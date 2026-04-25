<?php
// ============================================================
// قِنوان — transactions-monitor.php  (US-Admin-05)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

$transactions = $pdo->query("
    SELECT tx.transaction_id, tx.amount, tx.payment_status, tx.paid_at,
           ir.area_sqm, ir.duration, ir.harvest_method, ir.req_status, ir.submitted_at,
           f.name AS farm_name, f.region,
           ui.first_name AS inv_first, ui.last_name AS inv_last,
           uf.first_name AS far_first, uf.last_name AS far_last
    FROM qw_transaction tx
    JOIN qw_investment_request ir ON tx.request_id = ir.request_id
    JOIN qw_farm_offer fo  ON ir.offer_id   = fo.offer_id
    JOIN qw_farm f         ON fo.farm_id    = f.farm_id
    JOIN qw_farmer fr      ON f.farmer_id   = fr.farmer_id
    JOIN qw_user uf        ON fr.user_id    = uf.user_id
    JOIN qw_investor inv   ON ir.investor_id = inv.investor_id
    JOIN qw_user ui        ON inv.user_id   = ui.user_id
    ORDER BY tx.transaction_id DESC
")->fetchAll();

$statusLabel = ['pending'=>'معلقة','paid'=>'مدفوعة','failed'=>'فاشلة','refunded'=>'مستردة'];
$statusClass = ['pending'=>'status-pending','paid'=>'status-accepted','failed'=>'status-rejected','refunded'=>'status-rejected'];
$harvestMap  = ['receive'=>'استلام تمور','sell'=>'بيع المحصول','donate'=>'تبرع'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراقبة المعاملات - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"                class="nav-link">لوحة الإدارة</a>
        <a href="transactions-monitor.php" class="nav-link active">المعاملات</a>
        <a href="logout.php"               class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>مراقبة المعاملات</h2>
        <p>عرض جميع المعاملات الاستثمارية والتحقق من سلامتها المالية. المعاملات المشبوهة مُعلَّمة تلقائياً.</p>
    </section>

    <!-- Summary -->
    <div class="admin-stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
        <div class="admin-stat-card">
            <h3>إجمالي المعاملات</h3>
            <p><?= count($transactions) ?></p>
        </div>
        <div class="admin-stat-card">
            <h3>مجموع المدفوع (ر.س)</h3>
            <p><?= number_format(array_sum(array_column(array_filter($transactions, fn($t)=>$t['payment_status']==='paid'), 'amount')), 0) ?></p>
        </div>
        <div class="admin-stat-card">
            <h3>معلقة</h3>
            <p><?= count(array_filter($transactions, fn($t)=>$t['payment_status']==='pending')) ?></p>
        </div>
        <div class="admin-stat-card">
            <h3>مشبوهة (مبلغ عالٍ)</h3>
            <p><?= count(array_filter($transactions, fn($t)=>$t['amount']>100000)) ?></p>
        </div>
    </div>

    <section class="admin-toolbar">
        <input type="text"  id="txSearch"     placeholder="ابحث بالاسم أو المزرعة" oninput="filterTx()">
        <select id="txStatusFilter" onchange="filterTx()">
            <option value="all">كل الحالات</option>
            <option value="pending">معلقة</option>
            <option value="paid">مدفوعة</option>
            <option value="failed">فاشلة</option>
            <option value="refunded">مستردة</option>
        </select>
        <input type="date"  id="txDateFrom"   placeholder="من تاريخ" oninput="filterTx()">
        <input type="date"  id="txDateTo"     placeholder="إلى تاريخ" oninput="filterTx()">
    </section>

    <section class="admin-table-wrapper">
        <table class="admin-table" id="txTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>المستثمر</th>
                    <th>المزارع</th>
                    <th>المزرعة</th>
                    <th>المساحة</th>
                    <th>المدة</th>
                    <th>طريقة الحصاد</th>
                    <th>المبلغ (ر.س)</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                    <th>تنبيه</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($transactions as $tx):
                $isSuspicious = $tx['amount'] > 100000;
            ?>
                <tr data-status="<?= $tx['payment_status'] ?>"
                    data-name="<?= strtolower($tx['inv_first'].' '.$tx['inv_last'].' '.$tx['far_first'].' '.$tx['far_last'].' '.$tx['farm_name']) ?>"
                    data-date="<?= substr($tx['submitted_at'] ?? '', 0, 10) ?>"
                    style="<?= $isSuspicious ? 'background:#fff8e1;' : '' ?>">
                    <td><?= $tx['transaction_id'] ?></td>
                    <td><?= htmlspecialchars($tx['inv_first'].' '.$tx['inv_last']) ?></td>
                    <td><?= htmlspecialchars($tx['far_first'].' '.$tx['far_last']) ?></td>
                    <td><?= htmlspecialchars($tx['farm_name']) ?></td>
                    <td><?= number_format($tx['area_sqm'], 0) ?> م²</td>
                    <td><?= htmlspecialchars($tx['duration'] ?? '—') ?></td>
                    <td><?= $harvestMap[$tx['harvest_method']] ?? $tx['harvest_method'] ?></td>
                    <td style="font-weight:700;color:var(--green-dark);"><?= number_format($tx['amount'], 2) ?></td>
                    <td><span class="status-badge <?= $statusClass[$tx['payment_status']] ?? '' ?>"><?= $statusLabel[$tx['payment_status']] ?? $tx['payment_status'] ?></span></td>
                    <td style="font-size:12px;white-space:nowrap;"><?= $tx['paid_at'] ? date('Y-m-d', strtotime($tx['paid_at'])) : date('Y-m-d', strtotime($tx['submitted_at'])) ?></td>
                    <td><?= $isSuspicious ? '<span style="color:#c8922a;font-size:18px;" title="مبلغ مرتفع">⚠️</span>' : '<span style="color:var(--green-mid);">✓</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="11" style="text-align:center;color:var(--text-faint);padding:32px;">لا توجد معاملات بعد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

<script>
function filterTx() {
    const q      = document.getElementById('txSearch').value.toLowerCase();
    const status = document.getElementById('txStatusFilter').value;
    const from   = document.getElementById('txDateFrom').value;
    const to     = document.getElementById('txDateTo').value;

    document.querySelectorAll('#txTable tbody tr').forEach(row => {
        const matchName   = row.dataset.name.includes(q);
        const matchStatus = (status === 'all' || row.dataset.status === status);
        const rowDate     = row.dataset.date;
        const matchFrom   = !from || rowDate >= from;
        const matchTo     = !to   || rowDate <= to;
        row.style.display = (matchName && matchStatus && matchFrom && matchTo) ? '' : 'none';
    });
}
</script>
</body>
</html>