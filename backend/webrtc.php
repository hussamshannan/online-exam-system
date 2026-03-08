<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db  = getDB();
$dir = __DIR__ . '/webrtc/';

// ── POST: store signaling data ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user      = authUser($db);
    $b         = body();
    $action    = $b['action']     ?? '';
    $sessionId = intval($b['session_id'] ?? 0);

    if (!$sessionId) fail('session_id مطلوب');

    // offer: student sends SDP offer (overwrite to reset negotiation)
    if ($action === 'offer') {
        if ($user['role'] !== 'student') fail('مسموح للطلاب فقط', 403);
        // Reset any previous negotiation for this session
        @unlink($dir . $sessionId . '_answer.json');
        @unlink($dir . $sessionId . '_ice_supervisor.json');
        @unlink($dir . $sessionId . '_ice_student.json');
        file_put_contents($dir . $sessionId . '_offer.json', json_encode($b['sdp'], JSON_UNESCAPED_UNICODE));
        ok(null, 'تم');
    }

    // answer: supervisor sends SDP answer
    if ($action === 'answer') {
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);
        file_put_contents($dir . $sessionId . '_answer.json', json_encode($b['sdp'], JSON_UNESCAPED_UNICODE));
        ok(null, 'تم');
    }

    // ice_student / ice_supervisor: append single ICE candidate
    if ($action === 'ice_student' || $action === 'ice_supervisor') {
        if ($action === 'ice_student' && $user['role'] !== 'student') fail('مسموح للطلاب فقط', 403);
        if ($action === 'ice_supervisor' && !in_array($user['role'], ['admin', 'teacher'])) {
            fail('صلاحيات غير كافية', 403);
        }
        $file     = $dir . $sessionId . '_' . $action . '.json';
        $existing = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $existing[] = $b['candidate'];
        file_put_contents($file, json_encode($existing, JSON_UNESCAPED_UNICODE));
        ok(null, 'تم');
    }

    // reset: student cleans up on exam submit
    if ($action === 'reset') {
        foreach (['_offer', '_answer', '_ice_student', '_ice_supervisor'] as $suffix) {
            @unlink($dir . $sessionId . $suffix . '.json');
        }
        ok(null, 'تم');
    }

    fail('action غير معروف');
}

// ── GET: retrieve signaling data ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user      = authUser($db);
    $action    = $_GET['action']     ?? '';
    $sessionId = intval($_GET['session_id'] ?? 0);
    $since     = intval($_GET['since'] ?? 0);

    if (!$sessionId) fail('session_id مطلوب');

    if ($action === 'offer') {
        $file = $dir . $sessionId . '_offer.json';
        if (!file_exists($file)) ok(null, 'لا يوجد offer بعد');
        ok(json_decode(file_get_contents($file)));
    }

    if ($action === 'answer') {
        $file = $dir . $sessionId . '_answer.json';
        if (!file_exists($file)) ok(null, 'لا يوجد answer بعد');
        ok(json_decode(file_get_contents($file)));
    }

    if ($action === 'ice_student' || $action === 'ice_supervisor') {
        $file = $dir . $sessionId . '_' . $action . '.json';
        if (!file_exists($file)) ok([]);
        $all = json_decode(file_get_contents($file), true) ?? [];
        ok(array_slice($all, $since));
    }

    fail('action غير معروف');
}

fail('طريقة غير مدعومة', 405);
