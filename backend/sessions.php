<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = authUser($db);

    if ($user['role'] === 'student') {
        // Return ALL submitted results for this student
        $stmt = $db->prepare('
            SELECT s.id, s.score, s.total_marks, s.percentage,
                   s.started_at, s.submitted_at, s.student_name,
                   s.cheating_fail, s.results_published,
                   e.subject, e.college, e.department, e.batch
            FROM   exam_sessions s
            JOIN   exams e ON e.id = s.exam_id
            WHERE  s.student_id = ? AND s.submitted_at IS NOT NULL
            ORDER  BY s.submitted_at DESC
        ');
        $stmt->execute([$user['id']]);
        ok($stmt->fetchAll());
    }

    // Teacher / supervisor / admin
    if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

    $sessionId = (int)($_GET['session_id'] ?? 0);
    $examId    = (int)($_GET['exam_id']    ?? 0);

    if ($sessionId) {
        // Return session detail with student answers (for grading)
        $stmt = $db->prepare('
            SELECT s.*, e.subject, e.college, e.department, e.batch
            FROM   exam_sessions s
            JOIN   exams e ON e.id = s.exam_id
            WHERE  s.id = ?
        ');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        if (!$session) fail('الجلسة غير موجودة', 404);

        $stmt = $db->prepare('
            SELECT sa.id, sa.answer, sa.is_correct, sa.marks_awarded,
                   q.id AS question_id, q.text, q.type, q.mark,
                   q.correct_answer, q.option_a, q.option_b, q.option_c, q.option_d
            FROM   student_answers sa
            JOIN   questions q ON q.id = sa.question_id
            WHERE  sa.session_id = ?
            ORDER  BY q.sort_order, q.id
        ');
        $stmt->execute([$sessionId]);
        $session['answers'] = $stmt->fetchAll();
        ok($session);
    }

    // All sessions (optionally filtered by exam)
    $where  = $examId ? 'WHERE s.exam_id = ?' : '';
    $params = $examId ? [$examId] : [];

    $stmt = $db->prepare("
        SELECT s.id, s.student_name, s.student_email, s.roll_number,
               s.score, s.total_marks, s.percentage,
               s.started_at, s.submitted_at,
               s.cheating_fail, s.results_published,
               e.subject, e.college, e.department, e.batch,
               COUNT(al.id) AS log_count,
               SUM(CASE WHEN al.message LIKE '%blocked%'
                          OR al.message LIKE '%blur%'
                          OR al.message LIKE '%visibility%'
                        THEN 1 ELSE 0 END) AS suspicious_count
        FROM   exam_sessions s
        JOIN   exams e ON e.id = s.exam_id
        LEFT JOIN activity_logs al ON al.session_id = s.id
        {$where}
        GROUP  BY s.id
        ORDER  BY s.started_at DESC
    ");
    $stmt->execute($params);
    ok($stmt->fetchAll());
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? '';

    // ── start — create a new exam session ──────────────────────────────────────
    if ($action === 'start') {
        $user   = authUser($db, 'student');
        $examId = (int)($b['exam_id'] ?? 0);
        if (!$examId) fail('معرّف الامتحان مطلوب');

        // Verify the exam is accessible for this student
        $stmt = $db->prepare('
            SELECT id FROM exams
            WHERE  id = ? AND is_active = 1 AND is_published = 1
              AND  college = ? AND batch = ?
              AND  (end_time IS NULL OR end_time > NOW())
        ');
        $stmt->execute([$examId, $user['college'], $user['batch']]);
        if (!$stmt->fetch()) fail('الامتحان غير متاح');

        // Prevent retaking: one attempt per student per exam (active or submitted)
        $stmt = $db->prepare('
            SELECT id FROM exam_sessions
            WHERE student_id = ? AND exam_id = ?
        ');
        $stmt->execute([$user['id'], $examId]);
        if ($stmt->fetch()) fail('لقد أديت هذا الامتحان مسبقاً ولا يمكنك إعادته');

        $stmt = $db->prepare('
            INSERT INTO exam_sessions
                (exam_id, student_id, student_name, student_email, roll_number)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $examId, $user['id'], $user['name'], $user['email'], $user['roll_number'],
        ]);
        ok(['session_id' => (int)$db->lastInsertId()]);
    }

    // ── log — append an activity entry ─────────────────────────────────────────
    if ($action === 'log') {
        $sessionId = (int)($b['session_id'] ?? 0);
        $message   = trim($b['message']    ?? '');
        if (!$sessionId || !$message) fail('بيانات ناقصة');

        $db->prepare('INSERT INTO activity_logs (session_id, message) VALUES (?,?)')
           ->execute([$sessionId, $message]);
        ok(null);
    }

    // ── submit — grade answers and close the session ────────────────────────────
    if ($action === 'submit') {
        $user      = authUser($db, 'student');
        $sessionId = (int)($b['session_id'] ?? 0);
        $answers   = $b['answers'] ?? [];

        if (!$sessionId) fail('معرّف الجلسة مطلوب');

        $stmt = $db->prepare('
            SELECT s.*, e.id AS real_exam_id
            FROM   exam_sessions s
            JOIN   exams e ON e.id = s.exam_id
            WHERE  s.id = ? AND s.student_id = ?
        ');
        $stmt->execute([$sessionId, $user['id']]);
        $session = $stmt->fetch();

        if (!$session)                fail('الجلسة غير موجودة');
        if ($session['submitted_at']) fail('تم تسليم هذا الامتحان مسبقاً');

        $stmt = $db->prepare('SELECT * FROM questions WHERE exam_id = ?');
        $stmt->execute([$session['real_exam_id']]);
        $questions = $stmt->fetchAll();

        $ansMap = [];
        foreach ($answers as $a) {
            $ansMap[(int)$a['question_id']] = $a['answer'] ?? null;
        }

        $scored  = 0.0;
        $total   = 0.0;
        $ansStmt = $db->prepare('
            INSERT INTO student_answers
                (session_id, question_id, answer, is_correct, marks_awarded)
            VALUES (?,?,?,?,?)
        ');

        foreach ($questions as $q) {
            $mark          = (float)$q['mark'];
            $total        += $mark;
            $studentAnswer = $ansMap[(int)$q['id']] ?? null;
            $correct       = false;

            if ($q['type'] === 'mcq' || $q['type'] === 'tf') {
                $correct = $studentAnswer !== null
                    && strtolower(trim($studentAnswer))
                       === strtolower(trim((string)$q['correct_answer']));
            } elseif ($q['type'] === 'short' && $q['correct_answer'] !== null) {
                $correct = $studentAnswer !== null
                    && strtolower(trim($studentAnswer))
                       === strtolower(trim($q['correct_answer']));
            }

            $awarded = $correct ? $mark : 0.0;
            if ($correct) $scored += $mark;

            $ansStmt->execute([
                $sessionId, $q['id'], $studentAnswer,
                $correct ? 1 : 0, $awarded,
            ]);
        }

        $pct = $total > 0 ? round($scored / $total * 100, 2) : 0;

        $db->prepare('
            UPDATE exam_sessions
            SET    score = ?, total_marks = ?, percentage = ?,
                   submitted_at = NOW(), results_published = 1
            WHERE  id = ?
        ')->execute([$scored, $total, $pct, $sessionId]);

        ok([
            'score'      => $scored,
            'total'      => $total,
            'percentage' => $pct,
            'session_id' => $sessionId,
        ], 'تم تسليم الامتحان');
    }

    // ── grade — teacher manually grades a student answer ───────────────────────
    if ($action === 'grade') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $answerId  = (int)($b['answer_id'] ?? 0);
        $marks     = (float)($b['marks']    ?? 0);
        $isCorrect = (int)(bool)($b['is_correct'] ?? false);
        if (!$answerId) fail('معرّف الإجابة مطلوب');

        $stmt = $db->prepare('
            SELECT sa.session_id, q.mark
            FROM   student_answers sa
            JOIN   questions q ON q.id = sa.question_id
            WHERE  sa.id = ?
        ');
        $stmt->execute([$answerId]);
        $ansRow = $stmt->fetch();
        if (!$ansRow) fail('الإجابة غير موجودة', 404);

        $marks = min(max($marks, 0), (float)$ansRow['mark']);

        $db->prepare('UPDATE student_answers SET marks_awarded = ?, is_correct = ? WHERE id = ?')
           ->execute([$marks, $isCorrect, $answerId]);

        // Recalculate session score
        $stmt = $db->prepare('
            SELECT SUM(sa.marks_awarded) AS score, SUM(q.mark) AS total
            FROM   student_answers sa
            JOIN   questions q ON q.id = sa.question_id
            WHERE  sa.session_id = ?
        ');
        $stmt->execute([$ansRow['session_id']]);
        $totals = $stmt->fetch();
        $score  = (float)$totals['score'];
        $total  = (float)$totals['total'];
        $pct    = $total > 0 ? round($score / $total * 100, 2) : 0;

        $db->prepare('UPDATE exam_sessions SET score = ?, percentage = ? WHERE id = ?')
           ->execute([$score, $pct, $ansRow['session_id']]);

        ok(['score' => $score, 'percentage' => $pct], 'تم تحديث التقييم');
    }

    // ── publish_results — teacher publishes results for a session ───────────────
    if ($action === 'publish_results') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $sessionId = (int)($b['session_id'] ?? 0);
        if (!$sessionId) fail('معرّف الجلسة مطلوب');

        $db->prepare('UPDATE exam_sessions SET results_published = 1 WHERE id = ?')
           ->execute([$sessionId]);
        ok(null, 'تم نشر النتيجة');
    }

    // ── cheat_fail — mark student as failed due to cheating ────────────────────
    if ($action === 'cheat_fail') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $sessionId = (int)($b['session_id'] ?? 0);
        if (!$sessionId) fail('معرّف الجلسة مطلوب');

        $db->prepare('
            UPDATE exam_sessions
            SET    cheating_fail = 1, score = 0, percentage = 0, results_published = 1
            WHERE  id = ?
        ')->execute([$sessionId]);
        ok(null, 'تم تسجيل الرسوب بسبب الغش');
    }
}

fail('طلب غير صالح');
