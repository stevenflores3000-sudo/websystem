<?php
// ═══════════════════════════════════════════════════════════════════════
//  register.php  (updated)
//  Changes from original:
//    • Accepts recovery_email (personal Gmail) as a separate POST field
//    • Uses prepared statements (no raw string interpolation → safer)
//    • Validates recovery_email format and Gmail domain
//    • Stores recovery_email in the new user.recovery_email column
// ═══════════════════════════════════════════════════════════════════════
include 'db_connect.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit();
}

// ── 1. Presence check ────────────────────────────────────────────────
$required = ['name', 'student_id', 'email', 'recovery_email', 'password'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        header("Location: registration_error.php?type=missing_fields");
        exit();
    }
}

// ── 2. Collect & sanitize ────────────────────────────────────────────
$full_name      = trim($_POST['name']);
$student_id     = trim($_POST['student_id']);
$email          = strtolower(trim($_POST['email']));           // NU email
$recovery_email = strtolower(trim($_POST['recovery_email']));  // personal Gmail
$dept           = trim($_POST['department']  ?? '');
$year_level     = trim($_POST['year_level']  ?? '');
$password       = $_POST['password'];

// ── 3. Validate NU email domain ──────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: registration_error.php?type=invalid_email");
    exit();
}

// ── 4. Validate recovery Gmail format and domain ─────────────────────
if (!filter_var($recovery_email, FILTER_VALIDATE_EMAIL)) {
    header("Location: registration_error.php?type=invalid_recovery_email");
    exit();
}
$recovery_domain = strtolower(substr(strrchr($recovery_email, '@'), 1));
if (!in_array($recovery_domain, ['gmail.com', 'googlemail.com'], true)) {
    header("Location: registration_error.php?type=recovery_not_gmail");
    exit();
}

// ── 5. Password minimum length ───────────────────────────────────────
if (strlen($password) < 6) {
    header("Location: registration_error.php?type=weak_password");
    exit();
}

// ── 6. Hash password ─────────────────────────────────────────────────
$hashed_pass = password_hash($password, PASSWORD_DEFAULT);

// ── 7. Generate unique user ID ───────────────────────────────────────
$u_id = "USR-" . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));

// ── 8. Insert using prepared statement (prevents SQL injection) ───────
$stmt = $conn->prepare(
    "INSERT INTO user
         (id, student_id, name, email, recovery_email, department, year_level, password)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);

if (!$stmt) {
    // Prepare failed — likely column doesn't exist yet; run schema_updates.sql
    header("Location: registration_error.php?type=db_error");
    exit();
}

$stmt->bind_param(
    'ssssssss',
    $u_id,
    $student_id,
    $full_name,
    $email,
    $recovery_email,
    $dept,
    $year_level,
    $hashed_pass
);

$ok = $stmt->execute();

if ($ok) {
    $stmt->close();
    header("Location: registration_success.php");
    exit();
}

// ── 9. Handle specific DB errors ─────────────────────────────────────
$errno = $stmt->errno;
$error = $stmt->error;
$stmt->close();

if ($errno === 1062) {
    // Duplicate unique key — determine which field
    if (str_contains($error, 'student_id'))     header("Location: registration_error.php?type=duplicate_id");
    elseif (str_contains($error, 'uq_email') || str_contains($error, "'email'"))
                                                 header("Location: registration_error.php?type=duplicate_email");
    elseif (str_contains($error, 'recovery'))    header("Location: registration_error.php?type=duplicate_recovery_email");
    else                                         header("Location: registration_error.php?type=duplicate_id");
} else {
    header("Location: registration_error.php?type=db_error");
}
exit();
?>