<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $farm_id  = (int)($_POST['farm_id']  ?? 0);
    $decision = $_POST['decision'] ?? '';
    if ($farm_id && in_array($decision, ['approved','rejected','deactivated'])) {
        $pdo->prepare("UPDATE qw_farm SET farm_status=?, approved_by=? WHERE farm_id=?")
            ->execute([$decision, $_SESSION['user_id'], $farm_id]);
    }
    header('Location: farms-managment.php'); exit;
}
$farms = $pdo->query("
    SELECT f.*, u.first_name, u.last_name
    FROM qw_farm f
    JOIN qw_farmer fr ON f.farmer_id = fr.farmer_id
    JOIN qw_user u    ON fr.user_id  = u.user_id
    ORDER BY f.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المزارع - قنوان</title>
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
        <h2>إدارة المزارع</h2>
        <p>مراجعة المزارع واعتمادها وتعديلها وتعطيلها أو حذفها.</p>
    </section>

<section class="admin-toolbar">
    <input type="text" id="farmSearchInput" placeholder="ابحث باسم المزرعة أو اسم المالك">
</section>

<section id="farmRequestsContainer" class="admin-cards-list"></section>

<script src="script2.js"></script>
</body>
</html>