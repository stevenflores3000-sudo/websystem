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
if (isset($_POST['position_name']) && isset($_POST['max_selection']) && isset($_POST['max_total_candidates']) && isset($_POST['max_per_party'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO position_rules (position_name, max_selection, max_total_candidates, max_per_party) VALUES (:name, :max_sel, :max_total, :max_party) ON DUPLICATE KEY UPDATE max_selection = :max_sel, max_total_candidates = :max_total, max_per_party = :max_party");
        $stmt->execute([
            ':name'      => $_POST['position_name'],
            ':max_sel'   => $_POST['max_selection'],
            ':max_total' => $_POST['max_total_candidates'],
            ':max_party' => $_POST['max_per_party']
        ]);
        
        echo json_encode(["success" => true, "message" => "Policy registry bounds synchronized successfully."]);
        exit;
    } catch (Exception $e) {
        echo json_encode(["success" => false, "error" => "Policy Configurator Error: " . $e->getMessage()]);
        exit;
    }
}

function log_audit($conn, $action, $details) {
    // Use 'admin_id' (e.g. 'admin') instead of 'user_id' (e.g. '1') for better readability in the UI
    $admin_id = $_SESSION['admin_id'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO audit_log (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('ssss', $admin_id, $action, $details, $ip);
    $stmt->execute();
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

try {
    // ── 0. Auto-Patch Missing Columns ──────────────────────────
    // Ensures the database has the new date columns if they are missing
    $checkCol = $conn->query("SHOW COLUMNS FROM election LIKE 'start_date'");
    if ($checkCol && $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE election ADD COLUMN start_date DATE NULL AFTER date_of_election");
        $conn->query("ALTER TABLE election ADD COLUMN end_date DATE NULL AFTER start_date");
        $conn->query("UPDATE election SET start_date = date_of_election, end_date = date_of_election");
    }

    $checkPartyCol = $conn->query("SHOW COLUMNS FROM partylist LIKE 'election_id'");
    if ($checkPartyCol && $checkPartyCol->num_rows == 0) {
        $conn->query("ALTER TABLE partylist ADD COLUMN election_id VARCHAR(50) NULL");
    }

    // ── 1. Create a New Election ──────────────────────────────
    if ($action === 'add_election') {
        $id = 'ELEC-' . mt_rand(10000, 99999);
        $title = $data['name'];
        $date = $data['start_date'];
        $end_date = $data['end_date'];
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

        $stmt = $conn->prepare("INSERT INTO election (id, title, date_of_election, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssss', $id, $title, $date, $date, $end_date, $status);
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
        $elec_id = $data['election_id'];
        
        // Prevent duplicate party names within the SAME election
        $checkStmt = $conn->prepare("SELECT id FROM partylist WHERE LOWER(name) = LOWER(?) AND election_id = ?");
        $checkStmt->bind_param('ss', $name, $elec_id);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'A party list with this name already exists in this election.']);
            exit;
        }
        $checkStmt->close();

        $stmt = $conn->prepare("INSERT INTO partylist (id, name, election_id) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $id, $name, $elec_id);
        $stmt->execute();
        
        log_audit($conn, 'ADD_PARTY', "Created party list: $name (ID: $id) in election $elec_id");
        echo json_encode(['success' => true, 'party_id' => $id]);
    }
    // ── 4. Add a Candidate ────────────────────────────────────
    elseif ($action === 'add_candidate') {
        $elec_id = $data['election_id'];
        $party_id = $data['party_id'];
        $pos = $data['position'];
        $name = $data['name'];

        // 1. Extract and sanitize incoming position and party_name
        $target_pos = isset($_POST['position']) ? htmlspecialchars(trim($_POST['position'])) : ($data['position'] ?? '');
        $party_name = isset($_POST['party_name']) ? htmlspecialchars(trim($_POST['party_name'])) : ($data['party_name'] ?? '');

        // Enforce strict relational business logic validation via PDO
        
        // 2. Retrieve limits from election_position table for this specific election
        $ruleStmt = $pdo->prepare("SELECT max_candidates as max_total_candidates, max_per_party FROM election_position WHERE election_id = :elec_id AND title = :pos");
        $ruleStmt->execute([':elec_id' => $elec_id, ':pos' => $target_pos]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        $max_total_candidates = $rule ? (int)$rule['max_total_candidates'] : 100;
        $max_per_party = $rule ? (int)$rule['max_per_party'] : 100;
        
        // 3. Global Cap: Count all existing candidates for that position IN THIS ELECTION
        $globalCountStmt = $pdo->prepare("SELECT COUNT(*) FROM candidate WHERE election_id = :elec_id AND position_title = :pos");
        $globalCountStmt->execute([':elec_id' => $elec_id, ':pos' => $target_pos]);
        $global_count = (int)$globalCountStmt->fetchColumn();

        if ($global_count >= $max_total_candidates) {
            echo json_encode(["success" => false, "error" => "Registration Blocked: The global candidate limit for this position ($max_total_candidates) has been reached."]);
            exit;
        }

        // 4. Party Cap: Count how many candidates are currently registered for that exact position under the target party IN THIS ELECTION
        $partyCountStmt = $pdo->prepare("SELECT COUNT(*) FROM candidate c LEFT JOIN partylist p ON c.party_id = p.id WHERE c.election_id = :elec_id AND c.position_title = :pos AND (p.name = :party_name OR c.party_id = :party_name_fallback)");
        $partyCountStmt->execute([':elec_id' => $elec_id, ':pos' => $target_pos, ':party_name' => $party_name, ':party_name_fallback' => $party_id]);
        $party_count = (int)$partyCountStmt->fetchColumn();
        
        if ($party_count >= $max_per_party) {
            echo json_encode(["success" => false, "error" => "Registration Blocked: The target party lineup is already full ($max_per_party) for this specific position."]);
            exit;
        }

        // The system previously prevented adding multiple candidates to the same position within the same party.
        // This check has been removed to allow multiple candidates per position (e.g., multiple Senators in one partylist).

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
        
        // Delete votes linked to this candidate
        $stmtDelV = $conn->prepare("DELETE FROM vote WHERE candidate_id = ?");
        $stmtDelV->bind_param('s', $cand_id);
        $stmtDelV->execute();
        
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
        
        $stmtDelV = $conn->prepare("DELETE FROM vote WHERE candidate_id = ?");
        
        while ($row = $res->fetch_assoc()) {
            $cand_id = $row['id'];
            
            $stmtDelV->bind_param('s', $cand_id);
            $stmtDelV->execute();
            
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
        
        // Temporarily disable foreign key checks to prevent strict relational blocks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        // 1. Delete all votes cast in this election
        $stmtDelV = $conn->prepare("DELETE FROM vote WHERE election_id = ?");
        $stmtDelV->bind_param('s', $elec_id);
        $stmtDelV->execute();
        
        // 2. Delete candidates linked to this election
        $stmtDelC = $conn->prepare("DELETE FROM candidate WHERE election_id = ?");
        $stmtDelC->bind_param('s', $elec_id);
        $stmtDelC->execute();
        
        // 3. Delete positions linked to this election
        $stmtDelP = $conn->prepare("DELETE FROM election_position WHERE election_id = ?");
        $stmtDelP->bind_param('s', $elec_id);
        $stmtDelP->execute();
        
        // 4. Delete partylists linked specifically to this election
        $stmtDelParty = $conn->prepare("DELETE FROM partylist WHERE election_id = ?");
        $stmtDelParty->bind_param('s', $elec_id);
        $stmtDelParty->execute();

        // 5. Finally, delete the election itself
        $stmtDelE = $conn->prepare("DELETE FROM election WHERE id = ?");
        $stmtDelE->bind_param('s', $elec_id);
        $stmtDelE->execute();
        
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        
        log_audit($conn, 'DELETE_ELECTION', "Deleted election ID: $elec_id");
        echo json_encode(['success' => true]);
    }
    // ── 9. Edit an Election ───────────────────────────────────
    elseif ($action === 'edit_election') {
        $elec_id = $data['election_id'];
        $title = $data['name'];
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        
        $stmt = $conn->prepare("UPDATE election SET title = ?, date_of_election = ?, start_date = ?, end_date = ? WHERE id = ?");
        $stmt->bind_param('sssss', $title, $start_date, $start_date, $end_date, $elec_id);
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
        $max_candidates = isset($data['max_candidates']) ? (int)$data['max_candidates'] : 100;
        $max_per_party = isset($data['max_per_party']) ? (int)$data['max_per_party'] : 1;
        
        // Prevent duplicate positions in the same election
        $check = $conn->prepare("SELECT id FROM election_position WHERE election_id = ? AND LOWER(title) = LOWER(?)");
        $check->bind_param('ss', $elec_id, $title);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'This position already exists.']);
            exit;
        }
        $check->close();
        
        $stmt = $conn->prepare("INSERT INTO election_position (election_id, title, max_votes, max_candidates, max_per_party) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('ssiii', $elec_id, $title, $max_votes, $max_candidates, $max_per_party);
        $stmt->execute();
        
        log_audit($conn, 'ADD_POSITION', "Added position $title to election $elec_id (Max votes: $max_votes, Max cand: $max_candidates, Max per party: $max_per_party)");
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
        
        $stmtDelV = $conn->prepare("DELETE FROM vote WHERE candidate_id = ?");
        while ($row = $resC->fetch_assoc()) {
            $cand_id = $row['id'];
            $stmtDelV->bind_param('s', $cand_id);
            $stmtDelV->execute();
        }
        $stmtC->close();

        // Delete abstain votes for this position
        $abstain_id = 'ABSTAIN__' . $title;
        $stmtDelV->bind_param('s', $abstain_id);
        $stmtDelV->execute();

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
    // ── 12.5. Edit Custom Position ─────────────────────────────
    elseif ($action === 'edit_position') {
        $elec_id = $data['election_id'];
        $old_title = $data['old_title'];
        $new_title = $data['title'];
        $max_votes = isset($data['max_votes']) ? (int)$data['max_votes'] : 1;
        $max_candidates = isset($data['max_candidates']) ? (int)$data['max_candidates'] : 100;
        $max_per_party = isset($data['max_per_party']) ? (int)$data['max_per_party'] : 1;
        
        // If title changed, verify duplicate and cascade renames
        if (strtolower($old_title) !== strtolower($new_title)) {
            $check = $conn->prepare("SELECT id FROM election_position WHERE election_id = ? AND LOWER(title) = LOWER(?)");
            $check->bind_param('ss', $elec_id, $new_title);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                echo json_encode(['success' => false, 'error' => 'This position name already exists.']);
                exit;
            }
            $check->close();
            
            $stmtC = $conn->prepare("UPDATE candidate SET position_title = ? WHERE election_id = ? AND position_title = ?");
            $stmtC->bind_param('sss', $new_title, $elec_id, $old_title);
            $stmtC->execute();
            
            $old_abstain = 'ABSTAIN__' . $old_title;
            $new_abstain = 'ABSTAIN__' . $new_title;
            $stmtV = $conn->prepare("UPDATE vote SET candidate_id = ? WHERE election_id = ? AND candidate_id = ?");
            $stmtV->bind_param('sss', $new_abstain, $elec_id, $old_abstain);
            $stmtV->execute();
        }

        $stmt = $conn->prepare("UPDATE election_position SET title = ?, max_votes = ?, max_candidates = ?, max_per_party = ? WHERE election_id = ? AND title = ?");
        $stmt->bind_param('siiiss', $new_title, $max_votes, $max_candidates, $max_per_party, $elec_id, $old_title);
        $stmt->execute();
        
        log_audit($conn, 'EDIT_POSITION', "Edited position in election $elec_id from $old_title to $new_title (Max votes: $max_votes, Max cand: $max_candidates, Max per party: $max_per_party)");
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>