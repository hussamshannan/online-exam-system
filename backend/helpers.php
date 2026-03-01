<?php
// ─── CORS + JSON headers (include at the top of every endpoint) ──────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Response helpers ────────────────────────────────────────────────────────
function ok($data = null, string $msg = ''): void {
    echo json_encode(
        ['success' => true,  'data' => $data, 'message' => $msg],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(
        ['success' => false, 'data' => null, 'message' => $msg],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

// ─── Request helpers ─────────────────────────────────────────────────────────
function body(): array {
    static $parsed = null;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = json_decode($raw, true) ?? [];
    }
    return $parsed;
}

function bearerToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($h, 'Bearer ') === 0) {
        return substr($h, 7);
    }
    return null;
}

// ─── Auth helper ─────────────────────────────────────────────────────────────
function authUser(PDO $db, ?string $role = null): array {
    $token = bearerToken();
    if (!$token) fail('غير مصرح: يرجى تسجيل الدخول', 401);

    $stmt = $db->prepare('
        SELECT u.id, u.name, u.email, u.role,
               u.roll_number, u.college, u.department, u.batch, u.status
        FROM   auth_tokens t
        JOIN   users u ON u.id = t.user_id
        WHERE  t.token = ? AND t.expires_at > NOW()
    ');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user)             fail('انتهت الجلسة، يرجى تسجيل الدخول مجدداً', 401);
    if ($user['status'] !== 'active') fail('الحساب معطّل', 403);
    if ($role && $user['role'] !== $role) fail('صلاحيات غير كافية', 403);

    return $user;
}
