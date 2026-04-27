<?php
// ============================================================
// قِنوان — notifications.php  (Twilio Alphanumeric Sender ID)
// الحل: From = 'Qinwan' (اسم) بدل رقم أمريكي لا يدعم +966
// ============================================================

if (file_exists(__DIR__ . '/twilio_config.php')) {
    require_once __DIR__ . '/twilio_config.php';
}

function createNotification(PDO $pdo, int $user_id, string $type, string $title, string $message, ?int $entity_id = null, string $entity_type = ''): int {
    try {
        $stmt = $pdo->prepare("INSERT INTO qw_notification (user_id, notif_type, title, message, entity_type, entity_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $type, $title, $message, $entity_type ?: null, $entity_id]);
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        error_log('[Qinwan] createNotification: ' . $e->getMessage());
        return 0;
    }
}

function _sendTwilioSMS(string $to, string $body): bool {
    if (!defined('TWILIO_ACCOUNT_SID') || TWILIO_ACCOUNT_SID === 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx') {
        error_log('[Qinwan] Twilio: ضع بياناتك الحقيقية في twilio_config.php');
        return false;
    }
    if (!function_exists('curl_init')) {
        error_log('[Qinwan] Twilio: cURL معطّل — فعّله من php.ini');
        return false;
    }

    // توحيد الرقم السعودي
    $to = preg_replace('/[^\d+]/', '', $to);
    if (substr($to, 0, 1) !== '+') {
        if (substr($to, 0, 2) === '05') {
            $to = '+966' . substr($to, 1);
        } elseif (substr($to, 0, 1) === '5' && strlen($to) === 9) {
            $to = '+966' . $to;
        } elseif (substr($to, 0, 3) === '966') {
            $to = '+' . $to;
        } else {
            $to = '+' . $to;
        }
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';

    // ── Alphanumeric Sender ID بدل رقم أمريكي ────────────────
    // 'Qinwan' = اسم المرسل يظهر للمستقبل بدل رقم
    // يدعم السعودية بدون تسجيل للحسابات العادية (non-trial)
    // للحساب trial: استخدم رقمك الأمريكي وغيّر To لرقم أمريكي محقق
    $fromSender = defined('TWILIO_FROM_NUMBER') ? TWILIO_FROM_NUMBER : 'Qinwan';
    
    $data = http_build_query([
        'From' => $fromSender,
        'To'   => $to,
        'Body' => $body,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('[Qinwan] Twilio cURL: ' . $curlErr);
        return false;
    }

    $result  = json_decode($response, true);
    $errCode = $result['code']    ?? 0;
    $errMsg  = $result['message'] ?? '';

    if ($httpCode === 201 && isset($result['sid'])) {
        error_log('[Qinwan] ✅ SMS → SID:' . $result['sid'] . ' To:' . $to);
        return true;
    }

    error_log('[Qinwan] ❌ Twilio HTTP=' . $httpCode . ' errCode=' . $errCode . ' msg=' . $errMsg);

    // شرح أسباب الأخطاء الشائعة
    $hints = [
        21612 => 'رقم الـ From لا يدعم السعودية — غيّر TWILIO_FROM_NUMBER إلى Qinwan في twilio_config.php',
        21608 => 'الرقم غير مضاف في Verified Caller IDs — أضفه من console.twilio.com',
        20003 => 'SID أو Auth Token خاطئ في twilio_config.php',
        21211 => 'صيغة رقم المستلم خاطئة — يجب +966XXXXXXXXX',
        21614 => 'الرقم لا يدعم SMS — جرّب رقماً آخر',
        30006 => 'الرقم محظور من استقبال SMS',
        30007 => 'تم تصفية الرسالة من شبكة الاتصالات',
        21219 => 'حساب trial لا يدعم Alphanumeric — ترقّ للحساب العادي',
    ];
    if (isset($hints[$errCode])) {
        error_log('[Qinwan] 💡 الحل: ' . $hints[$errCode]);
    }
    return false;
}

function _logNotification(PDO $pdo, int $investor_id, string $channel, string $phone, string $message, string $status): void {
    try {
        $pdo->prepare("INSERT INTO qw_notification_log (investor_id, channel, phone_number, message, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$investor_id, $channel, $phone, $message, $status]);
    } catch (Exception $e) {}
}

function sendWhatsAppNotification(PDO $pdo, int $investor_id, string $message): string {
    try {
        $stmt = $pdo->prepare("SELECT i.whatsapp_number, i.whatsapp_enabled, i.whatsapp_verified, u.phone FROM qw_investor i JOIN qw_user u ON i.user_id=u.user_id WHERE i.investor_id=?");
        $stmt->execute([$investor_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv || empty($inv['whatsapp_enabled'])) return 'none';

        if (!empty($inv['whatsapp_number']) && !empty($inv['whatsapp_verified'])) {
            $sent = _sendTwilioSMS($inv['whatsapp_number'], $message);
            _logNotification($pdo, $investor_id, 'whatsapp', $inv['whatsapp_number'], $message, $sent ? 'delivered' : 'failed');
            return $sent ? 'whatsapp' : 'failed';
        }
        if (!empty($inv['phone'])) {
            $sent = _sendTwilioSMS($inv['phone'], $message);
            _logNotification($pdo, $investor_id, 'sms', $inv['phone'], $message, $sent ? 'delivered' : 'failed');
            return $sent ? 'sms' : 'failed';
        }
    } catch (Exception $e) {
        error_log('[Qinwan] sendWhatsApp: ' . $e->getMessage());
    }
    return 'none';
}

function notifyInvestorsOfFarmUpdate(PDO $pdo, int $farm_id, string $farm_name, int $update_id): void {
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT i.investor_id, u.user_id FROM qw_investment_request ir JOIN qw_farm_offer fo ON ir.offer_id=fo.offer_id JOIN qw_investor i ON ir.investor_id=i.investor_id JOIN qw_user u ON i.user_id=u.user_id WHERE fo.farm_id=? AND ir.req_status='accepted'");
        $stmt->execute([$farm_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            createNotification($pdo, $inv['user_id'], 'farm_update', "تحديث جديد من {$farm_name}", "نشر المزارع تحديثاً جديداً على مزرعة {$farm_name}.", $update_id, 'farm_update');
            sendWhatsAppNotification($pdo, $inv['investor_id'], "قِنوان: تحديث جديد من مزرعة {$farm_name}. سجّل دخولك لعرض التفاصيل.");
        }
    } catch (Exception $e) { error_log('[Qinwan] notifyFarmUpdate: ' . $e->getMessage()); }
}

function notifyHarvestChangeOutcome(PDO $pdo, int $investor_id, int $user_id, string $farm_name, string $decision): void {
    $ar  = $decision === 'approved' ? 'تمت الموافقة على' : 'تم رفض';
    $msg = "{$ar} طلب تغيير طريقة الحصاد لمزرعة {$farm_name}.";
    createNotification($pdo, $user_id, 'harvest_change_' . $decision, "قرار تغيير الحصاد", $msg, null, 'harvest_change');
    sendWhatsAppNotification($pdo, $investor_id, "قِنوان: {$msg}");
}

function notifyInvestmentDecision(PDO $pdo, int $investor_id, int $user_id, string $farm_name, string $decision): void {
    $ar  = $decision === 'accepted' ? 'قبل المزارع' : 'رفض المزارع';
    $msg = "{$ar} طلب استثمارك في مزرعة {$farm_name}.";
    createNotification($pdo, $user_id, 'request_' . $decision, "قرار طلب الاستثمار", $msg, null, 'investment_request');
    sendWhatsAppNotification($pdo, $investor_id, "قِنوان: {$msg}");
}

function notifyFarmerOfHarvestChangeRequest(PDO $pdo, int $farmer_user_id, string $farm_name, string $inv_name): void {
    createNotification($pdo, $farmer_user_id, 'harvest_change_request', "طلب تغيير طريقة الحصاد", "المستثمر {$inv_name} يطلب تغيير طريقة الحصاد في مزرعة {$farm_name}.", null, 'harvest_change');
}