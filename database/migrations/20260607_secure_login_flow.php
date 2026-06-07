<?php

require_once dirname(__DIR__, 2) . '/bootstrap.php';

$config = parse_ini_file(BASE_DIR . '/config/config.ini', true);
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $config['database']['host'],
    $config['database']['dbname'],
    $config['database']['charset']
);
$pdo = new PDO(
    $dsn,
    $config['database']['username'],
    $config['database']['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :table
        AND COLUMN_NAME = :column
    ');
    $stmt->execute(['table' => $table, 'column' => $column]);

    return (bool)$stmt->fetchColumn();
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :table
    ');
    $stmt->execute(['table' => $table]);

    return (bool)$stmt->fetchColumn();
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :table
        AND INDEX_NAME = :index
        LIMIT 1
    ');
    $stmt->execute(['table' => $table, 'index' => $index]);

    return (bool)$stmt->fetchColumn();
}

function constraintExists(PDO $pdo, string $constraint): bool
{
    $stmt = $pdo->prepare('
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
        AND CONSTRAINT_NAME = :constraint
    ');
    $stmt->execute(['constraint' => $constraint]);

    return (bool)$stmt->fetchColumn();
}

$changes = [];

if (!columnExists($pdo, 'users', 'is_approved')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER email');
    $changes[] = 'added users.is_approved';
}
if (!columnExists($pdo, 'users', 'approved_by')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN approved_by INT(11) NULL AFTER is_approved');
    $changes[] = 'added users.approved_by';
}
if (!columnExists($pdo, 'users', 'approved_at')) {
    $pdo->exec('ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER approved_by');
    $changes[] = 'added users.approved_at';
}
if (!indexExists($pdo, 'users', 'idx_users_is_approved')) {
    $pdo->exec('ALTER TABLE users ADD INDEX idx_users_is_approved (is_approved)');
    $changes[] = 'added idx_users_is_approved';
}
if (!indexExists($pdo, 'users', 'idx_users_approved_by')) {
    $pdo->exec('ALTER TABLE users ADD INDEX idx_users_approved_by (approved_by)');
    $changes[] = 'added idx_users_approved_by';
}
if (!constraintExists($pdo, 'users_approved_by_fk')) {
    $pdo->exec('ALTER TABLE users ADD CONSTRAINT users_approved_by_fk FOREIGN KEY (approved_by) REFERENCES users (user_id) ON DELETE SET NULL');
    $changes[] = 'added users_approved_by_fk';
}
if (!tableExists($pdo, 'login_attempts')) {
    $pdo->exec("
        CREATE TABLE login_attempts (
            attempt_id INT(11) NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            blocked_reason VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (attempt_id),
            KEY idx_login_attempts_email_created (email, created_at),
            KEY idx_login_attempts_ip_created (ip_address, created_at),
            KEY idx_login_attempts_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $changes[] = 'created login_attempts';
}

$deleted = $pdo->exec('
    DELETE at
    FROM auth_tokens at
    JOIN users u ON u.user_id = at.user_id
    WHERE u.is_approved = 0
');
$changes[] = 'deleted unapproved auth tokens: ' . (int)$deleted;

echo $changes ? implode(PHP_EOL, $changes) . PHP_EOL : 'No changes needed' . PHP_EOL;
