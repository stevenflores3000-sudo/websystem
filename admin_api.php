<?php
session_start();
include 'db_connect.php';
header('Content-Type: application/json');

// Security check: ensure only admins can modify elections
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    // ── 1. Create a New Election ──────────────────────────────
    if ($action === 'add_election') {
        $id = 'ELEC-' . mt_rand(10000, 99999);
        $title = $data['name'];
        $date = $data['start_date'];
        $status = $data['status'];

        // Prevent duplicate election titles
        $checkStmt = $conn->prepare("SELECT id FROM election WHERE LOWER(title) = LOWER(?)");
        $checkStmt->bind_param('s', $title);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'An election with this name already exists.']);
            exit;
        }
        $checkStmt->close();

        $stmt = $conn->prepare("INSERT INTO election (id, title, date_of_election, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $id, $title, $date, $status);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'election_id' => $id]);
    }
    // ── 2. Change Election Status (Active/Upcoming/Closed) ────
    elseif ($action === 'change_status') {
        $elec_id = $data['election_id'];
        $status = $data['status'];
        
        $stmt = $conn->prepare("UPDATE election SET status = ? WHERE id = ?");
        $stmt->bind_param('ss', $status, $elec_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 3. Create a Party List ────────────────────────────────
    elseif ($action === 'add_party') {
        $id = 'PRTY-' . mt_rand(10000, 99999);
        $name = $data['name'];
        
        // Ensure table exists just in case
        $conn->query("CREATE TABLE IF NOT EXISTS partylist (id VARCHAR(50) PRIMARY KEY, name VARCHAR(100))");
        
        // Prevent duplicate party names
        $checkStmt = $conn->prepare("SELECT id FROM partylist WHERE LOWER(name) = LOWER(?)");
        $checkStmt->bind_param('s', $name);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'A party list with this name already exists.']);
            exit;
        }
        $checkStmt->close();

        $stmt = $conn->prepare("INSERT INTO partylist (id, name) VALUES (?, ?)");
        $stmt->bind_param('ss', $id, $name);
        $stmt->execute();
        
        echo json_encode(['success' => true, 'party_id' => $id]);
    }
    // ── 4. Add a Candidate ────────────────────────────────────
    elseif ($action === 'add_candidate') {
        $elec_id = $data['election_id'];
        $party_id = $data['party_id'];
        $pos = $data['position'];
        $name = $data['name'];

        // Prevent duplicate candidate for the same position and party in the same election
        $checkStmt = $conn->prepare("SELECT id FROM candidate WHERE election_id = ? AND party_id = ? AND position_title = ?");
        $checkStmt->bind_param('sss', $elec_id, $party_id, $pos);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'A candidate for this position already exists in this party.']);
            exit;
        }
        $checkStmt->close();

        // Add 'name' column to candidate table if it doesn't exist
        $checkCol = $conn->query("SHOW COLUMNS FROM candidate LIKE 'name'");
        if ($checkCol && $checkCol->num_rows == 0) {
            $conn->query("ALTER TABLE candidate ADD COLUMN name VARCHAR(100) DEFAULT ''");
            $conn->query("ALTER TABLE candidate MODIFY user_id VARCHAR(50) NULL");
        }

        // Insert the candidate directly into the candidate table
        $c_id = 'C-' . mt_rand(10000, 99999);
        $stmtC = $conn->prepare("INSERT INTO candidate (id, user_id, name, position_title, party_id, election_id) VALUES (?, NULL, ?, ?, ?, ?)");
        $stmtC->bind_param('sssss', $c_id, $name, $pos, $party_id, $elec_id);
        $stmtC->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 5. Delete a Candidate ─────────────────────────────────
    elseif ($action === 'delete_candidate') {
        $cand_id = $data['candidate_id'];
        
        // Delete votes (handle both table names gracefully to prevent FK errors)
        $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
        $conn->query("DELETE FROM votes WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
        
        // Delete candidate
        $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
        $stmtC->bind_param('s', $cand_id);
        $stmtC->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 6. Delete a Party ─────────────────────────────────────
    elseif ($action === 'delete_party') {
        $party_id = $data['party_id'];
        
        // Get all candidates for this party to delete their votes
        $stmt = $conn->prepare("SELECT id FROM candidate WHERE party_id = ?");
        $stmt->bind_param('s', $party_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $cand_id = $row['id'];
            
            $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
            $conn->query("DELETE FROM votes WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
            
            $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
            $stmtC->bind_param('s', $cand_id);
            $stmtC->execute();
        }
        
        // Delete party
        $stmtP = $conn->prepare("DELETE FROM partylist WHERE id = ?");
        $stmtP->bind_param('s', $party_id);
        $stmtP->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 6.5. Edit a Party ─────────────────────────────────────
    elseif ($action === 'edit_party') {
        $party_id = $data['party_id'];
        $new_name = $data['name'];
        
        $stmtP = $conn->prepare("UPDATE partylist SET name = ? WHERE id = ?");
        $stmtP->bind_param('ss', $new_name, $party_id);
        $stmtP->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 7. Edit a Candidate ───────────────────────────────────
    elseif ($action === 'edit_candidate') {
        $cand_id = $data['candidate_id'];
        $new_name = $data['name'] ?? null;
        
        if ($new_name) {
            $stmtC = $conn->prepare("UPDATE candidate SET name = ? WHERE id = ?");
            $stmtC->bind_param('ss', $new_name, $cand_id);
            $stmtC->execute();
        }
        
        if (!empty($data['position'])) {
            $stmtC = $conn->prepare("UPDATE candidate SET position_title = ? WHERE id = ?");
            $stmtC->bind_param('ss', $data['position'], $cand_id);
            $stmtC->execute();
        }
        
        echo json_encode(['success' => true]);
    }
    // ── 8. Delete an Election ─────────────────────────────────
    elseif ($action === 'delete_election') {
        $elec_id = $data['election_id'];
        
        // 1. Delete all votes cast in this election (handles both table naming conventions)
        $conn->query("DELETE FROM vote WHERE election_id = '" . $conn->real_escape_string($elec_id) . "'");
        $conn->query("DELETE FROM votes WHERE election_id = '" . $conn->real_escape_string($elec_id) . "'");
        
        // 2. Delete candidates linked to this election
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ?");
        $stmtDelC->bind_param('s', $elec_id);
        $stmtDelC->execute();
        
        // 3. Finally, delete the election itself
        $stmtDelE = $conn->prepare("DELETE FROM election WHERE id = ?");
        $stmtDelE->bind_param('s', $elec_id);
        $stmtDelE->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 9. Edit an Election ───────────────────────────────────
    elseif ($action === 'edit_election') {
        $elec_id = $data['election_id'];
        $title = $data['name'];
        $date = $data['date'];
        
        $stmt = $conn->prepare("UPDATE election SET title = ?, date_of_election = ? WHERE id = ?");
        $stmt->bind_param('sss', $title, $date, $elec_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 10. Change Admin Password ──────────────────────────────
    elseif ($action === 'change_password') {
        $admin_id = $_SESSION['user_id'];
        $old_pass = $data['old_password'];
        $new_pass = $data['new_password'];

        $stmt = $conn->prepare("SELECT password FROM admin WHERE id = ?");
        $stmt->bind_param('s', $admin_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($row = $res->fetch_assoc()) {
            if (password_verify($old_pass, $row['password'])) {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmtU = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
                $stmtU->bind_param('ss', $hash, $admin_id);
                $stmtU->execute();
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Incorrect current password.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Admin not found.']);
        }
    }
    // ── 11. Add Custom Position ────────────────────────────────
    elseif ($action === 'add_position') {
        $elec_id = $data['election_id'];
        $title = $data['title'];
        
        $conn->query("CREATE TABLE IF NOT EXISTS election_position (id INT AUTO_INCREMENT PRIMARY KEY, election_id VARCHAR(50), title VARCHAR(100))");
        
        // Prevent duplicate positions in the same election
        $check = $conn->prepare("SELECT id FROM election_position WHERE election_id = ? AND LOWER(title) = LOWER(?)");
        $check->bind_param('ss', $elec_id, $title);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'This position already exists.']);
            exit;
        }
        $check->close();
        
        $stmt = $conn->prepare("INSERT INTO election_position (election_id, title) VALUES (?, ?)");
        $stmt->bind_param('ss', $elec_id, $title);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 12. Delete Custom Position ─────────────────────────────
    elseif ($action === 'delete_position') {
        $elec_id = $data['election_id'];
        $title = $data['title'];
        
        // 1. Find all candidates under this position to delete their votes
        $stmtC = $conn->prepare("SELECT id FROM candidate WHERE election_id = ? AND position_title = ?");
        $stmtC->bind_param('ss', $elec_id, $title);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($row = $resC->fetch_assoc()) {
            $cand_id = $row['id'];
            $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
        }
        $stmtC->close();

        // 2. Delete candidates under this position
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ? AND position_title = ?");
        $stmtDelC->bind_param('ss', $elec_id, $title);
        $stmtDelC->execute();

        // 3. Delete the position itself
        $stmt = $conn->prepare("DELETE FROM election_position WHERE election_id = ? AND title = ?");
        $stmt->bind_param('ss', $elec_id, $title);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>