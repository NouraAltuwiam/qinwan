<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_id = (int)($_POST['update_id'] ?? 0);
    if ($update_id) {
        $pdo->prepare("DELETE FROM qw_farm_update WHERE update_id=?")->execute([$update_id]);
    }
    header('Location: content-moderation.php'); exit;
}
$updates = $pdo->query("
    SELECT fu.update_id, fu.content, fu.created_at,
           f.name AS farm_name,
           u.first_name, u.last_name
    FROM qw_farm_update fu
    JOIN qw_farm f    ON fu.farm_id  = f.farm_id
    JOIN qw_farmer fr ON f.farmer_id = fr.farmer_id
    JOIN qw_user u    ON fr.user_id  = u.user_id
    ORDER BY fu.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المحتوى - قنوان</title>
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
        <h2>إدارة المحتوى</h2>
        <p>حذف التحديثات المخالفة أو تعديل المخالفات البسيطة دون حذف المنشور بالكامل.</p>
    </section>

    <section class="admin-cards-list" id="contentModerationContainer"></section>
</main>

<script src="script2.js"></script>
</body>
</html>