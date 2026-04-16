<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $complaint_id = (int)($_POST['complaint_id'] ?? 0);
    $status       = $_POST['comp_status'] ?? '';
    $valid = ['open','under_investigation','resolved','dismissed'];
    if ($complaint_id && in_array($status, $valid)) {
        $pdo->prepare("UPDATE qw_complaint SET comp_status=? WHERE complaint_id=?")
            ->execute([$status, $complaint_id]);
    }
    header('Location: complaints-queue.php'); exit;
}
$complaints = $pdo->query("
    SELECT c.*, u.first_name, u.last_name, u.email, u.role
    FROM qw_complaint c
    JOIN qw_user u ON c.user_id = u.user_id
    ORDER BY c.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>قائمة الشكاوى - قنوان</title>
    <link rel="stylesheet" href="style.css">

</head>
<body>

<header>
    <nav class="navbar">
        <h1 class="logo">Qinwan</h1>
        <ul class="nav-links">
            <li><a href="index.html">تصفح المزارع</a></li>
            <li><a href="investor.php">المستثمر</a></li>
            <li><a href="Farmer.php">المزارع</a></li>
            <li><a href="admin.php" class="active-admin-link">الإدارة</a></li>
            <li><a href="login.php">تسجيل الدخول</a></li>
            <li><a href="register.php" class="btn">ابدأ الآن</a></li>
        </ul>
    </nav>
</header>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>قائمة الشكاوى</h2>
        <p>استقبال الشكاوى والتحقيق فيها وتحديث حالتها بشكل منظم.</p>
    </section>

    <section class="admin-cards-list" id="complaintsCardsContainer"></section>
</main>

<script src="script2.js"></script>
</body>
</html>