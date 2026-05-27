<?php
session_start();
include 'db_connect.php';

$txn_id = $_GET['txn'] ?? '';
if (empty($txn_id)) {
    die("Error: No Transaction ID provided.");
}

// Prevent unauthorized access
if (!isset($_SESSION['user_id'])) {
    die("Error: Unauthorized access. Please log in.");
}

try {

    // 1. Fetch election and voter info based on the transaction ID
    $infoStmt = $pdo->prepare("
        SELECT DISTINCT 
            e.title AS election_name, 
            e.date_of_election,
            u.student_id, 
            u.name AS voter_name
        FROM vote v
        JOIN election e ON v.election_id = e.id
        JOIN user u ON v.user_id = u.id
        WHERE v.transaction_id = :txn
    ");
    $infoStmt->execute([':txn' => $txn_id]);
    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

    if (!$info) {
        die("Error: Invalid Transaction ID or record not found.");
    }

    // 2. Fetch the actual votes for this transaction
    $voteStmt = $pdo->prepare("
        SELECT 
            COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', '')) AS position_title, 
            COALESCE(NULLIF(c.name, ''), u.name, CASE WHEN v.candidate_id LIKE 'ABSTAIN__%' THEN 'Abstain' ELSE '' END) AS candidate_name, 
            COALESCE(p.name, CASE WHEN v.candidate_id LIKE 'ABSTAIN__%' THEN '—' ELSE 'Independent' END) AS party_name,
            COALESCE(ep.id, 999) AS pos_order
        FROM vote v
        LEFT JOIN candidate c ON v.candidate_id = c.id
        LEFT JOIN user u ON c.user_id = u.id
        LEFT JOIN partylist p ON c.party_id = p.id
        LEFT JOIN election_position ep ON ep.election_id = v.election_id AND ep.title = COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', ''))
        WHERE v.transaction_id = :txn
        ORDER BY CASE WHEN COALESCE(c.position_title, REPLACE(v.candidate_id, 'ABSTAIN__', '')) = 'President' THEN 0 ELSE 1 END, pos_order ASC, position_title ASC
    ");
    $voteStmt->execute([':txn' => $txn_id]);
    $votes = $voteStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Voting Manifest - <?php echo htmlspecialchars($txn_id); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            color: #111827;
            background: #f3f4f6;
            padding: 2rem;
        }
        .manifest-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 3rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-top: 8px solid #0a58ca;
        }
        .header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .header h1 { margin: 0 0 0.5rem 0; font-size: 1.8rem; text-transform: uppercase; letter-spacing: 1px; }
        .header p { margin: 0; color: #6b7280; font-family: 'Courier Prime', monospace; }
        
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 6px;
        }
        .meta-item strong { display: block; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem; }
        .meta-item span { font-weight: 600; font-size: 1.1rem; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }
        th { text-align: left; background: #f1f5f9; padding: 1rem; font-size: 0.85rem; text-transform: uppercase; color: #475569; border-bottom: 2px solid #cbd5e1; }
        td { padding: 1rem; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
        .td-pos { font-weight: 600; color: #0f172a; }
        .td-cand { font-family: 'Courier Prime', monospace; font-size: 1.1rem; }
        .td-party { font-size: 0.85rem; color: #64748b; }
        
        .footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px dashed #cbd5e1;
            font-size: 0.85rem;
            color: #94a3b8;
        }

        /* Specific CSS rules for Desktop Printing (window.print) */
        @media print {
            body { background: white; padding: 0; }
            .manifest-container { box-shadow: none; border: none; padding: 0; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="manifest-container">
        <div class="no-print" style="text-align:right; margin-bottom: 1rem;">
            <button onclick="window.print()" style="background:#0a58ca; color:white; border:none; padding:10px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Print Manifest</button>
        </div>
        
        <div class="header">
            <h1>Official Voting Manifest</h1>
            <p>TXN ID: <?php echo htmlspecialchars($txn_id); ?></p>
        </div>

        <div class="meta-grid">
            <div class="meta-item"><strong>Election Event</strong><span><?php echo htmlspecialchars($info['election_name']); ?></span></div>
            <div class="meta-item"><strong>Date Issued</strong><span><?php echo date('F d, Y - h:i A'); ?></span></div>
            <div class="meta-item"><strong>Voter Name</strong><span><?php echo htmlspecialchars($info['voter_name']); ?></span></div>
            <div class="meta-item"><strong>Student ID</strong><span><?php echo htmlspecialchars($info['student_id']); ?></span></div>
        </div>

        <table>
            <thead><tr><th>Position</th><th>Candidate Selected</th><th>Party Affiliation</th></tr></thead>
            <tbody>
                <?php foreach ($votes as $v): ?>
                <tr>
                    <td class="td-pos"><?php echo htmlspecialchars($v['position_title']); ?></td>
                    <td class="td-cand"><?php echo htmlspecialchars($v['candidate_name']); ?></td>
                    <td class="td-party"><?php echo htmlspecialchars($v['party_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer">This document is system-generated by NU-SmartVote and serves as cryptographic proof of your ballot submission.</div>
    </div>
    <script>window.onload = function() { window.print(); }</script>
</body>
</html>