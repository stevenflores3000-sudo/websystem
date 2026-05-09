<?php
// ═══════════════════════════════════════════════════════════════════════
//  get_stats.php
//  JSON API — returns three payloads for the Admin Dashboard:
//
//  1. summary        → total registered users, total unique votes cast,
//                       voter turnout %, active/upcoming/closed election counts
//  2. voter_tracker  → list of all users with has_voted boolean per election
//  3. elections      → each election with live candidate vote tallies grouped
//                       by position_title, plus eligible_voter count
//
//  GET params (all optional):
//    ?section=summary|voter_tracker|tally|all   (default: all)
//    ?election_id=ELEC-xxxxx                    (scopes voter_tracker + tally)
// ═══════════════════════════════════════════════════════════════════════
session_start();
include 'db_connect.php';

header('Content-Type: application/json');

// ── Auth guard (only logged-in admins should call this) ──────────────
// Comment out the block below if you want to test without session auth
/*
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit();
}
*/

$section     = $_GET['section']     ?? 'all';
$election_id = $_GET['election_id'] ?? null;

$out = ['success' => true];

// ══════════════════════════════════════════════════════════════════════
//  1. SUMMARY
// ══════════════════════════════════════════════════════════════════════
if ($section === 'all' || $section === 'summary') {
    // Total registered voters
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM user");
    $total_users = (int)$r->fetch_assoc()['cnt'];

    // Total unique votes (one row per user-election-candidate tuple)
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM vote");
    $total_votes = (int)$r->fetch_assoc()['cnt'];

    // Unique voters (users who cast ≥1 vote)
    $r = $conn->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM vote");
    $unique_voters = (int)$r->fetch_assoc()['cnt'];

    // Election status breakdown
    $r = $conn->query("SELECT status, COUNT(*) AS cnt FROM election GROUP BY status");
    $status_counts = ['active' => 0, 'upcoming' => 0, 'closed' => 0];
    while ($row = $r->fetch_assoc()) {
        $s = strtolower($row['status']);
        if (isset($status_counts[$s])) $status_counts[$s] = (int)$row['cnt'];
    }

    $turnout_pct = $total_users > 0
        ? round(($unique_voters / $total_users) * 100, 1)
        : 0;

    $out['summary'] = [
        'total_registered_users' => $total_users,
        'total_votes_cast'       => $total_votes,
        'unique_voters'          => $unique_voters,
        'turnout_pct'            => $turnout_pct,
        'active_elections'       => $status_counts['active'],
        'upcoming_elections'     => $status_counts['upcoming'],
        'closed_elections'       => $status_counts['closed'],
    ];
}

// ══════════════════════════════════════════════════════════════════════
//  2. VOTER TRACKER
//  Returns every registered user with a has_voted flag.
//  If election_id is provided, has_voted is scoped to that election.
// ══════════════════════════════════════════════════════════════════════
if ($section === 'all' || $section === 'voter_tracker') {
    if ($election_id) {
        $stmt = $conn->prepare(
            "SELECT u.id, u.student_id, u.name, u.department, u.year_level,
                    CASE WHEN v.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_voted
             FROM user u
             LEFT JOIN (
                 SELECT DISTINCT user_id FROM vote WHERE election_id = ?
             ) v ON v.user_id = u.id
             ORDER BY u.name"
        );
        $stmt->bind_param('s', $election_id);
    } else {
        // has_voted = true if user voted in ANY election
        $stmt = $conn->prepare(
            "SELECT u.id, u.student_id, u.name, u.department, u.year_level,
                    CASE WHEN v.user_id IS NOT NULL THEN 1 ELSE 0 END AS has_voted
             FROM user u
             LEFT JOIN (
                 SELECT DISTINCT user_id FROM vote
             ) v ON v.user_id = u.id
             ORDER BY u.name"
        );
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $tracker = [];
    while ($row = $res->fetch_assoc()) {
        $tracker[] = [
            'id'         => $row['id'],
            'student_id' => $row['student_id'],
            'name'       => $row['name'],
            'department' => $row['department'] ?? '',
            'year_level' => $row['year_level'] ?? '',
            'has_voted'  => (bool)$row['has_voted'],
        ];
    }
    $stmt->close();
    $out['voter_tracker'] = $tracker;
}

// ══════════════════════════════════════════════════════════════════════
//  3. ELECTIONS + LIVE TALLY
//  Returns each election with its candidates grouped by position_title,
//  each candidate including their live vote count.
// ══════════════════════════════════════════════════════════════════════
if ($section === 'all' || $section === 'tally') {

    // Base election query
    if ($election_id) {
        $stmt = $conn->prepare("SELECT * FROM election WHERE id = ?");
        $stmt->bind_param('s', $election_id);
        $stmt->execute();
        $elections_res = $stmt->get_result();
    } else {
        $elections_res = $conn->query("SELECT * FROM election ORDER BY date_of_election DESC");
    }

    $elections_out = [];

    while ($elec = $elections_res->fetch_assoc()) {
        $eid = $elec['id'];

        // Eligible voters for this election = total registered users
        // (adjust this query if you have a per-election eligible_voters column)
        $r = $conn->query("SELECT COUNT(*) AS cnt FROM user");
        $eligible = (int)$r->fetch_assoc()['cnt'];

        // Unique votes cast in this election
        $stmt2 = $conn->prepare(
            "SELECT COUNT(DISTINCT user_id) AS cnt FROM vote WHERE election_id = ?"
        );
        $stmt2->bind_param('s', $eid);
        $stmt2->execute();
        $votes_cast = (int)$stmt2->get_result()->fetch_assoc()['cnt'];
        $stmt2->close();

        // Candidates + vote counts grouped by position
        $stmt3 = $conn->prepare(
            "SELECT c.id AS candidate_id,
                    u.name AS candidate_name,
                    c.position_title,
                    COALESCE(p.name, 'Independent') AS party_name,
                    COUNT(v.id) AS vote_count
             FROM candidate c
             JOIN user u ON u.id = c.user_id
             LEFT JOIN partylist p ON p.id = c.party_id
             LEFT JOIN vote v ON v.candidate_id = c.id AND v.election_id = ?
             WHERE c.election_id = ?
             GROUP BY c.id, u.name, c.position_title, p.name
             ORDER BY c.position_title, vote_count DESC"
        );
        $stmt3->bind_param('ss', $eid, $eid);
        $stmt3->execute();
        $cand_res = $stmt3->get_result();
        $stmt3->close();

        // Group candidates into positions map
        $positions = [];
        while ($cand = $cand_res->fetch_assoc()) {
            $pos = $cand['position_title'];
            if (!isset($positions[$pos])) $positions[$pos] = [];
            $positions[$pos][] = [
                'candidate_id' => $cand['candidate_id'],
                'name'         => $cand['candidate_name'],
                'party'        => $cand['party_name'],
                'votes'        => (int)$cand['vote_count'],
            ];
        }

        $turnout = $eligible > 0 ? round(($votes_cast / $eligible) * 100, 1) : 0;

        $elections_out[] = [
            'id'              => $eid,
            'name'            => $elec['title'],
            'status'          => $elec['status'] ?? 'active',
            'date'            => $elec['date_of_election'] ?? null,
            'eligible_voters' => $eligible,
            'votes_cast'      => $votes_cast,
            'turnout_pct'     => $turnout,
            'positions'       => $positions,
        ];
    }

    $out['elections'] = $elections_out;
}

$conn->close();
echo json_encode($out);
?>