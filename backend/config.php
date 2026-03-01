<?php
// ─── Database credentials ───────────────────────────────────────────────────
// Change these to match your local XAMPP / MAMP / MySQL setup.
define('DB_HOST',    'localhost');
define('DB_NAME',    'online_exam');
define('DB_USER',    'root');
define('DB_PASS',    '');          // XAMPP default is empty; MAMP default is 'root'
define('DB_CHARSET', 'utf8mb4');

// ─── Default credentials (seeded by setup.php) ──────────────────────────────
define('DEFAULT_ADMIN_PASS',   'admin123');
define('DEFAULT_TEACHER_PASS', 'teacher123');

// ─── Token TTL ───────────────────────────────────────────────────────────────
define('TOKEN_TTL_HOURS', 24);
