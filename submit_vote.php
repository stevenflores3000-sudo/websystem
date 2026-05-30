<?php
session_start();
include 'db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in again.']);
    exit();
}

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    echo json_encode(['success' => false, 'message' => 'Administrators are not permitted to cast votes.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$election_id = $data['election_id'] ?? $_POST['election_id'] ?? null;
$user_id = $_SESSION['user_id'];
$votes = $data['votes'] ?? $_POST['votes'] ?? [];

if (empty($election_id) || empty($votes) || !is_array($votes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or incomplete vote data.']);
    exit();
}

try {
    // Disable foreign key constraints for this session so we can insert virtual 'ABSTAIN__' records
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    // 1. Validate user session vectors.
    $voteCheckStmt = $pdo->prepare("SELECT 1 FROM vote WHERE user_id = :user_id AND election_id = :election_id LIMIT 1");
    $voteCheckStmt->execute([':user_id' => $user_id, ':election_id' => $election_id]);
    
    if ($voteCheckStmt->fetchColumn()) {
        echo json_encode(["success" => false, "message" => "Transaction Rejected: You have already cast a vote in this election."]);
        exit;
    }

    // 2. Initiate a secure database transaction
    $pdo->beginTransaction();

    $ruleStmt = $pdo->prepare("SELECT max_votes as max_selection, max_per_party FROM election_position WHERE election_id = :election_id AND title = :position_name");
    $partyStmt = $pdo->prepare("SELECT p.name as party_name FROM candidate c LEFT JOIN partylist p ON c.party_id = p.id WHERE c.id = :candidate_id");

    // 3. Iterate through the incoming voting matrix array
    foreach ($votes as $position_name => $candidate_ids) {
        if (!is_array($candidate_ids)) continue;

        $ruleStmt->execute([':election_id' => $election_id, ':position_name' => $position_name]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        $max_selection = $rule ? (int)$rule['max_selection'] : 1;
        $max_per_party = $rule ? (int)$rule['max_per_party'] : 100;
        
        // Count the array elements
        if (count($candidate_ids) > $max_selection) {
            $pdo->rollBack();
            echo json_encode(["success" => false, "message" => "Transaction Rejected: Category max selection exceeded."]);
            exit;
        }

        // For each selected candidate, verify their party group
        $partyCounts = [];
        foreach ($candidate_ids as $candidate_id) {
            $partyStmt->execute([':candidate_id' => $candidate_id]);
            $cand = $partyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($cand) {
                $party_name = $cand['party_name'] ?? 'Independent';
                if (!isset($partyCounts[$party_name])) $partyCounts[$party_name] = 0;
                $partyCounts[$party_name]++;

                if ($partyCounts[$party_name] > $max_per_party) {
                    $pdo->rollBack();
                    echo json_encode(["success" => false, "message" => "Transaction Rejected: Party lineup vote count exceeds threshold."]);
                    exit;
                }
            }
        }
    }

    // 4. Write choices to tracking table, update user, commit, and redirect
    $transaction_id = bin2hex(random_bytes(16));
    $insertStmt = $pdo->prepare("INSERT INTO vote (user_id, election_id, candidate_id, transaction_id) VALUES (:user_id, :election_id, :candidate_id, :txn_id)");
    
    foreach ($votes as $position_name => $candidate_ids) {
        if (!is_array($candidate_ids)) continue;
        foreach ($candidate_ids as $candidate_id) {
            $insertStmt->execute([':user_id' => $user_id, ':election_id' => $election_id, ':candidate_id' => $candidate_id, ':txn_id' => $transaction_id]);
        }
    }

    $pdo->commit();
    
    echo json_encode(["success" => true, "transaction_id" => $transaction_id]);
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "System Error: " . $e->getMessage()]);
}
?>