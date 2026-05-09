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

        $stmt = $conn->prepare("INSERT INTO election (id, title, date_of_election, status) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $id, $title, $date, $status);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
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
        
        $stmt = $conn->prepare("INSERT INTO partylist (id, name) VALUES (?, ?)");
        $stmt->bind_param('ss', $id, $name);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 4. Add a Candidate ────────────────────────────────────
    elseif ($action === 'add_candidate') {
        $elec_id = $data['election_id'];
        $party_id = $data['party_id'];
        $pos = $data['position'];
        $name = $data['name'];

        // Because the candidate table links to the user table, we create a dummy user profile for the candidate
        $u_id = 'CAND-' . mt_rand(100000, 999999);
        $dummy_pass = password_hash('candidate', PASSWORD_DEFAULT);
        $stmtU = $conn->prepare("INSERT INTO user (id, student_id, name, password) VALUES (?, ?, ?, ?)");
        $stmtU->bind_param('ssss', $u_id, $u_id, $name, $dummy_pass);
        $stmtU->execute();
        
        // Insert the candidate linked to the dummy user
        $c_id = 'C-' . mt_rand(10000, 99999);
        $stmtC = $conn->prepare("INSERT INTO candidate (id, user_id, position_title, party_id, election_id) VALUES (?, ?, ?, ?, ?)");
        $stmtC->bind_param('sssss', $c_id, $u_id, $pos, $party_id, $elec_id);
        $stmtC->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 5. Delete a Candidate ─────────────────────────────────
    elseif ($action === 'delete_candidate') {
        $cand_id = $data['candidate_id'];
        
        // Get user_id to delete the dummy user as well
        $stmt = $conn->prepare("SELECT user_id FROM candidate WHERE id = ?");
        $stmt->bind_param('s', $cand_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $u_id = $row['user_id'];
            
            // Delete votes (handle both table names gracefully to prevent FK errors)
            $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'") || null;
            $conn->query("DELETE FROM votes WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'") || null;
            
            // Delete candidate
            $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
            $stmtC->bind_param('s', $cand_id);
            $stmtC->execute();
            
            // Delete dummy user
            $stmtU = $conn->prepare("DELETE FROM user WHERE id = ?");
            $stmtU->bind_param('s', $u_id);
            $stmtU->execute();
        }
        echo json_encode(['success' => true]);
    }
    // ── 6. Delete a Party ─────────────────────────────────────
    elseif ($action === 'delete_party') {
        $party_id = $data['party_id'];
        
        // Get all candidates for this party to delete their dummy users and votes
        $stmt = $conn->prepare("SELECT id, user_id FROM candidate WHERE party_id = ?");
        $stmt->bind_param('s', $party_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while ($row = $res->fetch_assoc()) {
            $cand_id = $row['id'];
            $u_id = $row['user_id'];
            
            $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'") || null;
            $conn->query("DELETE FROM votes WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'") || null;
            
            $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
            $stmtC->bind_param('s', $cand_id);
            $stmtC->execute();
            
            $stmtU = $conn->prepare("DELETE FROM user WHERE id = ?");
            $stmtU->bind_param('s', $u_id);
            $stmtU->execute();
        }
        
        // Delete party
        $stmtP = $conn->prepare("DELETE FROM partylist WHERE id = ?");
        $stmtP->bind_param('s', $party_id);
        $stmtP->execute();
        
        echo json_encode(['success' => true]);
    }
    // ── 7. Edit a Candidate ───────────────────────────────────
    elseif ($action === 'edit_candidate') {
        $cand_id = $data['candidate_id'];
        $new_name = $data['name'] ?? null;
        
        if ($new_name) {
            // Retrieve the dummy user_id to update the name
            $stmt = $conn->prepare("SELECT user_id FROM candidate WHERE id = ?");
            $stmt->bind_param('s', $cand_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($row = $res->fetch_assoc()) {
                $u_id = $row['user_id'];
                $stmtU = $conn->prepare("UPDATE user SET name = ? WHERE id = ?");
                $stmtU->bind_param('ss', $new_name, $u_id);
                $stmtU->execute();
            }
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
        
        // 2. Fetch candidates to remove their dummy user profiles
        $stmtC = $conn->prepare("SELECT user_id FROM candidate WHERE election_id = ?");
        $stmtC->bind_param('s', $elec_id);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        while ($row = $resC->fetch_assoc()) {
            $conn->query("DELETE FROM user WHERE id = '" . $conn->real_escape_string($row['user_id']) . "'");
        }
        
        // 3. Delete candidates linked to this election
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ?");
        $stmtDelC->bind_param('s', $elec_id);
        $stmtDelC->execute();
        
        // 4. Finally, delete the election itself
        $stmtDelE = $conn->prepare("DELETE FROM election WHERE id = ?");
        $stmtDelE->bind_param('s', $elec_id);
        $stmtDelE->execute();
        
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>