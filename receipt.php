<?php
session_start();

// 1. Validate user session parameters
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

// 2. Read transaction URL parameter securely
if (empty($_GET['transaction_id'])) {
    die("Invalid Transaction Reference ID.");
}

$transaction_id = $_GET['transaction_id'];

try {
    // 3. Execute parameterized PDO query joining tables
    $pdo = new PDO("mysql:host=localhost;dbname=voting_system;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        SELECT v.transaction_id, v.created_at, u.name as client_identity, c.position_title as category_name, c.name as asset_title
        FROM vote v
        JOIN user u ON v.user_id = u.id
        JOIN candidate c ON v.candidate_id = c.id
        WHERE v.transaction_id = :txn_id
    ");
    $stmt->execute([':txn_id' => $transaction_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        die("Manifest record not found.");
    }

    $client = htmlspecialchars($rows[0]['client_identity']);
    $hash = htmlspecialchars($rows[0]['transaction_id']);
    $timestamp = isset($rows[0]['created_at']) ? htmlspecialchars($rows[0]['created_at']) : date('Y-m-d H:i:s');

} catch (Exception $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>B2B Resource Manifest & Corporate Voucher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; margin: 0; }
            .voucher-card { box-shadow: none !important; border: 1px solid #ccc !important; padding: 1.5rem !important; }
        }
        body { background: #f8f9fa; padding: 3rem 1rem; font-family: system-ui, -apple-system, sans-serif; }
        .voucher-card { background: white; max-width: 750px; margin: 0 auto; padding: 3rem; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .brand-header { font-size: 1.6rem; font-weight: 800; color: #0a58ca; border-bottom: 2px solid #e9ecef; padding-bottom: 1rem; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="voucher-card">
        <div class="brand-header">B2B Resource Allocation System</div>
        <div class="row mb-4">
            <div class="col-sm-6">
                <h6 class="text-muted text-uppercase fw-bold" style="font-size: 0.8rem;">Client Identity</h6>
                <p class="fs-5 fw-bold text-dark"><?= $client ?></p>
            </div>
            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                <h6 class="text-muted text-uppercase fw-bold" style="font-size: 0.8rem;">Reference Hash</h6>
                <p class="font-monospace text-break mb-1" style="color: #495057;"><?= $hash ?></p>
                <p class="text-muted small mb-0"><?= $timestamp ?></p>
            </div>
        </div>
        
        <h5 class="mb-3 mt-4 border-bottom pb-2 fw-bold text-dark">Selected Assets / Resources</h5>
        <table class="table table-hover align-middle mb-4">
            <thead class="table-light">
                <tr>
                    <th>Category Name</th>
                    <th>Asset Title / Vendor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold text-secondary"><?= htmlspecialchars($row['category_name']) ?></td>
                    <td class="fw-bold text-dark"><?= htmlspecialchars($row['asset_title']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="text-center mt-5 pt-3 no-print">
            <button class="btn btn-primary px-4 py-2 fw-bold" onclick="window.print();">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" class="bi bi-printer me-2" viewBox="0 0 16 16"><path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/></svg>
                Print Manifest Voucher
            </button>
        </div>
    </div>
</body>
</html>