<?php
// ============================================================
// قِنوان — login.php
// تسجيل الدخول — يطابق login.html بالكامل
// Tables: qw_user, qw_farmer, qw_investor
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$error = '';

// ── معالجة الـ POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    if (!$email || !$password) {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    } else {
        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM qw_user WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'البريد الإلكتروني غير مسجل';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'كلمة المرور غير صحيحة';
        } else {
            // ── تعيين الجلسة ──
            $_SESSION['user_id']    = $user['user_id'];
            $_SESSION['role']       = $user['role'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name']  = $user['last_name'];

            // جلب farmer_id أو investor_id
            if ($user['role'] === 'farmer') {
                $s = $pdo->prepare("SELECT farmer_id FROM qw_farmer WHERE user_id = ?");
                $s->execute([$user['user_id']]);
                $row = $s->fetch();
                $_SESSION['farmer_id'] = $row['farmer_id'] ?? null;
                header('Location: Farmer.php');
            } elseif ($user['role'] === 'investor') {
                $s = $pdo->prepare("SELECT investor_id FROM qw_investor WHERE user_id = ?");
                $s->execute([$user['user_id']]);
                $row = $s->fetch();
                $_SESSION['investor_id'] = $row['investor_id'] ?? null;
                header('Location: investor.php');
            } else {
                header('Location: admin.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>قِنوان | تسجيل الدخول</title>
  <link rel="stylesheet" href="style.css" />
</head>

<body>
  <div class="auth-container">
    <div class="auth-box">

      <div class="auth-logo">
        <img class="auth-logo-img" src="logo.png" alt="قِنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="auth-logo-fallback" style="display:none">ق</div>
      </div>

      <h1>مرحباً بعودتك</h1>
      <p>سجّل دخولك إلى حسابك في قِنوان</p>

      <div class="auth-ornament">
        <div class="line"></div>
        <div class="diamond"></div>
        <div class="line" style="transform:scaleX(-1)"></div>
      </div>

      <?php if ($error): ?>
        <div class="auth-error" style="color:#c0392b;background:#fdf0ef;border:1px solid #f5c6c6;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:14px;">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php">

        <!-- email في جدول qw_user -->
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" placeholder="example@email.com" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />

        <!-- password_hash في جدول qw_user -->
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="••••••••" required />

        <button type="submit" class="auth-btn">تسجيل الدخول</button>
      </form>

      <div class="auth-link">
        ليس لديك حساب؟ <a href="register.php">إنشاء حساب</a>
      </div>

    </div>
  </div>

  <script src="script.js"></script>
</body>
</html>
