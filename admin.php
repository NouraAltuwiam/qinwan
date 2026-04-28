<?php
// ============================================================
// قِنوان — admin.php  (US-Admin-06: Platform Statistics)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

/* ============================================================
   ✅ معالجة قبول أو رفض تحديثات المزارع
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_action'], $_POST['update_id'])) {
    $updateId = (int) $_POST['update_id'];
    $action   = $_POST['update_action'];

    if ($action === 'approve') {
        $newStatus    = 'approved';
        $actionType   = 'update_approved';
        $notifyTitle  = 'تم قبول التحديث';
        $notifyMsg    = 'تمت الموافقة على تحديث المزرعة وسيظهر للمستثمرين.';
    } elseif ($action === 'reject') {
        $newStatus    = 'rejected';
        $actionType   = 'update_rejected';
        $notifyTitle  = 'تم رفض التحديث';
        $notifyMsg    = 'تم رفض تحديث المزرعة ولن يظهر للمستثمرين.';
    } else {
        header('Location: admin.php#farm-updates'); exit;
    }

    $pdo->prepare("UPDATE qw_farm_update SET status = ? WHERE update_id = ?")
        ->execute([$newStatus, $updateId]);

    try {
        $pdo->prepare("
            INSERT INTO qw_activity_log (user_id, action_type, entity_type, entity_id, created_at)
            VALUES (?, ?, 'farm_update', ?, NOW())
        ")->execute([$_SESSION['user_id'], $actionType, $updateId]);
    } catch (Exception $e) {}

    try {
        $pdo->prepare("
            INSERT INTO qw_notification (user_id, title, message, created_at)
            SELECT u.user_id, ?, ?, NOW()
            FROM qw_farm_update fu
            JOIN qw_farm f    ON fu.farm_id   = f.farm_id
            JOIN qw_farmer fr ON f.farmer_id  = fr.farmer_id
            JOIN qw_user u    ON fr.user_id   = u.user_id
            WHERE fu.update_id = ?
        ")->execute([$notifyTitle, $notifyMsg, $updateId]);
    } catch (Exception $e) {}

    header('Location: admin.php#farm-updates'); exit;
}

/* ============================================================
   إحصائيات
   ============================================================ */
$totalUsers  = (int)$pdo->query("SELECT COUNT(*) FROM qw_user")->fetchColumn();
$activeFarms = (int)$pdo->query("SELECT COUNT(*) FROM qw_farm WHERE farm_status='approved'")->fetchColumn();
$leasedArea  = (float)$pdo->query("SELECT COALESCE(SUM(area_sqm),0) FROM qw_investment_request WHERE req_status='accepted'")->fetchColumn();
$txVolume    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM qw_transaction WHERE payment_status='paid'")->fetchColumn();
$newMonth    = (int)$pdo->query("SELECT COUNT(*) FROM qw_user WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

/* ============================================================
   ✅ TC2: API endpoint للـ auto-refresh
   ============================================================ */
if (isset($_GET['api']) && $_GET['api'] === 'stats') {
    header('Content-Type: application/json');
    echo json_encode([
        'totalUsers'  => $totalUsers,
        'activeFarms' => $activeFarms,
        'leasedArea'  => $leasedArea,
        'txVolume'    => $txVolume,
        'newMonth'    => $newMonth,
        'refreshedAt' => date('H:i:s'),
    ]);
    exit;
}

/* ============================================================
   التحديثات المعلقة
   ============================================================ */
$pendingUpdates = $pdo->query("
    SELECT fu.update_id, fu.content, fu.media_urls, fu.media_type, fu.created_at,
           f.name AS farm_name,
           CONCAT(u.first_name, ' ', u.last_name) AS farmer_name
    FROM qw_farm_update fu
    JOIN qw_farm f    ON fu.farm_id   = f.farm_id
    JOIN qw_farmer fr ON f.farmer_id  = fr.farmer_id
    JOIN qw_user u    ON fr.user_id   = u.user_id
    WHERE fu.status = 'pending'
    ORDER BY fu.created_at DESC
")->fetchAll();
$pendingCount = count($pendingUpdates);

/* ============================================================
   بيانات الرسم البياني
   ============================================================ */
$monthlyStmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
    FROM qw_user
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
");
$monthlyData = $monthlyStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>لوحة الإدارة - قنوان</title>
    <link rel="stylesheet" href="style.css" />
    <style>
        /* ✅ تحديثات المزارع */
        .updates-section { margin-top: 36px; }
        .updates-header { display:flex; align-items:center; gap:12px; margin-bottom:20px; }
        .updates-header h2 { font-size:1.2rem; font-weight:700; color:var(--text-main,#1a1a1a); margin:0; }
        .badge-pending { background:#f59e0b; color:#fff; border-radius:20px; padding:2px 12px; font-size:0.82rem; font-weight:700; }
        .update-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px 24px; margin-bottom:16px; display:flex; flex-direction:column; gap:12px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
        .update-card-top { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; }
        .update-meta { font-size:0.85rem; color:#6b7280; }
        .update-meta strong { color:#1a1a1a; }
        .update-content { font-size:0.97rem; color:#374151; line-height:1.7; background:#f9fafb; border-radius:8px; padding:12px 16px; border-right:3px solid #4ade80; }
        .update-actions { display:flex; gap:10px; justify-content:flex-end; }
        .btn-approve { background:#16a34a; color:#fff; border:none; border-radius:8px; padding:8px 20px; font-size:0.9rem; font-weight:600; cursor:pointer; }
        .btn-approve:hover { background:#15803d; }
        .btn-reject { background:#dc2626; color:#fff; border:none; border-radius:8px; padding:8px 20px; font-size:0.9rem; font-weight:600; cursor:pointer; }
        .btn-reject:hover { background:#b91c1c; }
        .no-updates { text-align:center; padding:40px; color:#9ca3af; background:#f9fafb; border-radius:12px; border:1px dashed #e5e7eb; }
        .update-timestamp { font-size:0.8rem; color:#9ca3af; }

        /* ✅ شريط الـ refresh */
        .refresh-bar {
            display:flex; align-items:center; gap:12px;
            background:#f0fdf4; border:1px solid #bbf7d0;
            border-radius:8px; padding:8px 16px; margin-bottom:20px;
            font-size:13px; color:#166534;
        }
        .refresh-dot {
            width:8px; height:8px; border-radius:50%;
            background:#16a34a; animation:pulse 2s infinite;
        }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }
        .stat-value { transition: all 0.4s ease; }
        .stat-updated { color:#16a34a !important; transform:scale(1.05); }
    </style>
</head>
<body>

<nav>
    <button class="nav-back" onclick="window.location.href='index.php'">العودة للرئيسية</button>
    <div class="nav-links">
        <a href="admin.php"               class="nav-link active">لوحة الإدارة</a>
        <a href="users-managment.php"     class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"     class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link">التوثيق</a>
        <a href="transactions-monitor.php" class="nav-link">المعاملات</a>
        <a href="complaints-queue.php"    class="nav-link">الشكاوى</a>
        <a href="activity-logs.php"       class="nav-link">السجل</a>
        <a href="content-moderation.php"  class="nav-link">المحتوى</a>
        <a href="logout.php"              class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <!-- ✅ مسار الصورة حسب نسختها -->
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
        <div class="logo-fallback" style="display:none">ق</div>
        <div>
            <span class="logo-name">قنوان</span>
            <span class="logo-sub">الإدارة</span>
        </div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <div class="page-content" style="padding:0;">

        <div class="page-title-wrap" style="margin-bottom:28px;">
            <h1 class="page-title">لوحة الإدارة</h1>
            <div class="title-ornament">
                <div class="orn-line" style="width:60px"></div>
                <div class="orn-diamond"></div>
                <div class="orn-dot"></div>
                <div class="orn-diamond"></div>
                <div class="orn-line" style="width:24px"></div>
            </div>
        </div>

        <p style="color:var(--text-muted);margin-bottom:20px;">
            متابعة الأداء العام للمنصة، وإدارة المستخدمين والمزارع والمعاملات والشكاوى وسجل النشاط من مكان واحد.
        </p>

        <!-- ✅ TC2: شريط الـ auto-refresh -->
        <div class="refresh-bar">
            <div class="refresh-dot"></div>
            <span>البيانات تتحدث تلقائياً كل 5 دقائق — آخر تحديث: <strong id="lastRefresh"><?= date('H:i:s') ?></strong></span>
            <button onclick="refreshStats()" style="margin-right:auto;background:#16a34a;color:#fff;border:none;border-radius:6px;padding:4px 12px;cursor:pointer;font-size:12px;">🔄 تحديث الآن</button>
        </div>

        <!-- ✅ TC1: إحصائيات من الـ DB -->
        <section class="admin-stats-grid">
            <div class="admin-stat-card">
                <h3>إجمالي المستخدمين</h3>
                <p class="stat-value" id="stat-totalUsers"><?= number_format($totalUsers) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>المزارع النشطة</h3>
                <p class="stat-value" id="stat-activeFarms"><?= number_format($activeFarms) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>المساحة المؤجرة (م²)</h3>
                <p class="stat-value" id="stat-leasedArea"><?= number_format($leasedArea, 0) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>حجم المعاملات (ر.س)</h3>
                <p class="stat-value" id="stat-txVolume"><?= number_format($txVolume, 0) ?></p>
            </div>
            <div class="admin-stat-card">
                <h3>تسجيلات هذا الشهر</h3>
                <p class="stat-value" id="stat-newMonth"><?= number_format($newMonth) ?></p>
            </div>
        </section>

        <!-- ✅ TC3: Chart + Export Excel حقيقي -->
        <section class="admin-chart-card">
            <div class="admin-chart-header">
                <h3>التسجيلات الشهرية (آخر 12 شهراً)</h3>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="admin-action-btn btn-primary" onclick="exportExcel()">📊 تصدير Excel</button>
                    <button class="admin-action-btn btn-primary" onclick="exportPDF()">📄 تصدير PDF</button>
                </div>
            </div>
            <div class="monthly-bars" id="monthlyBars">
                <?php
                $maxCnt = max(array_column($monthlyData, 'cnt') ?: [1]);
                foreach ($monthlyData as $m):
                    $heightPct = ($maxCnt > 0) ? round($m['cnt'] / $maxCnt * 100) : 0;
                ?>
                <div class="bar-item">
                    <div class="bar-value"><?= $m['cnt'] ?></div>
                    <div class="bar" style="height:<?= max($heightPct, 6) ?>px;"></div>
                    <div class="bar-label"><?= substr($m['month'],5) ?></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($monthlyData)): ?>
                    <div style="color:var(--text-faint);text-align:center;width:100%;padding:40px 0;">لا توجد بيانات بعد</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ✅ تحديثات المزارع المعلقة -->
        <section class="updates-section" id="farm-updates">
            <div class="updates-header">
                <h2>🌿 تحديثات المزارع — بانتظار المراجعة</h2>
                <?php if ($pendingCount > 0): ?>
                    <span class="badge-pending"><?= $pendingCount ?> معلق</span>
                <?php endif; ?>
            </div>

            <?php if (empty($pendingUpdates)): ?>
                <div class="no-updates">✅ لا توجد تحديثات معلقة حالياً</div>
            <?php else: ?>
                <?php foreach ($pendingUpdates as $upd): ?>
                <div class="update-card">
                    <div class="update-card-top">
                        <div class="update-meta">
                            <strong>المزرعة:</strong> <?= htmlspecialchars($upd['farm_name']) ?>
                            &nbsp;|&nbsp;
                            <strong>المزارع:</strong> <?= htmlspecialchars($upd['farmer_name']) ?>
                        </div>
                        <div class="update-timestamp">
                            🕐 <?= date('Y/m/d — H:i', strtotime($upd['created_at'])) ?>
                        </div>
                    </div>
                    <div class="update-content">
                        <?= nl2br(htmlspecialchars($upd['content'])) ?>
                    </div>
                    <?php if (!empty($upd['media_urls'])): ?>
                        <div style="font-size:0.85rem;color:#6b7280;">
                            📎 يحتوي على مرفقات (<?= htmlspecialchars($upd['media_type'] ?? 'ملف') ?>)
                        </div>
                    <?php endif; ?>
                    <div class="update-actions">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('هل تريد قبول هذا التحديث؟')">
                            <input type="hidden" name="update_id"     value="<?= $upd['update_id'] ?>">
                            <input type="hidden" name="update_action" value="approve">
                            <button type="submit" class="btn-approve">✅ قبول</button>
                        </form>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('هل تريد رفض هذا التحديث؟')">
                            <input type="hidden" name="update_id"     value="<?= $upd['update_id'] ?>">
                            <input type="hidden" name="update_action" value="reject">
                            <button type="submit" class="btn-reject">❌ رفض</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <!-- Admin panels -->
        <section class="admin-panel-grid">
            <a href="users-managment.php"      class="admin-panel-card"><h3>👥 إدارة المستخدمين</h3><p>عرض جميع المستخدمين، البحث، التصفية، ومراجعة حسابات المزارعين.</p></a>
            <a href="farms-managment.php"      class="admin-panel-card"><h3>🌴 إدارة المزارع</h3><p>مراجعة طلبات المزارع، اعتمادها، تعديلها، تعطيلها أو حذفها.</p></a>
            <a href="farmer-verification.php"  class="admin-panel-card"><h3>✅ توثيق المزارعين</h3><p>مراجعة الهوية الوطنية ومستندات الملكية واعتماد أو رفض الطلب.</p></a>
            <a href="transactions-monitor.php" class="admin-panel-card"><h3>💳 مراقبة المعاملات</h3><p>عرض جميع المعاملات الاستثمارية ومتابعة حالاتها والتنبيهات المشبوهة.</p></a>
            <a href="complaints-queue.php"     class="admin-panel-card"><h3>📋 قائمة الشكاوى</h3><p>استقبال الشكاوى، التحقيق فيها، وتحديث حالتها بشكل منظم.</p></a>
            <a href="activity-logs.php"        class="admin-panel-card"><h3>📑 سجل النشاط</h3><p>تتبع جميع الإجراءات المهمة مع الوقت والكيان المتأثر.</p></a>
            <a href="content-moderation.php"   class="admin-panel-card"><h3>🛡 إدارة المحتوى</h3><p>حذف المنشورات المخالفة أو تعديل المخالفات البسيطة دون حذف المنشور كاملاً.</p></a>
        </section>

    </div>
</main>

<!-- ✅ TC3: SheetJS لتصدير Excel حقيقي -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// ✅ TC2: Auto-refresh كل 5 دقائق
const REFRESH_INTERVAL = 5 * 60 * 1000;

function refreshStats() {
    fetch('admin.php?api=stats')
        .then(r => r.json())
        .then(data => {
            updateStat('stat-totalUsers',  data.totalUsers.toLocaleString());
            updateStat('stat-activeFarms', data.activeFarms.toLocaleString());
            updateStat('stat-leasedArea',  Math.round(data.leasedArea).toLocaleString());
            updateStat('stat-txVolume',    Math.round(data.txVolume).toLocaleString());
            updateStat('stat-newMonth',    data.newMonth.toLocaleString());
            document.getElementById('lastRefresh').textContent = data.refreshedAt;
        })
        .catch(e => console.log('Refresh error:', e));
}

function updateStat(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value;
        el.classList.add('stat-updated');
        setTimeout(() => el.classList.remove('stat-updated'), 1000);
    }
}

setInterval(refreshStats, REFRESH_INTERVAL);

// ✅ TC3: تصدير Excel حقيقي
function exportExcel() {
    const stats = [
        ['الإحصائية', 'القيمة'],
        ['إجمالي المستخدمين',    <?= $totalUsers ?>],
        ['المزارع النشطة',       <?= $activeFarms ?>],
        ['المساحة المؤجرة (م²)', <?= $leasedArea ?>],
        ['حجم المعاملات (ر.س)', <?= $txVolume ?>],
        ['تسجيلات هذا الشهر',   <?= $newMonth ?>],
    ];
    const monthly = [
        ['الشهر', 'عدد التسجيلات'],
        <?php foreach ($monthlyData as $m): ?>
        ['<?= $m['month'] ?>', <?= $m['cnt'] ?>],
        <?php endforeach; ?>
    ];
    const wb  = XLSX.utils.book_new();
    const ws1 = XLSX.utils.aoa_to_sheet(stats);
    ws1['!cols'] = [{wch:30},{wch:20}];
    XLSX.utils.book_append_sheet(wb, ws1, 'الإحصائيات');
    const ws2 = XLSX.utils.aoa_to_sheet(monthly);
    ws2['!cols'] = [{wch:15},{wch:20}];
    XLSX.utils.book_append_sheet(wb, ws2, 'التسجيلات الشهرية');
    XLSX.writeFile(wb, 'qinwan_stats.xlsx');
}

// ✅ TC3: تصدير PDF
function exportPDF() {
    window.print();
}
</script>
</body>
</html>
