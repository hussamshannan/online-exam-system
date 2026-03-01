<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET  →  latest submitted result for the logged-in student ───────────────
if ($method === 'GET') {
    $user = authUser($db, 'student');

    $stmt = $db->prepare('
        SELECT s.id, s.score, s.total_marks, s.percentage,
               s.started_at, s.submitted_at, s.student_name,
               e.subject, e.college, e.department
        FROM   exam_sessions s
        JOIN   exams e ON e.id = s.exam_id
        WHERE  s.student_id = ? AND s.submitted_at IS NOT NULL
        ORDER  BY s.submitted_at DESC
        LIMIT  1
    ');
    $stmt->execute([$user['id']]);
    $result = $stmt->fetch();

    if (!$result) { ok(null, 'لا توجد نتيجة'); }
    ok($result);
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? '';

    // ── start — create a new exam session ─────────────────────────────────────
    if ($action === 'start') {
        $user   = authUser($db, 'student');
        $examId = (int)($b['exam_id'] ?? 0);
        if (!$examId) fail('معرّف الامتحان مطلوب');

        // Verify the exam exists and is active
        $stmt = $db->prepare('SELECT id FROM exams WHERE id = ? AND is_active = 1');
        $stmt->execute([$examId]);
        if (!$stmt->fetch()) fail('الامتحان غير متاح');

        $stmt = $db->prepare('
            INSERT INTO exam_sessions
                (exam_id, student_id, student_name, student_email, roll_number)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $examId,
            $user['id'],
            $user['name'],
            $user['email'],
            $user['roll_number'],
        ]);

        ok(['session_id' => (int)$db->lastInsertId()]);
    }

    // ── log — append an activity entry ────────────────────────────────────────
    if ($action === 'log') {
        // No strict auth check — any call with a valid session_id is accepted
        $sessionId = (int)($b['session_id'] ?? 0);
        $message   = trim($b['message']    ?? '');
        if (!$sessionId || !$message) fail('بيانات ناقصة');

        $db->prepare('INSERT INTO activity_logs (session_id, message) VALUES (?,?)')
           ->execute([$sessionId, $message]);
        ok(null);
    }

    // ── submit — grade answers and close the session ───────────────────────────
    if ($action === 'submit') {
        $user      = authUser($db, 'student');
        $sessionId = (int)($b['session_id'] ?? 0);
        $answers   = $b['answers'] ?? [];   // [{question_id, answer}]

        if (!$sessionId) fail('معرّف الجلسة مطلوب');

        // Load session and verify ownership
        $stmt = $db->prepare('
            SELECT s.*, e.id AS real_exam_id
            FROM   exam_sessions s
            JOIN   exams e ON e.id = s.exam_id
            WHERE  s.id = ? AND s.student_id = ?
        ');
        $stmt->execute([$sessionId, $user['id']]);
        $session = $stmt->fetch();

        if (!$session)               fail('الجلسة غير موجودة');
        if ($session['submitted_at']) fail('تم تسليم هذا الامتحان مسبقاً');

        // Load questions
        $stmt = $db->prepare('SELECT * FROM questions WHERE exam_id = ?');
        $stmt->execute([$session['real_exam_id']]);
        $questions = $stmt->fetchAll();

        // Build answer lookup: question_id → student_answer
        $ansMap = [];
        foreach ($answers as $a) {
            $ansMap[(int)$a['question_id']] = $a['answer'] ?? null;
        }

        // Grade
        $scored = 0.0;
        $total  = 0.0;
        $ansStmt = $db->prepare('
            INSERT INTO student_answers
                (session_id, question_id, answer, is_correct, marks_awarded)
            VALUES (?,?,?,?,?)
        ');

        foreach ($questions as $q) {
            $mark           = (float)$q['mark'];
            $total         += $mark;
            $studentAnswer  = $ansMap[(int)$q['id']] ?? null;
            $correct        = false;

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
            SET    score = ?, total_marks = ?, percentage = ?, submitted_at = NOW()
            WHERE  id = ?
        ')->execute([$scored, $total, $pct, $sessionId]);

        ok([
            'score'      => $scored,
            'total'      => $total,
            'percentage' => $pct,
            'session_id' => $sessionId,
        ], 'تم تسليم الامتحان');
    }
}

fail('طلب غير صالح');
