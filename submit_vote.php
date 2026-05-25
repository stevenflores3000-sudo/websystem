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

$election_id   = $_POST['election_id'] ?? null;
$user_id       = $_SESSION['user_id'];

if (empty($election_id) || empty($_POST['votes']) || !is_array($_POST['votes'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or incomplete vote data.']);
    exit();
}

try {
    // Establish PDO connection explicitly for this strict validation requirement
    $pdo = new PDO("mysql:host=localhost;dbname=voting_system;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Initialize explicit PDO transaction
    $pdo->beginTransaction();

    $votes = $_POST['votes'];

    // Prepare rule validation queries
    $ruleStmt = $pdo->prepare("SELECT max_selection, max_per_party FROM position_rules WHERE position_name = :position_name");
    $partyStmt = $pdo->prepare("SELECT party_name FROM candidates WHERE id = :candidate_id");

    // Loop through each position in the payload
    foreach ($votes as $position_name => $candidate_ids) {
        if (!is_array($candidate_ids)) continue;

        // 1. Pull position rules
        $ruleStmt->execute([':position_name' => $position_name]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Security Violation: Invalid position configuration."]);
            exit;
        }

        $max_selection = (int) $rule['max_selection'];
        $max_per_party = (int) $rule['max_per_party'];

        // 2. Check total selection count
        if (count($candidate_ids) > $max_selection) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Over-voting detected."]);
            exit;
        }

        // 3 & 4. Check partylist seat allocation limits
        $partyCounts = [];
        foreach ($candidate_ids as $candidate_id) {
            $partyStmt->execute([':candidate_id' => $candidate_id]);
            $cand = $partyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($cand) {
                $party_name = $cand['party_name'];
                if (!isset($partyCounts[$party_name])) {
                    $partyCounts[$party_name] = 0;
                }
                $partyCounts[$party_name]++;

                if ($partyCounts[$party_name] > $max_per_party) {
                    $pdo->rollBack();
                    echo json_encode(["success" => false, "message" => "Partylist seat allocation exceeded."]);
                    exit;
                }
            }
        }
    }

    // 5. Final loop inserting rows into the vote ledger
    $insertStmt = $pdo->prepare("INSERT INTO vote (user_id, election_id, candidate_id) VALUES (:user_id, :election_id, :candidate_id)");
    
    foreach ($votes as $position_name => $candidate_ids) {
        if (!is_array($candidate_ids)) continue;
        foreach ($candidate_ids as $candidate_id) {
            $insertStmt->execute([
                ':user_id'      => $user_id,
                ':election_id'  => $election_id,
                ':candidate_id' => $candidate_id
            ]);
        }
    }

    // Commit transaction safely
    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Vote successfully validated and recorded."]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Vote Security Engine Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "An error occurred while processing your ballot."]);
}
?>