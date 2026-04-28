<?php
// ============================================================
// قِنوان — report-problem.php  (US-Admin-07)
// صفحة تقديم الشكاوى للمستخدمين (مزارعين ومستثمرين)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// يجب أن يكون المستخدم مسجل دخول
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = getDB();
$success = false;
$error   = '';

// ============================================================
// معالجة تقديم الشكوى
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject     = trim($_POST['subject']     ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($subject)) {
        $error = 'يرجى كتابة موضوع الشكوى.';
    } elseif (empty($description)) {
        $error = 'يرجى كتابة تفاصيل الشكوى.';
    } elseif (mb_strlen($description) < 20) {
        $error = 'يرجى كتابة تفاصيل أكثر (20 حرف على الأقل).';
    } else {
        // ✅ إضافة الشكوى للـ DB
        $pdo->prepare("
            INSERT INTO qw_complaint (user_id, subject, description, comp_status, created_at)
            VALUES (?, ?, ?, 'open', NOW())
        ")->execute([$_SESSION['user_id'], $subject, $description]);

        // ✅ تسجيل في activity_log
        try {
            $pdo->prepare("
                INSERT INTO qw_activity_log (user_id, action_type, entity_type, created_at)
                VALUES (?, 'submit_complaint', 'complaint', NOW())
            ")->execute([$_SESSION['user_id']]);
        } catch(Exception $e) {}

        $success = true;
    }
}

// معلومات المستخدم الحالي
$user = $pdo->prepare("SELECT first_name, last_name, email, role FROM qw_user WHERE user_id = ?");
$user->execute([$_SESSION['user_id']]);
$user = $user->fetch();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإبلاغ عن مشكلة - قنوان</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .report-container {
            max-width: 600px;
            margin: 60px auto;
            padding: 0 20px;
        }
        .report-card {
            background: #fff;
            border-radius: 16px;
            padding: 36px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
        }
        .report-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        .report-subtitle {
            font-size: 0.95rem;
            color: #6b7280;
            margin-bottom: 28px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #1a1a1a;
            background: #f9fafb;
            box-sizing: border-box;
            transition: border 0.2s;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4ade80;
            background: #fff;
        }
        .form-group textarea {
            resize: vertical;
            min-height: 140px;
        }
        .char-count {
            font-size: 12px;
            color: #9ca3af;
            text-align: left;
            margin-top: 4px;
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }
        .btn-submit:hover { background: #15803d; }
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .alert-success {
            text-align: center;
            padding: 40px 20px;
        }
        .alert-success .success-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .alert-success h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #16a34a;
            margin-bottom: 8px;
        }
        .alert-success p {
            color: #6b7280;
            margin-bottom: 24px;
        }
        .btn-back {
            display: inline-block;
            padding: 10px 24px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .btn-back:hover { background: #e5e7eb; }
        .user-info-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            color: #166534;
        }
    </style>
</head>
<body>

<nav>
    <button class="nav-back" onclick="history.back()">← رجوع</button>
    <div class="nav-links">
        <a href="index.php"  class="nav-link">الرئيسية</a>
        <a href="logout.php" class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='index.php'">
        <img class="logo-img" src="logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span></div>
    </div>
</nav>

<div class="report-container">
    <div class="report-card">

        <?php if ($success): ?>
        <!-- ✅ رسالة النجاح -->
        <div class="alert-success">
            <div class="success-icon">✅</div>
            <h3>تم إرسال شكواك بنجاح</h3>
            <p>سيقوم فريق الإدارة بمراجعة شكواك والرد عليك في أقرب وقت ممكن.</p>
            <a href="index.php" class="btn-back">العودة للرئيسية</a>
            &nbsp;
            <a href="report-problem.php" class="btn-back">تقديم شكوى أخرى</a>
        </div>

        <?php else: ?>
        <!-- ✅ Form تقديم الشكوى -->
        <h2 class="report-title">📋 الإبلاغ عن مشكلة</h2>
        <p class="report-subtitle">اشرح المشكلة التي تواجهها وسيتواصل معك فريق الدعم في أقرب وقت.</p>

        <!-- معلومات المستخدم -->
        <div class="user-info-box">
            👤 تقديم الشكوى بواسطة:
            <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            (<?= $user['role'] === 'farmer' ? 'مزارع' : 'مستثمر' ?>)
            — <?= htmlspecialchars($user['email']) ?>
        </div>

        <!-- رسالة الخطأ -->
        <?php if ($error): ?>
            <div class="alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">

            <!-- موضوع الشكوى -->
            <div class="form-group">
                <label>موضوع الشكوى *</label>
                <input type="text"
                       name="subject"
                       placeholder="مثال: مشكلة في عملية الدفع"
                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                       maxlength="255"
                       required>
            </div>

            <!-- تفاصيل الشكوى -->
            <div class="form-group">
                <label>تفاصيل الشكوى *</label>
                <textarea name="description"
                          id="descArea"
                          placeholder="اشرح المشكلة بالتفصيل..."
                          maxlength="2000"
                          oninput="updateCount()"
                          required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                <div class="char-count">
                    <span id="charCount">0</span> / 2000 حرف
                </div>
            </div>

            <button type="submit" class="btn-submit">📤 إرسال الشكوى</button>
        </form>
        <?php endif; ?>

    </div>
</div>

<script>
function updateCount() {
    const len = document.getElementById('descArea').value.length;
    document.getElementById('charCount').textContent = len;
}
// تحديث العداد عند تحميل الصفحة لو في نص محفوظ
updateCount();
</script>
</body>
</html>
