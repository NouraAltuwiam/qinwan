<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
$pdo = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $farmer_id = (int)($_POST['farmer_id'] ?? 0);
    $decision  = $_POST['decision'] ?? '';
    if ($farmer_id && in_array($decision, ['verified','rejected'])) {
        $pdo->prepare("UPDATE qw_farmer SET verification_status=?, verified_by=?, verified_at=NOW() WHERE farmer_id=?")
            ->execute([$decision, $_SESSION['user_id'], $farmer_id]);
    }
    header('Location: farmer-verification.php'); exit;
}
$farmers = $pdo->query("
    SELECT fr.farmer_id, fr.national_id, fr.verification_status, fr.verified_at,
           u.first_name, u.last_name, u.email, u.phone, u.created_at
    FROM qw_farmer fr
    JOIN qw_user u ON fr.user_id = u.user_id
    ORDER BY fr.farmer_id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>توثيق حسابات المزارعين - قنوان</title>
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
        <h2>توثيق حسابات المزارعين</h2>
        <p>مراجعة الهوية الوطنية ومستندات ملكية المزرعة، ثم اعتماد أو رفض الطلب مع ملاحظة إجبارية.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="verificationSearchInput" placeholder="ابحث باسم المزارع">
    </section>

    <section class="admin-cards-list" id="verificationCardsContainer"></section>
</main>

<script src="script2.js"></script>
</body>
</html>