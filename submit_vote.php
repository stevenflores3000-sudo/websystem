<?php
/**
 * submit_vote.php
 *
 * Receives a JSON payload with an election_id and a list of candidate_ids.
 * 1. Verifies the user is logged in.
 * 2. Checks if the user has already voted in this election to prevent double-voting.
 * 3. Inserts the votes into the `votes` table within a transaction for data integrity.
 * 4. Returns a JSON success or error message.
 */

session_start();
include 'db_connect.php';

// Set header to return JSON
header('Content-Type: application/json');

// 1. Check for valid session and request method
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required. Please log in again.']);
    exit();
}

// Prevent administrators from casting votes
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    echo json_encode(['success' => false, 'error' => 'Administrators are not permitted to cast votes.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit();
}

// 2. Get and decode the POST body
$data = json_decode(file_get_contents('php://input'), true);

$election_id   = $data['election_id'] ?? null;
$candidate_ids = $data['candidate_ids'] ?? [];
$user_id       = $_SESSION['user_id'];

if (empty($election_id) || empty($candidate_ids) || !is_array($candidate_ids)) {
    echo json_encode(['success' => false, 'error' => 'Invalid or incomplete vote data.']);
    exit();
}

try {
    // 3. Check if the user has already voted in this election
    $stmt = $conn->prepare("SELECT COUNT(*) FROM vote WHERE user_id = ? AND election_id = ?");
    if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
    
    $stmt->bind_param('ss', $user_id, $election_id);
    $stmt->execute();
    $stmt->bind_result($vote_count);
    $stmt->fetch();
    $stmt->close();

    if ($vote_count > 0) {
        echo json_encode(['success' => false, 'error' => 'You have already voted in this election.']);
        exit();
    }

    // 3.5. Ensure the database allows multi-candidate ballots. 
    // The 'unique_voter' key prevents inserting multiple candidates per election.
    $checkIdx = $conn->query("SHOW INDEX FROM vote WHERE Key_name = 'unique_voter'");
    if ($checkIdx && $checkIdx->num_rows > 0) {
        try {
            $conn->query("ALTER TABLE vote DROP INDEX unique_voter");
        } catch (Exception $idxEx) {
            throw new Exception("Could not automatically remove the restrictive unique_voter rule. Please open phpMyAdmin, select your database, go to the SQL tab, and run: ALTER TABLE vote DROP INDEX unique_voter;");
        }
    }

    // 3.6. Ensure the database allows virtual 'Abstain' candidates which don't exist in the candidate table.
    $checkFk = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vote' AND COLUMN_NAME = 'candidate_id' AND REFERENCED_TABLE_NAME = 'candidate'");
    if ($checkFk && $checkFk->num_rows > 0) {
        while ($row = $checkFk->fetch_assoc()) {
            $fkName = $row['CONSTRAINT_NAME'];
            try {
                $conn->query("ALTER TABLE vote DROP FOREIGN KEY `$fkName`");
            } catch (Exception $fkEx) {
                // Ignore failure
            }
        }
    }

    // 4. Start a transaction and insert votes
    // (Moved inside the try block so any fatal exceptions are safely caught and returned as JSON)
    $conn->begin_transaction();

    // Dynamically check if the 'vote' table requires a manual VARCHAR ID (missing AUTO_INCREMENT)
    $checkId = $conn->query("SHOW COLUMNS FROM vote LIKE 'id'");
    $requires_manual_id = false;
    if ($checkId && $checkId->num_rows > 0) {
        $col = $checkId->fetch_assoc();
        if (stripos($col['Extra'], 'auto_increment') === false && stripos($col['Type'], 'int') === false) {
            $requires_manual_id = true;
        }
    }

    if ($requires_manual_id) {
        $stmt = $conn->prepare("INSERT INTO vote (id, user_id, election_id, candidate_id) VALUES (?, ?, ?, ?)");
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
        foreach ($candidate_ids as $candidate_id) {
            $vote_id = 'V-' . mt_rand(100000, 999999);
            $stmt->bind_param('ssss', $vote_id, $user_id, $election_id, $candidate_id);
            if (!$stmt->execute()) { throw new Exception("Insert failed: " . $stmt->error); }
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO vote (user_id, election_id, candidate_id) VALUES (?, ?, ?)");
        if (!$stmt) { throw new Exception("Prepare failed: " . $conn->error); }
        foreach ($candidate_ids as $candidate_id) {
            $stmt->bind_param('sss', $user_id, $election_id, $candidate_id);
            if (!$stmt->execute()) { throw new Exception("Insert failed: " . $stmt->error); }
        }
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Vote submitted successfully.']);

} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    error_log("Vote submission failed for user_id {$user_id}: " . $e->getMessage());
    // Surface the EXACT database error to the UI for clear debugging
    echo json_encode(['success' => false, 'error' => 'DB Error: ' . $e->getMessage()]);
}

$conn->close();
?>