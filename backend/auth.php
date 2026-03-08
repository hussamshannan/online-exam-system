<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET /auth.php  →  return current user from token ────────────────────────
if ($method === 'GET') {
    $user = authUser($db);
    ok($user);
}

// ─── POST /auth.php ───────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? '';

    // ── login ──────────────────────────────────────────────────────────────────
    if ($action === 'login') {
        $role = trim($b['role'] ?? '');
        $name = trim($b['name'] ?? '');
        $pass =       $b['password'] ?? '';

        if (!$role || !$name || !$pass) fail('جميع الحقول مطلوبة');

        // Map Arabic role names to DB enum values
        $roleMap = ['مشرف' => 'admin', 'معلم' => 'teacher', 'طالب' => 'student'];
        $dbRole  = $roleMap[$role] ?? null;
        if (!$dbRole) fail('نوع المستخدم غير صحيح');

        if ($dbRole === 'admin' || $dbRole === 'teacher') {
            // Single account per role — match by role only, verify password
            $stmt = $db->prepare(
                'SELECT * FROM users WHERE role = ? AND status = "active" LIMIT 1'
            );
            $stmt->execute([$dbRole]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['password_hash'])) {
                fail('كلمة المرور غير صحيحة');
            }
            // Use the display name the user typed, not the seeded DB name
            $user['name'] = $name;

        } else {
            // Student: match by name + password (names should be unique)
            $stmt = $db->prepare(
                'SELECT * FROM users WHERE role = "student" AND name = ? AND status = "active"'
            );
            $stmt->execute([$name]);
            $candidates = $stmt->fetchAll();

            $user = null;
            foreach ($candidates as $c) {
                if (password_verify($pass, $c['password_hash'])) {
                    $user = $c;
                    break;
                }
            }
            if (!$user) fail('الاسم أو كلمة المرور غير صحيحة');
        }

        // Issue token
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+' . TOKEN_TTL_HOURS . ' hours'));
        $db->prepare('INSERT INTO auth_tokens (user_id, token, expires_at) VALUES (?,?,?)')
           ->execute([$user['id'], $token, $expires]);

        unset($user['password_hash']);
        ok(['token' => $token, 'user' => $user], 'تم تسجيل الدخول');
    }

    // ── logout ─────────────────────────────────────────────────────────────────
    if ($action === 'logout') {
        $token = bearerToken();
        if ($token) {
            $db->prepare('DELETE FROM auth_tokens WHERE token = ?')->execute([$token]);
        }
        ok(null, 'تم تسجيل الخروج');
    }
}

fail('طلب غير صالح');
