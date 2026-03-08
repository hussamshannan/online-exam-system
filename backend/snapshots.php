<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db = getDB();

// ── POST: student uploads a snapshot ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = authUser($db); // any logged-in user
    if ($user['role'] !== 'student') fail('مسموح للطلاب فقط', 403);

    $b = body();
    $sessionId = intval($b['session_id'] ?? 0);
    $imageData  = $b['image'] ?? ''; // base64 data URI: "data:image/jpeg;base64,..."

    if (!$sessionId || !$imageData) fail('بيانات ناقصة');

    // Verify this session belongs to this student
    $stmt = $db->prepare('SELECT id FROM exam_sessions WHERE id = ? AND student_id = ? AND submitted_at IS NULL');
    $stmt->execute([$sessionId, $user['id']]);
    if (!$stmt->fetch()) fail('جلسة غير صالحة', 403);

    // Strip data URI prefix and decode
    if (strpos($imageData, ',') !== false) {
        $imageData = explode(',', $imageData, 2)[1];
    }
    $binary = base64_decode($imageData);
    if (!$binary || strlen($binary) < 100) fail('صورة غير صالحة');

    $dir  = __DIR__ . '/snapshots/';
    $file = $dir . $sessionId . '.jpg';
    if (file_put_contents($file, $binary) === false) {
        fail('فشل حفظ الصورة', 500);
    }

    ok(null, 'تم');
}

// ── GET: supervisor/admin/teacher fetches latest snapshot ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = authUser($db);
    if (!in_array($user['role'], ['admin', 'teacher'])) {
        fail('صلاحيات غير كافية', 403);
    }

    $sessionId = intval($_GET['session_id'] ?? 0);
    if (!$sessionId) fail('session_id مطلوب');

    $file = __DIR__ . '/snapshots/' . $sessionId . '.jpg';
    if (!file_exists($file)) {
        ok(null, 'لا توجد لقطة بعد');
    }

    $binary  = file_get_contents($file);
    $mtime   = filemtime($file);
    $b64     = base64_encode($binary);
    ok(['image' => 'data:image/jpeg;base64,' . $b64, 'timestamp' => $mtime]);
}

fail('طريقة غير مدعومة', 405);
