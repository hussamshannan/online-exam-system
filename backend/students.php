<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET  →  list all students (admin only) ───────────────────────────────────
if ($method === 'GET') {
    authUser($db, 'admin');
    $rows = $db->query(
        'SELECT id, name, email, roll_number, college, department, batch, status, created_at
         FROM   users
         WHERE  role = "student"
         ORDER  BY created_at DESC'
    )->fetchAll();
    ok($rows);
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'create';

    // ── create ─────────────────────────────────────────────────────────────────
    if ($action === 'create') {
        authUser($db, 'admin');

        $name    = trim($b['name']     ?? '');
        $email   = trim($b['email']    ?? '');
        $pass    =      $b['password'] ?? '';
        $roll    = trim($b['roll']     ?? '');
        $college = trim($b['college']  ?? '');
        $batch   = trim($b['batch']    ?? '');

        if (!$name || !$email || !$pass || !$roll || !$college || !$batch) {
            fail('أكمل جميع الحقول المطلوبة');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('البريد الإلكتروني غير صحيح');
        }

        // Duplicate e-mail check
        $dup = $db->prepare('SELECT id FROM users WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetch()) fail('هذا البريد الإلكتروني مسجل بالفعل');

        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password_hash, role, roll_number, college, batch)
             VALUES (?, ?, ?, "student", ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, $roll, $college, $batch]);

        ok(['id' => (int)$db->lastInsertId()], 'تم إضافة الطالب ' . $name . ' بنجاح');
    }

    // ── delete ─────────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        authUser($db, 'admin');
        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الطالب مطلوب');

        $db->prepare('DELETE FROM users WHERE id = ? AND role = "student"')
           ->execute([$id]);
        ok(null, 'تم حذف الطالب');
    }

    // ── toggle_status ──────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        authUser($db, 'admin');
        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الطالب مطلوب');

        $db->prepare(
            'UPDATE users
             SET    status = IF(status = "active", "inactive", "active")
             WHERE  id = ? AND role = "student"'
        )->execute([$id]);
        ok(null, 'تم تحديث الحالة');
    }
}

fail('طلب غير صالح');
