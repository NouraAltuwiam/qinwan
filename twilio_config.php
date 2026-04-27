<?php
// ============================================================
// twilio_config.php — Twilio API credentials
// لا ترفع هذا الملف على GitHub
// ============================================================

define('TWILIO_ACCOUNT_SID', 'ACcab28c588ee84fcffb1223eea5250609'); // ← Account SID
define('TWILIO_AUTH_TOKEN',  '060066e9ea9002363246ba62e493f59a');               // ← Auth Token

// ── المرسل ──────────────────────────────────────────────────
// الخيار 1 (للحسابات العادية - يدعم +966):
define('TWILIO_FROM_NUMBER', 'Qinwan');

// الخيار 2 (trial - لا يدعم +966):
// define('TWILIO_FROM_NUMBER', '+19786620852');