<?php
ini_set('display_errors', 0);
error_reporting(0);
session_start();
include 'db_connect.php';
header('Content-Type: application/json');

// Security check: ensure only admins can modify elections
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

// ── DYNAMIC ADMINISTRATIVE CONFIGURATOR POLICY ───────────
if (isset($_POST['position_name']) && isset($_POST['max_selection']) && isset($_POST['max_per_party'])) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=voting_system;charset=utf8mb4", "root", "");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $pdo->exec("CREATE TABLE IF NOT EXISTS position_rules (
            position_name VARCHAR(100) PRIMARY KEY,
            max_selection INT DEFAULT 1,
            max_per_party INT DEFAULT 1
        )");

        $stmt = $pdo->prepare("INSERT INTO position_rules (position_name, max_selection, max_per_party) VALUES (:name, :max_sel, :max_party) ON DUPLICATE KEY UPDATE max_selection = :max_sel, max_per_party = :max_party");
        $stmt->execute([
            ':name'      => $_POST['position_name'],
            ':max_sel'   => $_POST['max_selection'],
            ':max_party' => $_POST['max_per_party']
        ]);
        
        echo json_encode(["success" => true, "message" => "Policy registry bounds synchronized successfully."]);
        exit;
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Policy Configurator Error: " . $e->getMessage()]);
        exit;
    }
}

// Create audit_log table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id VARCHAR(50),
    action VARCHAR(50),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function log_audit($conn, $action, $details) {
    $admin_id = $_SESSION['user_id'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO audit_log (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $admin_id, $action, $details, $ip);
    $stmt->execute();
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
        
        log_audit($conn, 'ADD_ELECTION', "Created election: $title (ID: $id)");
        echo json_encode(['success' => true, 'election_id' => $id]);
    }
    // ── 2. Change Election Status (Active/Upcoming/Closed) ────
    elseif ($action === 'change_status') {
        $elec_id = $data['election_id'];
        $status = $data['status'];
        
        $stmt = $conn->prepare("UPDATE election SET status = ? WHERE id = ?");
        $stmt->bind_param('ss', $status, $elec_id);
        $stmt->execute();
        
        log_audit($conn, 'CHANGE_ELECTION_STATUS', "Changed election $elec_id status to $status");
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
        
        log_audit($conn, 'ADD_PARTY', "Created party list: $name (ID: $id)");
        echo json_encode(['success' => true, 'party_id' => $id]);
    }
    // ── 4. Add a Candidate ────────────────────────────────────
    elseif ($action === 'add_candidate') {
        $elec_id = $data['election_id'];
        $party_id = $data['party_id'];
        $pos = $data['position'];
        $name = $data['name'];

        // The system previously prevented adding multiple candidates to the same position within the same party.
        // This check has been removed to allow multiple candidates per position (e.g., multiple Senators in one partylist).

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
        
        log_audit($conn, 'ADD_CANDIDATE', "Added candidate: $name for $pos in party $party_id (Election: $elec_id)");
        echo json_encode(['success' => true]);
    }
    // ── 5. Delete a Candidate ─────────────────────────────────
    elseif ($action === 'delete_candidate') {
        $cand_id = $data['candidate_id'];
        
        // Delete votes (handle both table names gracefully to prevent FK errors)
        $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($cand_id) . "'");
        
        // Delete candidate
        $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
        $stmtC->bind_param('s', $cand_id);
        $stmtC->execute();
        
        log_audit($conn, 'DELETE_CANDIDATE', "Deleted candidate ID: $cand_id");
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
            
            $stmtC = $conn->prepare("DELETE FROM candidate WHERE id = ?");
            $stmtC->bind_param('s', $cand_id);
            $stmtC->execute();
        }
        
        // Delete party
        $stmtP = $conn->prepare("DELETE FROM partylist WHERE id = ?");
        $stmtP->bind_param('s', $party_id);
        $stmtP->execute();
        
        log_audit($conn, 'DELETE_PARTY', "Deleted party list ID: $party_id");
        echo json_encode(['success' => true]);
    }
    // ── 6.5. Edit a Party ─────────────────────────────────────
    elseif ($action === 'edit_party') {
        $party_id = $data['party_id'];
        $new_name = $data['name'];
        
        $stmtP = $conn->prepare("UPDATE partylist SET name = ? WHERE id = ?");
        $stmtP->bind_param('ss', $new_name, $party_id);
        $stmtP->execute();
        
        log_audit($conn, 'EDIT_PARTY', "Renamed party $party_id to $new_name");
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
        
        log_audit($conn, 'EDIT_CANDIDATE', "Edited candidate ID: $cand_id");
        echo json_encode(['success' => true]);
    }
    // ── 8. Delete an Election ─────────────────────────────────
    elseif ($action === 'delete_election') {
        $elec_id = $data['election_id'];
        
        // 1. Delete all votes cast in this election (handles both table naming conventions)
        $conn->query("DELETE FROM vote WHERE election_id = '" . $conn->real_escape_string($elec_id) . "'");
        
        // 2. Delete candidates linked to this election
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ?");
        $stmtDelC->bind_param('s', $elec_id);
        $stmtDelC->execute();
        
        // 3. Finally, delete the election itself
        $stmtDelE = $conn->prepare("DELETE FROM election WHERE id = ?");
        $stmtDelE->bind_param('s', $elec_id);
        $stmtDelE->execute();
        
        log_audit($conn, 'DELETE_ELECTION', "Deleted election ID: $elec_id");
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
        
        log_audit($conn, 'EDIT_ELECTION', "Edited election ID: $elec_id to $title");
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
                
                log_audit($conn, 'CHANGE_PASSWORD', "Admin changed their password.");
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
        $max_votes = isset($data['max_votes']) ? (int)$data['max_votes'] : 1;
        
        $conn->query("CREATE TABLE IF NOT EXISTS election_position (id INT AUTO_INCREMENT PRIMARY KEY, election_id VARCHAR(50), title VARCHAR(100), max_votes INT DEFAULT 1)");
        $checkMax = $conn->query("SHOW COLUMNS FROM election_position LIKE 'max_votes'");
        if ($checkMax && $checkMax->num_rows == 0) {
            $conn->query("ALTER TABLE election_position ADD COLUMN max_votes INT DEFAULT 1");
        }
        
        // Prevent duplicate positions in the same election
        $check = $conn->prepare("SELECT id FROM election_position WHERE election_id = ? AND LOWER(title) = LOWER(?)");
        $check->bind_param('ss', $elec_id, $title);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'This position already exists.']);
            exit;
        }
        $check->close();
        
        $stmt = $conn->prepare("INSERT INTO election_position (election_id, title, max_votes) VALUES (?, ?, ?)");
        $stmt->bind_param('ssi', $elec_id, $title, $max_votes);
        $stmt->execute();
        
        log_audit($conn, 'ADD_POSITION', "Added position $title to election $elec_id (Max votes: $max_votes)");
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

        // Delete abstain votes for this position
        $abstain_id = 'ABSTAIN__' . $title;
        $conn->query("DELETE FROM vote WHERE candidate_id = '" . $conn->real_escape_string($abstain_id) . "'");

        // 2. Delete candidates under this position
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ? AND position_title = ?");
        $stmtDelC->bind_param('ss', $elec_id, $title);
        $stmtDelC->execute();

        // 3. Delete the position itself
        $stmt = $conn->prepare("DELETE FROM election_position WHERE election_id = ? AND title = ?");
        $stmt->bind_param('ss', $elec_id, $title);
        $stmt->execute();
        
        log_audit($conn, 'DELETE_POSITION', "Deleted position $title from election $elec_id");
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>