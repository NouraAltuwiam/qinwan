<?php
// ============================================================
// قِنوان — login.php
// US-02 Farmer/Investor: Login with lockout after 5 attempts
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    header('Location: ' . ($role === 'farmer' ? 'Farmer.php' : ($role === 'admin' ? 'admin.php' : 'investor.php')));
    exit;
}

$error = '';

// Lockout logic (US-02: 5 consecutive failed = 15 min lock)
$lockKey  = 'login_attempts_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
$lockTime = 'login_lock_until_' . md5($_SERVER['REMOTE_ADDR'] ?? '');

if (!isset($_SESSION[$lockKey]))   $_SESSION[$lockKey]  = 0;
if (!isset($_SESSION[$lockTime]))  $_SESSION[$lockTime] = 0;

$isLocked     = ($_SESSION[$lockTime] > time());
$attemptsLeft = max(0, 5 - $_SESSION[$lockKey]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isLocked) {
        $remaining = ceil(($_SESSION[$lockTime] - time()) / 60);
        $error = "تم تجاوز عدد المحاولات المسموحة. الحساب مقفل مؤقتاً. يرجى المحاولة بعد {$remaining} دقيقة.";
    } else {
        $email    = trim(strtolower($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور.';
        } else {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT * FROM qw_user WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $_SESSION[$lockKey]++;
                if ($_SESSION[$lockKey] >= 5) {
                    $_SESSION[$lockTime] = time() + 900; // 15 minutes
                    $error = 'تم تجاوز عدد المحاولات المسموحة. حسابك مقفل مؤقتاً لمدة 15 دقيقة.';
                    $isLocked = true;
                } else {
                    $remaining = 5 - $_SESSION[$lockKey];
                    $error = "بريد إلكتروني أو كلمة مرور غير صحيحة. تبقى لك {$remaining} محاولة.";
                }
            } else {
                // Success — reset counter
                $_SESSION[$lockKey]  = 0;
                $_SESSION[$lockTime] = 0;

                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['role']       = $user['role'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];

                // Log activity
                try {
                    $pdo->prepare("INSERT INTO qw_activity_log (user_id, action_type, entity_type, entity_id) VALUES (?, 'login', 'user', ?)")
                        ->execute([$user['user_id'], $user['user_id']]);
                } catch(Exception $e) {}

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
        <a href="index.php">
          <img class="auth-logo-img" src="images\logo.png" alt="قِنوان"
               onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
          <div class="auth-logo-fallback" style="display:none">ق</div>
        </a>
      </div>

      <h1>مرحباً بعودتك</h1>
      <p>سجّل دخولك إلى حسابك في قِنوان</p>

      <div class="auth-ornament">
        <div class="line"></div>
        <div class="diamond"></div>
        <div class="line" style="transform:scaleX(-1)"></div>
      </div>

      <?php if ($error): ?>
        <div class="auth-error">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if ($isLocked): ?>
        <div class="auth-locked">
          🔒 الحساب مقفل مؤقتاً بسبب تجاوز عدد المحاولات المسموحة. يرجى الانتظار 15 دقيقة.
        </div>
      <?php endif; ?>

      <form method="POST" action="login.php" id="loginForm">

        <label>البريد الإلكتروني</label>
        <input type="email" name="email" placeholder="xxxx@xxx.xxx" required
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
               <?= $isLocked ? 'disabled' : '' ?> />

        <label>كلمة المرور</label>
        <div class="password-wrap">
          <input type="password" name="password" id="password" placeholder="••••••••" required
                 <?= $isLocked ? 'disabled' : '' ?> />
          <button type="button" class="toggle-pw" onclick="togglePw()">👁</button>
        </div>

        <?php if (!$isLocked && $_SESSION[$lockKey] > 0): ?>
          <div class="attempts-warning">
            تنبيه: تبقى لك <?= 5 - $_SESSION[$lockKey] ?> محاولة قبل قفل الحساب مؤقتاً.
          </div>
        <?php endif; ?>

        <button type="submit" class="auth-btn" <?= $isLocked ? 'disabled' : '' ?>>تسجيل الدخول</button>
      </form>

      <div class="auth-link">
        ليس لديك حساب؟ <a href="register.php">إنشاء حساب</a>
      </div>

    </div>
  </div>

  <style>
    .auth-error {
      background: #fdf0ef; border: 1px solid #f5c6c6; border-radius: 8px;
      color: #c0392b; padding: 12px 16px; margin-bottom: 18px; font-size: 14px;
      border-right: 4px solid #c0392b;
    }
    .auth-locked {
      background: #fff8e1; border: 1px solid #ffe082; border-radius: 8px;
      color: #795548; padding: 12px 16px; margin-bottom: 18px; font-size: 14px;
      border-right: 4px solid #f9a825;
    }
    .attempts-warning {
      background: #fff3e0; border-radius: 6px; color: #e65100;
      padding: 8px 12px; font-size: 13px; margin-bottom: 14px; text-align: right;
    }
    .password-wrap { position: relative; margin-bottom: 20px; }
    .password-wrap input { margin-bottom: 0 !important; width: 100%; }
    .toggle-pw {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; font-size: 16px; padding: 0;
    }
    .auth-btn:disabled { opacity: 0.5; cursor: not-allowed; }
  </style>

  <script>
    function togglePw() {
      const inp = document.getElementById('password');
      const btn = document.querySelector('.toggle-pw');
      if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '🙈'; }
      else { inp.type = 'password'; btn.textContent = '👁'; }
    }
  </script>
</body>
</html>