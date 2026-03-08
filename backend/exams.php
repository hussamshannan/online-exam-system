<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET ──────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = authUser($db);
    $id   = (int)($_GET['id'] ?? 0);

    if ($user['role'] === 'student') {
        if ($id) {
            // Return full exam with questions — verify accessibility for this student
            $stmt = $db->prepare('
                SELECT * FROM exams
                WHERE  id = ? AND is_active = 1 AND is_published = 1
                  AND  college = ? AND batch = ?
                  AND  (end_time IS NULL OR end_time > NOW())
            ');
            $stmt->execute([$id, $user['college'], $user['batch']]);
            $exam = $stmt->fetch();
            if (!$exam) fail('الامتحان غير متاح', 404);

            $stmt = $db->prepare(
                'SELECT * FROM questions WHERE exam_id = ? ORDER BY sort_order, id'
            );
            $stmt->execute([$id]);
            $exam['questions'] = $stmt->fetchAll();

            foreach ($exam['questions'] as &$q) {
                $q['a'] = $q['option_a'];
                $q['b'] = $q['option_b'];
                $q['c'] = $q['option_c'];
                $q['d'] = $q['option_d'];
                // Never expose correct answers to students
                unset($q['correct_answer'], $q['option_a'], $q['option_b'],
                      $q['option_c'], $q['option_d']);
            }
            unset($q);
            ok($exam);
        }

        // List of available exams filtered by student's college + batch
        $stmt = $db->prepare('
            SELECT id, college, department, batch, subject, duration, start_time, end_time
            FROM   exams
            WHERE  is_active = 1 AND is_published = 1
              AND  college = ? AND batch = ?
              AND  (end_time IS NULL OR end_time > NOW())
            ORDER  BY created_at DESC
        ');
        $stmt->execute([$user['college'], $user['batch']]);
        ok($stmt->fetchAll());
    }

    // Admin / teacher / supervisor
    if ($id) {
        // Single exam with questions
        $stmt = $db->prepare('SELECT * FROM exams WHERE id = ?');
        $stmt->execute([$id]);
        $exam = $stmt->fetch();
        if (!$exam) fail('الامتحان غير موجود', 404);

        $stmt = $db->prepare(
            'SELECT * FROM questions WHERE exam_id = ? ORDER BY sort_order, id'
        );
        $stmt->execute([$id]);
        $qs = $stmt->fetchAll();
        foreach ($qs as &$q) {
            $q['a']       = $q['option_a'];
            $q['b']       = $q['option_b'];
            $q['c']       = $q['option_c'];
            $q['d']       = $q['option_d'];
            $q['correct'] = $q['correct_answer'];
            $q['answer']  = $q['correct_answer'];
        }
        unset($q);
        $exam['questions'] = $qs;
        ok($exam);
    }

    // Auto-unpublish expired exams — use MySQL NOW() to avoid PHP timestamp overflow
    $db->exec(
        "UPDATE exams SET is_published = 0
         WHERE  is_published = 1 AND end_time IS NOT NULL AND end_time < NOW()"
    );

    $rows = $db->query('
        SELECT e.id, e.college, e.department, e.batch, e.subject,
               e.duration, e.is_active, e.is_published,
               e.start_time, e.end_time, e.created_at,
               COUNT(q.id) AS question_count
        FROM   exams e
        LEFT JOIN questions q ON q.exam_id = e.id
        GROUP  BY e.id
        ORDER  BY e.college, e.department, e.batch, e.created_at DESC
    ')->fetchAll();

    ok($rows);
}

// ─── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $b      = body();
    $action = $b['action'] ?? 'save';

    // ── save (create exam) ──────────────────────────────────────────────────────
    if ($action === 'save') {
        $teacher    = authUser($db, 'teacher');
        $college    = trim($b['college']    ?? '');
        $department = trim($b['department'] ?? '');
        $batch      = trim($b['batch']      ?? '');
        $subject    = trim($b['subject']    ?? '');
        $duration   = (int)($b['duration']  ?? 0);
        $startTime  = $b['start_time'] ?? null;
        $endTime    = $b['end_time']   ?? null;
        $questions  = $b['questions']  ?? [];

        if (!$subject)         fail('اسم المادة مطلوب');
        if ($duration < 1)     fail('مدة الامتحان يجب أن تكون دقيقة واحدة على الأقل');
        if (empty($questions)) fail('يجب إضافة سؤال واحد على الأقل');
        if ($startTime && $endTime && strtotime($startTime) >= strtotime($endTime))
            fail('وقت الانتهاء يجب أن يكون بعد وقت البدء');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                INSERT INTO exams
                    (college, department, batch, subject, duration,
                     start_time, end_time, created_by, is_active, is_published)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)
            ');
            $stmt->execute([
                $college, $department, $batch, $subject, $duration,
                $startTime ?: null, $endTime ?: null, $teacher['id'],
            ]);
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
            ok(
                ['exam_id' => $examId],
                'تم حفظ الامتحان بنجاح (' . count($questions) . ' أسئلة) — يمكنك نشره من تبويب الامتحانات'
            );
        } catch (Throwable $e) {
            $db->rollBack();
            fail('خطأ في حفظ الامتحان: ' . $e->getMessage(), 500);
        }
    }

    // ── publish ─────────────────────────────────────────────────────────────────
    if ($action === 'publish') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الامتحان مطلوب');

        $stmt = $db->prepare('SELECT * FROM exams WHERE id = ?');
        $stmt->execute([$id]);
        $exam = $stmt->fetch();
        if (!$exam) fail('الامتحان غير موجود', 404);

        if ($exam['start_time'] && strtotime($exam['start_time']) > time())
            fail('لا يمكن نشر الامتحان قبل وقت البدء المحدد: ' . $exam['start_time']);
        if ($exam['end_time'] && strtotime($exam['end_time']) < time())
            fail('انتهى وقت الامتحان، لا يمكن نشره');

        $db->prepare('UPDATE exams SET is_published = 1 WHERE id = ?')->execute([$id]);
        ok(null, 'تم نشر الامتحان بنجاح');
    }

    // ── unpublish ───────────────────────────────────────────────────────────────
    if ($action === 'unpublish') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الامتحان مطلوب');

        $db->prepare('UPDATE exams SET is_published = 0 WHERE id = ?')->execute([$id]);
        ok(null, 'تم إيقاف نشر الامتحان');
    }

    // ── set_time_window ─────────────────────────────────────────────────────────
    if ($action === 'set_time_window') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $id        = (int)($b['id'] ?? 0);
        $startTime = $b['start_time'] ?? null;
        $endTime   = $b['end_time']   ?? null;
        if (!$id) fail('معرّف الامتحان مطلوب');
        if ($startTime && $endTime && strtotime($startTime) >= strtotime($endTime))
            fail('وقت الانتهاء يجب أن يكون بعد وقت البدء');

        $db->prepare('UPDATE exams SET start_time = ?, end_time = ? WHERE id = ?')
           ->execute([$startTime ?: null, $endTime ?: null, $id]);
        ok(null, 'تم تحديث نافذة الوقت');
    }

    // ── delete ──────────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $user = authUser($db);
        if (!in_array($user['role'], ['admin', 'teacher'])) fail('صلاحيات غير كافية', 403);

        $id = (int)($b['id'] ?? 0);
        if (!$id) fail('معرّف الامتحان مطلوب');
        $db->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);
        ok(null, 'تم حذف الامتحان');
    }

    // ── delete_all ──────────────────────────────────────────────────────────────
    if ($action === 'delete_all') {
        authUser($db, 'admin');
        $db->exec('DELETE FROM exams');
        ok(null, 'تم حذف جميع الامتحانات');
    }
}

fail('طلب غير صالح');
