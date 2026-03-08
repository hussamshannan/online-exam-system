<?php
/**
 * migrate.php — Run once to upgrade an existing database to the new schema.
 * Access: http://localhost/online-exam-system-master/backend/migrate.php
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

function log_msg(string $msg, bool $ok = true): void {
    $c = $ok ? '#155724' : '#721c24';
    $b = $ok ? '#d4edda' : '#f8d7da';
    echo "<div style='background:{$b};color:{$c};padding:8px 14px;border-radius:5px;margin:6px 0;font-family:monospace'>{$msg}</div>";
}

echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">
<title>ترقية قاعدة البيانات</title>
<style>body{font-family:Arial,sans-serif;max-width:700px;margin:40px auto;padding:20px;background:#f9f8f3}
h1{color:#2d5016}code{background:#eee;padding:2px 6px;border-radius:3px}</style>
</head><body><h1>ترقية نظام الامتحان الإلكتروني</h1>';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // 1. Add supervisor role to users ENUM
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role
        ENUM('admin','teacher','student','supervisor') NOT NULL DEFAULT 'student'");
    log_msg('✓ إضافة دور المراقب (supervisor) إلى جدول users');

    // 2. Add time-window and publish columns to exams
    $examCols = $pdo->query("SHOW COLUMNS FROM exams")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'start_time'   => "ALTER TABLE exams ADD COLUMN start_time DATETIME DEFAULT NULL",
        'end_time'     => "ALTER TABLE exams ADD COLUMN end_time DATETIME DEFAULT NULL",
        'is_published' => "ALTER TABLE exams ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $sql) {
        if (!in_array($col, $examCols)) {
            $pdo->exec($sql);
            log_msg("✓ إضافة حقل {$col} إلى exams");
        } else {
            log_msg("ℹ حقل {$col} موجود مسبقاً");
        }
    }
    // Mark existing active exams as published for backward compatibility
    $updated = $pdo->exec("UPDATE exams SET is_published = 1 WHERE is_active = 1 AND is_published = 0");
    log_msg("✓ تحديث {$updated} امتحان موجود: is_published = 1");

    // 3. Add cheating_fail and results_published to exam_sessions
    $sesCols = $pdo->query("SHOW COLUMNS FROM exam_sessions")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'cheating_fail'     => "ALTER TABLE exam_sessions ADD COLUMN cheating_fail TINYINT(1) NOT NULL DEFAULT 0",
        'results_published' => "ALTER TABLE exam_sessions ADD COLUMN results_published TINYINT(1) NOT NULL DEFAULT 0",
    ] as $col => $sql) {
        if (!in_array($col, $sesCols)) {
            $pdo->exec($sql);
            log_msg("✓ إضافة حقل {$col} إلى exam_sessions");
        } else {
            log_msg("ℹ حقل {$col} موجود مسبقاً");
        }
    }
    // Mark existing submitted sessions as published for backward compatibility
    $updated = $pdo->exec("UPDATE exam_sessions SET results_published = 1 WHERE submitted_at IS NOT NULL AND results_published = 0");
    log_msg("✓ تحديث {$updated} جلسة موجودة: results_published = 1");

    // 4. Seed supervisor account
    $hash = password_hash('supervisor123', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'supervisor')")
        ->execute(['المراقب', 'supervisor@exam.local', $hash]);
    log_msg('✓ حساب المراقب: supervisor@exam.local — كلمة المرور: supervisor123');

    echo '<hr style="margin:20px 0">
    <div style="background:#cce5ff;color:#004085;padding:14px;border-radius:6px">
    <strong>تم الترقية بنجاح!</strong>
    يمكنك الآن <a href="../index.html">الدخول إلى النظام</a>.
    </div>';

} catch (PDOException $e) {
    log_msg('✗ خطأ: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES), false);
}

echo '</body></html>';
