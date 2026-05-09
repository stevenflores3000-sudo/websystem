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

// 3. Check if the user has already voted in this election
$stmt = $conn->prepare("SELECT COUNT(*) FROM votes WHERE user_id = ? AND election_id = ?");
$stmt->bind_param('ss', $user_id, $election_id);
$stmt->execute();
$stmt->bind_result($vote_count);
$stmt->fetch();
$stmt->close();

if ($vote_count > 0) {
    echo json_encode(['success' => false, 'error' => 'You have already voted in this election.']);
    exit();
}

// 4. Start a transaction and insert votes
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("INSERT INTO votes (user_id, election_id, candidate_id) VALUES (?, ?, ?)");

    foreach ($candidate_ids as $candidate_id) {
        $stmt->bind_param('sss', $user_id, $election_id, $candidate_id);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error); // Trigger rollback
        }
    }
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Vote submitted successfully.']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Vote submission failed for user_id {$user_id}: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'A database error occurred. Your vote was not saved.']);
}

$conn->close();
?>