<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET  →  all sessions with scores + suspicious-event counts ──────────────
if ($method === 'GET') {
    authUser($db, 'admin');

    $rows = $db->query('
        SELECT
            s.id,
            s.student_name,
            s.student_email,
            s.roll_number,
            s.score,
            s.total_marks,
            s.percentage,
            s.started_at,
            s.submitted_at,
            e.subject,
            e.college,
            COUNT(al.id) AS log_count,
            SUM(
                CASE WHEN al.message LIKE "%blocked%"
                       OR al.message LIKE "%blur%"
                       OR al.message LIKE "%visibility%"
                     THEN 1 ELSE 0 END
            ) AS suspicious_count
        FROM   exam_sessions s
        JOIN   exams e  ON e.id  = s.exam_id
        LEFT JOIN activity_logs al ON al.session_id = s.id
        GROUP  BY s.id
        ORDER  BY s.started_at DESC
    ')->fetchAll();

    ok($rows);
}

// ─── DELETE  →  wipe all session logs (admin) ────────────────────────────────
if ($method === 'DELETE') {
    authUser($db, 'admin');
    $db->exec('DELETE FROM activity_logs');
    $db->exec('DELETE FROM student_answers');
    $db->exec('DELETE FROM exam_sessions');
    ok(null, 'تم مسح جميع السجلات');
}

fail('طلب غير صالح');
