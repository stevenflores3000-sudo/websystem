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

try {

// ══════════════════════════════════════════════════════════════════════
//  1. SUMMARY
// ══════════════════════════════════════════════════════════════════════
if ($section === 'all' || $section === 'summary') {
    // Total registered voters
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM user WHERE id NOT LIKE 'CAND-%'");
    $total_users = ($r && $row = $r->fetch_assoc()) ? (int)$row['cnt'] : 0;

    // Total unique votes (one row per user-election-candidate tuple)
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM vote");
    $total_votes = ($r && $row = $r->fetch_assoc()) ? (int)$row['cnt'] : 0;

    // Unique voters (users who cast ≥1 vote)
    $r = $conn->query("SELECT COUNT(DISTINCT user_id) AS cnt FROM vote WHERE user_id NOT LIKE 'CAND-%'");
    $unique_voters = ($r && $row = $r->fetch_assoc()) ? (int)$row['cnt'] : 0;

    // Election status breakdown
    $r = $conn->query("SELECT status, COUNT(*) AS cnt FROM election GROUP BY status");
    $status_counts = ['active' => 0, 'upcoming' => 0, 'closed' => 0];
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $s = strtolower($row['status']);
            if (isset($status_counts[$s])) $status_counts[$s] = (int)$row['cnt'];
        }
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
             WHERE u.id NOT LIKE 'CAND-%'
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
             WHERE u.id NOT LIKE 'CAND-%'
             ORDER BY u.name"
        );
    }
    $tracker = [];
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
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
        }
        $stmt->close();
    }
    $out['voter_tracker'] = $tracker;
}

// ══════════════════════════════════════════════════════════════════════
//  3. ELECTIONS + LIVE TALLY
//  Returns each election with its candidates grouped by position_title,
//  each candidate including their live vote count.
// ══════════════════════════════════════════════════════════════════════
if ($section === 'all' || $section === 'tally') {

    // Fetch all global party lists to populate the real IDs on the frontend
    $parties = [];
    $p_res = $conn->query("SELECT id, name FROM partylist");
    if ($p_res) {
        while ($row = $p_res->fetch_assoc()) {
            $parties[] = $row;
        }
    }
    $out['parties'] = $parties;

    // Base election query
    if ($election_id) {
        $stmt = $conn->prepare("SELECT * FROM election WHERE id = ?");
        $stmt->bind_param('s', $election_id);
        $stmt->execute();
        $elections_res = $stmt->get_result();
    } else {
        $elections_res = $conn->query("SELECT * FROM election ORDER BY date_of_election DESC");
    }

    // Fetch eligible voters once to avoid N+1 query overhead inside the loop
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM user WHERE id NOT LIKE 'CAND-%'");
    $global_eligible = ($r && $row = $r->fetch_assoc()) ? (int)$row['cnt'] : 0;

    // Safely apply candidate table schema patch if missing (prevents fatal query errors)
    $checkCandName = $conn->query("SHOW COLUMNS FROM candidate LIKE 'name'");
    if ($checkCandName && $checkCandName->num_rows == 0) {
        $conn->query("ALTER TABLE candidate ADD COLUMN name VARCHAR(100) DEFAULT ''");
        $conn->query("ALTER TABLE candidate MODIFY user_id VARCHAR(50) NULL");
        $conn->query("UPDATE candidate c JOIN user u ON c.user_id = u.id SET c.name = u.name WHERE c.user_id LIKE 'CAND-%'");
        $conn->query("UPDATE candidate SET user_id = NULL WHERE user_id LIKE 'CAND-%'");
    }

    // Ensure position table exists before we try to select from it
    $conn->query("CREATE TABLE IF NOT EXISTS election_position (id INT AUTO_INCREMENT PRIMARY KEY, election_id VARCHAR(50), title VARCHAR(100), max_votes INT DEFAULT 1)");
    $checkMax = $conn->query("SHOW COLUMNS FROM election_position LIKE 'max_votes'");
    if ($checkMax && $checkMax->num_rows == 0) {
        $conn->query("ALTER TABLE election_position ADD COLUMN max_votes INT DEFAULT 1");
    }

    $elections_out = [];

    while ($elec = $elections_res->fetch_assoc()) {
        $eid = $elec['id'];

        $eligible = $global_eligible;

        // Unique votes cast in this election
        $stmt2 = $conn->prepare(
            "SELECT COUNT(DISTINCT user_id) AS cnt FROM vote WHERE election_id = ?"
        );
        $votes_cast = 0;
        if ($stmt2) {
            $stmt2->bind_param('s', $eid);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $votes_cast = ($res2 && $row2 = $res2->fetch_assoc()) ? (int)$row2['cnt'] : 0;
            $stmt2->close();
        }

        // Check if current user voted
        $user_voted = false;
        if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'voter') {
            $stmtV = $conn->prepare("SELECT 1 FROM vote WHERE election_id = ? AND user_id = ? LIMIT 1");
            $stmtV->bind_param('ss', $eid, $_SESSION['user_id']);
            $stmtV->execute();
            if ($stmtV->get_result()->num_rows > 0) {
                $user_voted = true;
            }
            $stmtV->close();
        }

        // Candidates + vote counts grouped by position
        $stmt3 = $conn->prepare(
            "SELECT c.id AS candidate_id,
                    COALESCE(NULLIF(c.name, ''), u.name) AS candidate_name,
                    c.position_title,
                    p.id AS party_id,
                    COALESCE(p.name, 'Independent') AS party_name,
                    COUNT(v.candidate_id) AS vote_count,
                    ep.id AS pos_order
             FROM candidate c
             LEFT JOIN user u ON u.id = c.user_id
             LEFT JOIN partylist p ON p.id = c.party_id
             LEFT JOIN vote v ON v.candidate_id = c.id AND v.election_id = ?
             LEFT JOIN election_position ep ON ep.election_id = c.election_id AND ep.title = c.position_title
             WHERE c.election_id = ?
             GROUP BY c.id, c.name, u.name, c.position_title, p.id, p.name, ep.id
             ORDER BY CASE WHEN c.position_title = 'President' THEN 0 ELSE 1 END, ep.id ASC, c.position_title ASC, vote_count DESC"
        );

        if (!$stmt3) {
            $out['success'] = false;
            $out['error']   = 'DB Prepare Error: ' . $conn->error;
            die(json_encode($out));
        }
        $stmt3->bind_param('ss', $eid, $eid);
        $stmt3->execute();
        $cand_res = $stmt3->get_result();
        $stmt3->close();

        // Group candidates into positions map
        $positions = [];
        
        // Pre-fill empty positions directly from the dedicated database table
        $stmtP = $conn->prepare("SELECT title, max_votes FROM election_position WHERE election_id = ? ORDER BY CASE WHEN title = 'President' THEN 0 ELSE 1 END, id ASC");
        if (!$stmtP) {
            $out['success'] = false;
            $out['error']   = 'DB Prepare Error (Position): ' . $conn->error;
            die(json_encode($out));
        }
        $stmtP->bind_param('s', $eid);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        $position_info = [];
        while($pRow = $resP->fetch_assoc()) {
            $positions[$pRow['title']] = [];
            $position_info[$pRow['title']] = isset($pRow['max_votes']) ? (int)$pRow['max_votes'] : 1;
        }
        $stmtP->close();

        while ($cand = $cand_res->fetch_assoc()) {
            $pos = $cand['position_title'];
            if (!isset($positions[$pos])) $positions[$pos] = [];
            $positions[$pos][] = [
                'candidate_id' => $cand['candidate_id'],
                'name'         => $cand['candidate_name'],
                'party_id'     => $cand['party_id'],
                'party'        => $cand['party_name'],
                'votes'        => (int)$cand['vote_count'],
            ];
        }

        // Append virtual 'Abstain' counts for each position so it displays correctly on Admin Dashboard charts
        foreach ($positions as $posTitle => &$posCandidates) {
            $abstain_id = 'ABSTAIN__' . $posTitle;
            $stmtA = $conn->prepare("SELECT COUNT(*) AS cnt FROM vote WHERE election_id = ? AND candidate_id = ?");
            if ($stmtA) {
                $stmtA->bind_param('ss', $eid, $abstain_id);
                $stmtA->execute();
                $resA = $stmtA->get_result();
                $abs_cnt = ($resA && $rowA = $resA->fetch_assoc()) ? (int)$rowA['cnt'] : 0;
                $stmtA->close();
                
                $posCandidates[] = [
                    'candidate_id' => $abstain_id,
                    'name'         => 'Abstain',
                    'party_id'     => 'none',
                    'party'        => '—',
                    'votes'        => $abs_cnt,
                ];
            }
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
            'user_voted'      => $user_voted,
            'positions'       => $positions,
            'position_info'   => $position_info,
        ];
    }

    $out['elections'] = $elections_out;
}

// ══════════════════════════════════════════════════════════════════════
//  4. VOTER RECEIPT
//  Returns the candidates a specific user voted for in an election.
// ══════════════════════════════════════════════════════════════════════
if ($section === 'receipt') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'voter' || !$election_id) {
        $out['success'] = false;
        $out['error']   = 'Unauthorized or missing election ID.';
    } else {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare(
            "SELECT 
                COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', '')) AS position_title, 
                COALESCE(NULLIF(c.name, ''), u.name, CASE WHEN v.candidate_id LIKE 'ABSTAIN__%' THEN 'Abstain' ELSE '' END) AS candidate_name, 
                COALESCE(p.name, CASE WHEN v.candidate_id LIKE 'ABSTAIN__%' THEN '—' ELSE 'Independent' END) AS party_name,
                COALESCE(ep.id, 999) AS pos_order
             FROM vote v
             LEFT JOIN candidate c ON v.candidate_id = c.id
             LEFT JOIN user u ON c.user_id = u.id
             LEFT JOIN partylist p ON c.party_id = p.id
             LEFT JOIN election_position ep ON ep.election_id = v.election_id AND ep.title = COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', ''))
             WHERE v.election_id = ? AND v.user_id = ?
             ORDER BY CASE WHEN COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', '')) = 'President' THEN 0 ELSE 1 END, pos_order ASC, position_title ASC"
        );
        if (!$stmt) {
            $out['success'] = false;
            $out['error']   = 'DB Prepare Error (Receipt): ' . $conn->error;
        } else {
            $stmt->bind_param('ss', $election_id, $user_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $receipt = [];
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $receipt[] = [
                        'position'  => $row['position_title'],
                        'candidate' => $row['candidate_name'],
                        'party'     => $row['party_name']
                    ];
                }
            }
            $stmt->close();
            $out['receipt'] = $receipt;
        }
    }
}

} catch (Throwable $e) {
    $out['success'] = false;
    $out['error']   = 'DB Exception: ' . $e->getMessage();
}

$conn->close();
echo json_encode($out);
?>