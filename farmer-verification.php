<?php
// ============================================================
// قِنوان — transactions-monitor.php  (US-Admin-05)
// ============================================================
require_once 'db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { header('Location: login.php'); exit; }

$pdo = getDB();

// ✅ Query كاملة مع بيانات إضافية للـ modal
$transactions = $pdo->query("
    SELECT tx.transaction_id, tx.amount, tx.payment_status, tx.paid_at,
           ir.request_id, ir.area_sqm, ir.duration, ir.harvest_method,
           ir.req_status, ir.submitted_at, ir.delivery_address, ir.rejection_reason,
           f.name AS farm_name, f.region, f.palm_type,
           ui.first_name AS inv_first, ui.last_name AS inv_last,
           ui.email AS inv_email, ui.phone AS inv_phone,
           uf.first_name AS far_first, uf.last_name AS far_last,
           uf.email AS far_email, uf.phone AS far_phone
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
$durationMap = ['1_season'=>'موسم واحد','1_year'=>'سنة واحدة','2_years'=>'سنتان'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مراقبة المعاملات - قنوان</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ✅ Modal التفاصيل */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; }
        .modal-overlay.active { display:flex; }
        .modal-box { background:#fff; border-radius:16px; padding:28px 32px; max-width:600px; width:95%; max-height:85vh; overflow-y:auto; direction:rtl; }
        .modal-box h3 { font-size:1.2rem; font-weight:700; margin-bottom:20px; color:#1a1a1a; border-bottom:2px solid #f3f4f6; padding-bottom:12px; }
        .detail-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f3f4f6; font-size:0.95rem; }
        .detail-row:last-child { border-bottom:none; }
        .detail-label { color:#6b7280; font-weight:600; }
        .detail-value { color:#1a1a1a; text-align:left; }
        .suspicious-banner { background:#fff8e1; border:2px solid #f59e0b; border-radius:10px; padding:12px 16px; margin-bottom:16px; color:#92400e; font-weight:600; }
        .btn-close { background:#6b7280; color:#fff; border:none; border-radius:8px; padding:10px 24px; font-size:0.95rem; cursor:pointer; margin-top:20px; width:100%; }
        .btn-details { background:#3b82f6; color:#fff; border:none; border-radius:6px; padding:5px 12px; font-size:12px; cursor:pointer; white-space:nowrap; }
        .btn-details:hover { background:#2563eb; }
    </style>
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
        <!-- ✅ مسار الصورة حسب نسختها -->
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>مراقبة المعاملات</h2>
        <p>عرض جميع المعاملات الاستثمارية والتحقق من سلامتها المالية. المعاملات المشبوهة مُعلَّمة تلقائياً.</p>
    </section>

    <!-- ✅ TC3: تنبيه المشبوهة في الأعلى -->
    <?php $suspicious = array_filter($transactions, fn($t) => $t['amount'] > 100000); ?>
    <?php if (!empty($suspicious)): ?>
        <div class="suspicious-banner">
            ⚠️ تنبيه: يوجد <?= count($suspicious) ?> معاملة مشبوهة (مبلغ يتجاوز 100,000 ر.س) — راجعها أدناه
        </div>
    <?php endif; ?>

    <!-- إحصائيات -->
    <div class="admin-stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:24px;">
        <div class="admin-stat-card"><h3>إجمالي المعاملات</h3><p><?= count($transactions) ?></p></div>
        <div class="admin-stat-card"><h3>مجموع المدفوع (ر.س)</h3><p><?= number_format(array_sum(array_column(array_filter($transactions, fn($t)=>$t['payment_status']==='paid'), 'amount')), 0) ?></p></div>
        <div class="admin-stat-card"><h3>معلقة</h3><p><?= count(array_filter($transactions, fn($t)=>$t['payment_status']==='pending')) ?></p></div>
        <div class="admin-stat-card"><h3>مشبوهة</h3><p style="color:#c8922a;"><?= count($suspicious) ?></p></div>
    </div>

    <!-- ✅ TC2: Filters -->
    <section class="admin-toolbar">
        <input type="text"  id="txSearch"     placeholder="ابحث بالاسم أو المزرعة" oninput="filterTx()">
        <select id="txStatusFilter" onchange="filterTx()">
            <option value="all">كل الحالات</option>
            <option value="pending">معلقة</option>
            <option value="paid">مدفوعة</option>
            <option value="failed">فاشلة</option>
            <option value="refunded">مستردة</option>
        </select>
        <input type="date" id="txDateFrom" oninput="filterTx()">
        <input type="date" id="txDateTo"   oninput="filterTx()">
    </section>

    <!-- ✅ TC1: جدول مع زر التفاصيل -->
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
                    <th>التفاصيل</th>
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
                    <td><?= $durationMap[$tx['duration']] ?? $tx['duration'] ?></td>
                    <td><?= $harvestMap[$tx['harvest_method']] ?? $tx['harvest_method'] ?></td>
                    <td style="font-weight:700;color:var(--green-dark);"><?= number_format($tx['amount'], 2) ?></td>
                    <td><span class="status-badge <?= $statusClass[$tx['payment_status']] ?? '' ?>"><?= $statusLabel[$tx['payment_status']] ?? $tx['payment_status'] ?></span></td>
                    <td style="font-size:12px;white-space:nowrap;"><?= $tx['paid_at'] ? date('Y-m-d', strtotime($tx['paid_at'])) : date('Y-m-d', strtotime($tx['submitted_at'])) ?></td>
                    <td><?= $isSuspicious ? '<span style="color:#c8922a;font-size:18px;" title="مبلغ مرتفع">⚠️</span>' : '<span style="color:var(--green-mid);">✓</span>' ?></td>
                    <!-- ✅ TC1: زر التفاصيل الكاملة -->
                    <td>
                        <button class="btn-details" onclick="showDetails(<?= $tx['transaction_id'] ?>)">🔍 تفاصيل</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="12" style="text-align:center;color:var(--text-faint);padding:32px;">لا توجد معاملات بعد.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>

<!-- ✅ TC1: Modal التفاصيل الكاملة -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-box">
        <h3>🔍 تفاصيل المعاملة</h3>
        <div id="modalContent"></div>
        <button class="btn-close" onclick="closeModal()">✖ إغلاق</button>
    </div>
</div>

<script>
const txData = <?= json_encode(array_combine(
    array_column($transactions, 'transaction_id'),
    $transactions
), JSON_UNESCAPED_UNICODE) ?>;

const harvestMap  = <?= json_encode($harvestMap,  JSON_UNESCAPED_UNICODE) ?>;
const durationMap = <?= json_encode($durationMap, JSON_UNESCAPED_UNICODE) ?>;
const statusLabel = <?= json_encode($statusLabel, JSON_UNESCAPED_UNICODE) ?>;

function showDetails(id) {
    const tx = txData[id];
    if (!tx) return;
    const isSuspicious = parseFloat(tx.amount) > 100000;
    let html = '';
    if (isSuspicious) html += `<div class="suspicious-banner">⚠️ معاملة مشبوهة — المبلغ يتجاوز 100,000 ر.س</div>`;
    html += `
        <div class="detail-row"><span class="detail-label">رقم المعاملة</span><span class="detail-value">#${tx.transaction_id}</span></div>
        <div class="detail-row"><span class="detail-label">المستثمر</span><span class="detail-value">${tx.inv_first} ${tx.inv_last}</span></div>
        <div class="detail-row"><span class="detail-label">بريد المستثمر</span><span class="detail-value">${tx.inv_email}</span></div>
        <div class="detail-row"><span class="detail-label">هاتف المستثمر</span><span class="detail-value">${tx.inv_phone}</span></div>
        <div class="detail-row"><span class="detail-label">المزارع</span><span class="detail-value">${tx.far_first} ${tx.far_last}</span></div>
        <div class="detail-row"><span class="detail-label">بريد المزارع</span><span class="detail-value">${tx.far_email}</span></div>
        <div class="detail-row"><span class="detail-label">هاتف المزارع</span><span class="detail-value">${tx.far_phone}</span></div>
        <div class="detail-row"><span class="detail-label">المزرعة</span><span class="detail-value">${tx.farm_name}</span></div>
        <div class="detail-row"><span class="detail-label">المنطقة</span><span class="detail-value">${tx.region}</span></div>
        <div class="detail-row"><span class="detail-label">نوع النخيل</span><span class="detail-value">${tx.palm_type}</span></div>
        <div class="detail-row"><span class="detail-label">المساحة</span><span class="detail-value">${Number(tx.area_sqm).toLocaleString()} م²</span></div>
        <div class="detail-row"><span class="detail-label">مدة الاستثمار</span><span class="detail-value">${durationMap[tx.duration] ?? tx.duration}</span></div>
        <div class="detail-row"><span class="detail-label">طريقة الحصاد</span><span class="detail-value">${harvestMap[tx.harvest_method] ?? tx.harvest_method}</span></div>
        ${tx.delivery_address ? `<div class="detail-row"><span class="detail-label">عنوان التوصيل</span><span class="detail-value">${tx.delivery_address}</span></div>` : ''}
        <div class="detail-row"><span class="detail-label">المبلغ</span><span class="detail-value" style="font-weight:700;color:green;">${Number(tx.amount).toLocaleString()} ر.س</span></div>
        <div class="detail-row"><span class="detail-label">حالة الدفع</span><span class="detail-value">${statusLabel[tx.payment_status] ?? tx.payment_status}</span></div>
        <div class="detail-row"><span class="detail-label">تاريخ الطلب</span><span class="detail-value">${tx.submitted_at ? tx.submitted_at.substring(0,10) : '—'}</span></div>
        <div class="detail-row"><span class="detail-label">تاريخ الدفع</span><span class="detail-value">${tx.paid_at ? tx.paid_at.substring(0,10) : '—'}</span></div>
        ${tx.rejection_reason ? `<div class="detail-row"><span class="detail-label">سبب الرفض</span><span class="detail-value">${tx.rejection_reason}</span></div>` : ''}
    `;
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('detailModal').classList.add('active');
}

function closeModal() {
    document.getElementById('detailModal').classList.remove('active');
}

document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

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
</html>            WHERE fr.farmer_id = ?
        ");
        $farmerUser->execute([$farmer_id]);
        $farmerUserId = $farmerUser->fetchColumn();

        if ($farmerUserId) {
            if ($decision === 'verified') {
                $title   = 'تم توثيق حسابك ✅';
                $message = 'تهانينا! تم اعتماد حسابك كمزارع. يمكنك الآن إضافة مزرعتك على المنصة.';
            } else {
                $title   = 'تم رفض طلب التوثيق ❌';
                $message = 'عذراً، تم رفض طلب توثيق حسابك. السبب: ' . $note;
            }
            try {
                $pdo->prepare("
                    INSERT INTO qw_notification (user_id, notif_type, title, message, entity_type, entity_id, created_at)
                    VALUES (?, 'verification', ?, ?, 'farmer', ?, NOW())
                ")->execute([$farmerUserId, $title, $message, $farmer_id]);
            } catch(Exception $e) {}
        }
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
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>توثيق المزارعين - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"               class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"     class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"     class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link active">التوثيق</a>
        <a href="logout.php"              class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>توثيق حسابات المزارعين</h2>
        <p>مراجعة الهوية الوطنية ومستندات ملكية المزرعة، ثم اعتماد أو رفض الطلب.</p>
    </section>

    <!-- ✅ رسالة خطأ لو الرفض بدون ملاحظة -->
    <?php if (isset($_GET['error']) && $_GET['error'] === 'note_required'): ?>
        <div style="background:#fee2e2;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-weight:600;">
            ⚠️ يجب كتابة سبب الرفض قبل المتابعة
        </div>
    <?php endif; ?>

    <section class="admin-toolbar">
        <input type="text" id="verifySearch" placeholder="ابحث باسم المزارع أو رقم الهوية" oninput="filterVerifications()">
        <select id="verifyStatusFilter" onchange="filterVerifications()">
            <option value="all">كل الحالات</option>
            <option value="pending">قيد المراجعة</option>
            <option value="verified">موثق</option>
            <option value="rejected">مرفوض</option>
        </select>
    </section>

    <section class="admin-cards-list" id="verificationList">
    <?php foreach ($farmers as $fr):
        $statusMap = ['pending'=>'قيد المراجعة','verified'=>'موثق','rejected'=>'مرفوض'];
    ?>
        <div class="admin-record-card"
             data-status="<?= $fr['verification_status'] ?>"
             data-name="<?= strtolower($fr['first_name'].' '.$fr['last_name'].' '.$fr['national_id']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3>👤 <?= htmlspecialchars($fr['first_name'].' '.$fr['last_name']) ?></h3>
                    <p>📧 <?= htmlspecialchars($fr['email']) ?></p>
                    <p>📞 <?= htmlspecialchars($fr['phone']) ?></p>
                    <p>📅 تاريخ التسجيل: <?= date('Y-m-d', strtotime($fr['created_at'])) ?></p>
                </div>
                <span class="status-badge status-<?= $fr['verification_status'] === 'verified' ? 'accepted' : ($fr['verification_status'] === 'pending' ? 'pending' : 'rejected') ?>">
                    <?= $statusMap[$fr['verification_status']] ?? $fr['verification_status'] ?>
                </span>
            </div>

            <!-- الهوية الوطنية -->
            <div class="verification-documents" style="margin-top:16px;">
                <div class="verification-doc-box">
                    <h4>🪪 رقم الهوية الوطنية</h4>
                    <div class="national-id-number"><?= htmlspecialchars($fr['national_id']) ?></div>
                </div>
                <?php if ($fr['verified_at']): ?>
                <div class="verification-doc-box">
                    <h4>📅 تاريخ مراجعة التوثيق</h4>
                    <div style="font-size:15px;color:var(--text-main);"><?= date('Y-m-d H:i', strtotime($fr['verified_at'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($fr['verification_status'] === 'pending'): ?>
            <div class="admin-card-actions">
                <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farmer_id" value="<?= $fr['farmer_id'] ?>">

                    <!-- ✅ الملاحظة إجبارية عند الرفض -->
                    <input type="text" name="note"
                           id="note_<?= $fr['farmer_id'] ?>"
                           placeholder="ملاحظة (إجبارية عند الرفض)"
                           class="form-input"
                           style="flex:2;min-width:200px;padding:8px 12px;margin:0;">

                    <button type="submit" name="decision" value="verified"
                            class="admin-action-btn btn-approve">✅ اعتماد</button>

                    <!-- ✅ التحقق من الملاحظة قبل الرفض -->
                    <button type="submit" name="decision" value="rejected"
                            class="admin-action-btn btn-danger"
                            onclick="
                                var note = document.getElementById('note_<?= $fr['farmer_id'] ?>').value.trim();
                                if(!note){ alert('يجب كتابة سبب الرفض أولاً'); return false; }
                                return confirm('تأكيد رفض التوثيق؟');
                            ">❌ رفض</button>
                </form>
            </div>

            <?php elseif ($fr['verification_status'] === 'verified'): ?>
                <!-- ✅ شارة التوثيق -->
                <div style="margin-top:12px;">
                    <span class="verified-badge">✅ تم التوثيق — الشارة المعتمدة نشطة على المنصة</span>
                </div>
            <?php else: ?>
                <div style="margin-top:12px;"><span class="flag-badge">❌ مرفوض</span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($farmers)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا يوجد مزارعون مسجلون بعد.</div>
    <?php endif; ?>
    </section>
</main>

<script>
function filterVerifications() {
    const q      = document.getElementById('verifySearch').value.toLowerCase();
    const status = document.getElementById('verifyStatusFilter').value;
    document.querySelectorAll('#verificationList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>    <title>توثيق المزارعين - قنوان</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<nav>
    <a href="admin.php" class="nav-back">← لوحة الإدارة</a>
    <div class="nav-links">
        <a href="admin.php"               class="nav-link">لوحة الإدارة</a>
        <a href="users-managment.php"     class="nav-link">المستخدمون</a>
        <a href="farms-managment.php"     class="nav-link">المزارع</a>
        <a href="farmer-verification.php" class="nav-link active">التوثيق</a>
        <a href="logout.php"              class="nav-link nav-logout">خروج 🚪</a>
    </div>
    <div class="nav-logo" onclick="window.location.href='admin.php'">
        <img class="logo-img" src="images\logo.png" alt="قنوان" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"/>
        <div class="logo-fallback" style="display:none">ق</div>
        <div><span class="logo-name">قنوان</span><span class="logo-sub">الإدارة</span></div>
    </div>
</nav>

<main class="admin-page admin-rtl">
    <section class="admin-heading">
        <h2>توثيق حسابات المزارعين</h2>
        <p>مراجعة الهوية الوطنية ومستندات ملكية المزرعة، ثم اعتماد أو رفض الطلب.</p>
    </section>

    <section class="admin-toolbar">
        <input type="text" id="verifySearch" placeholder="ابحث باسم المزارع أو رقم الهوية" oninput="filterVerifications()">
        <select id="verifyStatusFilter" onchange="filterVerifications()">
            <option value="all">كل الحالات</option>
            <option value="pending">قيد المراجعة</option>
            <option value="verified">موثق</option>
            <option value="rejected">مرفوض</option>
        </select>
    </section>

    <section class="admin-cards-list" id="verificationList">
    <?php foreach ($farmers as $fr):
        $statusMap = ['pending'=>'قيد المراجعة','verified'=>'موثق','rejected'=>'مرفوض'];
    ?>
        <div class="admin-record-card"
             data-status="<?= $fr['verification_status'] ?>"
             data-name="<?= strtolower($fr['first_name'].' '.$fr['last_name'].' '.$fr['national_id']) ?>">

            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                <div>
                    <h3>👤 <?= htmlspecialchars($fr['first_name'].' '.$fr['last_name']) ?></h3>
                    <p>📧 <?= htmlspecialchars($fr['email']) ?></p>
                    <p>📞 <?= htmlspecialchars($fr['phone']) ?></p>
                    <p>📅 تاريخ التسجيل: <?= date('Y-m-d', strtotime($fr['created_at'])) ?></p>
                </div>
                <span class="status-badge status-<?= $fr['verification_status'] === 'verified' ? 'accepted' : ($fr['verification_status'] === 'pending' ? 'pending' : 'rejected') ?>">
                    <?= $statusMap[$fr['verification_status']] ?? $fr['verification_status'] ?>
                </span>
            </div>

            <!-- الهوية الوطنية -->
            <div class="verification-documents" style="margin-top:16px;">
                <div class="verification-doc-box">
                    <h4>🪪 رقم الهوية الوطنية</h4>
                    <div class="national-id-number"><?= htmlspecialchars($fr['national_id']) ?></div>
                </div>
                <?php if ($fr['verified_at']): ?>
                <div class="verification-doc-box">
                    <h4>📅 تاريخ مراجعة التوثيق</h4>
                    <div style="font-size:15px;color:var(--text-main);"><?= date('Y-m-d H:i', strtotime($fr['verified_at'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($fr['verification_status'] === 'pending'): ?>
            <div class="admin-card-actions">
                <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="farmer_id" value="<?= $fr['farmer_id'] ?>">
                    <input type="text" name="note" placeholder="ملاحظة (اختياري)" class="form-input" style="flex:2;min-width:200px;padding:8px 12px;margin:0;">
                    <button type="submit" name="decision" value="verified"  class="admin-action-btn btn-approve">✅ اعتماد</button>
                    <button type="submit" name="decision" value="rejected"  class="admin-action-btn btn-danger"  onclick="return confirm('تأكيد رفض التوثيق؟')">❌ رفض</button>
                </form>
            </div>
            <?php elseif ($fr['verification_status'] === 'verified'): ?>
                <div style="margin-top:12px;"><span class="verified-badge">✅ تم التوثيق — الشارة المعتمدة نشطة على المنصة</span></div>
            <?php else: ?>
                <div style="margin-top:12px;"><span class="flag-badge">❌ مرفوض</span></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (empty($farmers)): ?>
        <div style="text-align:center;padding:48px;color:var(--text-faint);">لا يوجد مزارعون مسجلون بعد.</div>
    <?php endif; ?>
    </section>
</main>

<script>
function filterVerifications() {
    const q      = document.getElementById('verifySearch').value.toLowerCase();
    const status = document.getElementById('verifyStatusFilter').value;
    document.querySelectorAll('#verificationList .admin-record-card').forEach(card => {
        const matchName   = card.dataset.name.includes(q);
        const matchStatus = (status === 'all' || card.dataset.status === status);
        card.style.display = (matchName && matchStatus) ? '' : 'none';
    });
}
</script>
</body>
</html>
