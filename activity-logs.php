<?php
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }
// ملاحظة: جدول سجل النشاط يعتمد على بيانات script2.js في النسخة الحالية
?>
<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سجل النشاط - قنوان</title>
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
        <h2>سجل النشاط</h2>
        <p>تدقيق جميع إجراءات النظام والمستخدمين مع الوقت والكيان المتأثر.</p>
    </section>

    <section class="admin-toolbar">
    <input type="text" id="logSearchInput" placeholder="ابحث باسم المستخدم أو نوع الإجراء">

    <input type="date" id="logDateFrom">
    <input type="date" id="logDateTo">
</section>

    <section class="admin-table-wrapper">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>معرف المستخدم</th>
                    <th>اسم المستخدم</th>
                    <th>نوع الإجراء</th>
                    <th>الكيان المتأثر</th>
                    <th>الوقت</th>
                </tr>
            </thead>
            <tbody id="activityLogsTableBody"></tbody>
        </table>
    </section>
</main>

<script src="script2.js"></script>
</body>
</html>