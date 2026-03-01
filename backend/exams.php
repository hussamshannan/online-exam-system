<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET  →  return the active exam with its questions ────────────────────────
if ($method === 'GET') {
    authUser($db);   // any logged-in user

    $exam = $db->query(
        'SELECT * FROM exams WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1'
    )->fetch();

    if (!$exam) { ok(null, 'لا يوجد امتحان نشط حالياً'); }

    $stmt = $db->prepare(
        'SELECT * FROM questions WHERE exam_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([$exam['id']]);
    $exam['questions'] = $stmt->fetchAll();

    // Normalise question fields to match the frontend's expected shape
    foreach ($exam['questions'] as &$q) {
        $q['a']       = $q['option_a'];
        $q['b']       = $q['option_b'];
        $q['c']       = $q['option_c'];
        $q['d']       = $q['option_d'];
        $q['correct'] = $q['correct_answer'];
        $q['answer']  = $q['correct_answer'];  // short-answer reference
    }
    unset($q);

    ok($exam);
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'save';

    // ── save (create / replace active exam) ────────────────────────────────────
    if ($action === 'save') {
        $teacher = authUser($db, 'teacher');

        $college    = trim($b['college']    ?? '');
        $department = trim($b['department'] ?? '');
        $batch      = trim($b['batch']      ?? '');
        $subject    = trim($b['subject']    ?? '');
        $duration   = (int)($b['duration']  ?? 0);
        $questions  = $b['questions'] ?? [];

        if (!$subject)           fail('اسم المادة مطلوب');
        if ($duration < 1)       fail('مدة الامتحان يجب أن تكون دقيقة واحدة على الأقل');
        if (empty($questions))   fail('يجب إضافة سؤال واحد على الأقل');

        $db->beginTransaction();
        try {
            // Deactivate previous exams
            $db->exec('UPDATE exams SET is_active = 0');

            $stmt = $db->prepare(
                'INSERT INTO exams (college, department, batch, subject, duration, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$college, $department, $batch, $subject, $duration, $teacher['id']]);
            $examId = (int)$db->lastInsertId();

            $qStmt = $db->prepare('
                INSERT INTO questions
                    (exam_id, type, text, mark, option_a, option_b, option_c, option_d,
                     correct_answer, sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ');

            foreach ($questions as $i => $q) {
                $qStmt->execute([
                    $examId,
                    $q['type'],
                    $q['text'],
                    max(0, (float)($q['mark'] ?? 1)),
                    $q['a']       ?? $q['option_a'] ?? null,
                    $q['b']       ?? $q['option_b'] ?? null,
                    $q['c']       ?? $q['option_c'] ?? null,
                    $q['d']       ?? $q['option_d'] ?? null,
                    $q['correct'] ?? $q['answer']   ?? null,
                    $i,
                ]);
            }

            $db->commit();
            ok(['exam_id' => $examId], 'تم حفظ الامتحان بنجاح (' . count($questions) . ' أسئلة)');

        } catch (Throwable $e) {
            $db->rollBack();
            fail('خطأ في حفظ الامتحان: ' . $e->getMessage(), 500);
        }
    }

    // ── delete one exam ────────────────────────────────────────────────────────
    if ($action === 'delete') {
        authUser($db, 'admin');
        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الامتحان مطلوب');
        $db->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);
        ok(null, 'تم حذف الامتحان');
    }

    // ── delete all exams ───────────────────────────────────────────────────────
    if ($action === 'delete_all') {
        authUser($db, 'admin');
        $db->exec('DELETE FROM exams');
        ok(null, 'تم حذف جميع الامتحانات');
    }
}

fail('طلب غير صالح');
