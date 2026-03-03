<?php
/**
 * init.php — Called automatically on first page load.
 * Checks whether the database and tables exist; if not, creates and seeds them.
 * Returns JSON: { success, seeded, message }
 */
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function init_respond(bool $seeded, string $msg = ''): void {
    echo json_encode(['success' => true,  'seeded' => $seeded, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function init_fail(string $msg): void {
    http_response_code(500);
    echo json_encode(['success' => false, 'seeded' => false,   'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Connect WITHOUT specifying a database (so we can CREATE it if missing)
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check whether the database already exists
    $dbExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME = '" . DB_NAME . "'"
    )->fetchColumn();

    if ($dbExists) {
        $pdo->exec("USE `" . DB_NAME . "`");

        // Check if the core tables are present (users table as proxy)
        $tableExists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'users'"
        )->fetchColumn();

        if ($tableExists) {
            init_respond(false); // Already set up — nothing to do
        }
    }

    // ── Database + Tables ────────────────────────────────────────────────────
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name          VARCHAR(120)  NOT NULL,
        email         VARCHAR(180)  NOT NULL UNIQUE,
        password_hash VARCHAR(255)  NOT NULL,
        role          ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
        roll_number   VARCHAR(30)   DEFAULT NULL,
        college       VARCHAR(120)  DEFAULT NULL,
        department    VARCHAR(120)  DEFAULT NULL,
        batch         VARCHAR(60)   DEFAULT NULL,
        status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role   (role),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        token      VARCHAR(64)  NOT NULL UNIQUE,
        expires_at DATETIME     NOT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS exams (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        college     VARCHAR(120) DEFAULT NULL,
        department  VARCHAR(120) DEFAULT NULL,
        batch       VARCHAR(60)  DEFAULT NULL,
        subject     VARCHAR(200) NOT NULL,
        duration    SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        created_by  INT UNSIGNED DEFAULT NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS questions (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        exam_id        INT UNSIGNED NOT NULL,
        type           ENUM('mcq','tf','short') NOT NULL,
        text           TEXT         NOT NULL,
        mark           DECIMAL(5,2) NOT NULL DEFAULT 1.00,
        option_a       VARCHAR(500) DEFAULT NULL,
        option_b       VARCHAR(500) DEFAULT NULL,
        option_c       VARCHAR(500) DEFAULT NULL,
        option_d       VARCHAR(500) DEFAULT NULL,
        correct_answer VARCHAR(500) DEFAULT NULL,
        sort_order     SMALLINT    NOT NULL DEFAULT 0,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        INDEX idx_exam (exam_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_sessions (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        exam_id       INT UNSIGNED NOT NULL,
        student_id    INT UNSIGNED DEFAULT NULL,
        student_name  VARCHAR(120) DEFAULT NULL,
        student_email VARCHAR(180) DEFAULT NULL,
        roll_number   VARCHAR(30)  DEFAULT NULL,
        score         DECIMAL(6,2) NOT NULL DEFAULT 0,
        total_marks   DECIMAL(6,2) NOT NULL DEFAULT 0,
        percentage    DECIMAL(5,2) NOT NULL DEFAULT 0,
        started_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        submitted_at  DATETIME     DEFAULT NULL,
        FOREIGN KEY (exam_id)    REFERENCES exams(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_answers (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id    INT UNSIGNED NOT NULL,
        question_id   INT UNSIGNED NOT NULL,
        answer        TEXT         DEFAULT NULL,
        is_correct    TINYINT(1)   NOT NULL DEFAULT 0,
        marks_awarded DECIMAL(5,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (session_id)  REFERENCES exam_sessions(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES questions(id)     ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        message    TEXT         NOT NULL,
        log_time   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES exam_sessions(id) ON DELETE CASCADE,
        INDEX idx_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Seed admin + teacher ─────────────────────────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)"
    );
    $stmt->execute(['المشرف',  'admin@exam.local',   password_hash(DEFAULT_ADMIN_PASS,   PASSWORD_DEFAULT), 'admin']);
    $stmt->execute(['المعلم',  'teacher@exam.local', password_hash(DEFAULT_TEACHER_PASS, PASSWORD_DEFAULT), 'teacher']);

    // ── Seed sample students ─────────────────────────────────────────────────
    $students = [
        ['أحمد محمد علي',     'ahmed@exam.local',    '001', 'كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى'],
        ['فاطمة حسن كريم',    'fatima@exam.local',   '002', 'كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى'],
        ['عمر خالد سعيد',     'omar@exam.local',     '003', 'كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى'],
        ['زينب عبدالله ناصر', 'zainab@exam.local',   '004', 'كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى'],
        ['محمد سامي جاسم',    'mohammed@exam.local', '005', 'كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى'],
        ['نور علاء الدين',     'noor@exam.local',     '006', 'كلية الحاسوب', 'علوم الحاسوب',  'الدفعة الثانية'],
        ['علي حسين رضا',      'ali@exam.local',      '007', 'كلية الحاسوب', 'علوم الحاسوب',  'الدفعة الثانية'],
        ['سارة إبراهيم مجيد', 'sara@exam.local',     '008', 'كلية الطب',    'الطب البشري',   'الدفعة الثالثة'],
    ];

    $sStmt = $pdo->prepare(
        "INSERT IGNORE INTO users
             (name, email, password_hash, role, roll_number, college, department, batch)
         VALUES (?, ?, ?, 'student', ?, ?, ?, ?)"
    );
    foreach ($students as [$name, $email, $roll, $college, $dept, $batch]) {
        $sStmt->execute([$name, $email, password_hash('student123', PASSWORD_DEFAULT), $roll, $college, $dept, $batch]);
    }

    // ── Seed sample exam ─────────────────────────────────────────────────────
    $teacherRow = $pdo->query("SELECT id FROM users WHERE role = 'teacher' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $teacherId  = $teacherRow['id'] ?? null;

    if ((int)$pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn() === 0) {
        $pdo->prepare(
            "INSERT INTO exams (college, department, batch, subject, duration, created_by, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        )->execute(['كلية الهندسة', 'هندسة برمجيات', 'الدفعة الأولى', 'برمجة 1', 60, $teacherId]);

        $examId = (int)$pdo->lastInsertId();

        $questions = [
            ['mcq',   'ما هو الناتج من تنفيذ الدالة print(2 + 3) في Python؟',           2, '2',     '3',   '5',  '23',  'c',     0],
            ['mcq',   'أي من الرموز التالية يُستخدم للتعليق في لغة Java؟',              1, '//',    '##',  '**', '%%', 'a',     1],
            ['mcq',   'ما هو نوع البيانات الذي يخزن القيم الصحيحة في معظم اللغات؟',    1, 'float', 'int', 'char','bool','b',    2],
            ['mcq',   'أي بنية تحكم تُستخدم لتكرار مجموعة من التعليمات؟',               2, 'if',    'switch','for','return','c', 3],
            ['tf',    'لغة HTML هي لغة برمجة كاملة.',                                   1, null, null, null, null, 'false', 4],
            ['tf',    'قاعدة البيانات MySQL هي قاعدة بيانات علائقية.',                  1, null, null, null, null, 'true',  5],
            ['tf',    'المصفوفة (Array) هي نوع من هياكل البيانات.',                     1, null, null, null, null, 'true',  6],
            ['short', 'اكتب الكلمة المفتاحية المستخدمة في Python لتعريف دالة.',         2, null, null, null, null, 'def',   7],
            ['short', 'ما هو اختصار كلمة HTML؟',                                        2, null, null, null, null, 'HyperText Markup Language', 8],
            ['short', 'ما هي البروتوكول المستخدم لنقل صفحات الويب على الإنترنت؟',      2, null, null, null, null, 'HTTP',  9],
        ];

        $qStmt = $pdo->prepare("
            INSERT INTO questions
                (exam_id, type, text, mark, option_a, option_b, option_c, option_d, correct_answer, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($questions as $q) {
            $qStmt->execute(array_merge([$examId], $q));
        }
    }

    init_respond(true, 'تم إعداد قاعدة البيانات وإضافة البيانات التجريبية بنجاح');

} catch (PDOException $e) {
    init_fail('تعذّر الاتصال بقاعدة البيانات: ' . $e->getMessage());
}
