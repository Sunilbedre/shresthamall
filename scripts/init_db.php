<?php
/**
 * scripts/init_db.php
 * Run this ONCE after deployment to create the SQLite database, all tables,
 * default settings, and a first admin login.
 *
 * Usage (command line, from the project root):
 *   php scripts/init_db.php
 *   php scripts/init_db.php --username=admin --password=SomeStrongPassword123 --role=admin
 *
 * If --username/--password are omitted, a random password is generated and
 * printed once — save it immediately, it is not stored anywhere in plain text.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Database.php';

Database::migrate();
echo "Database schema created/verified at: {$CONFIG['db_path']}\n";

$options = getopt('', ['username::', 'password::', 'role::']);
$username = $options['username'] ?? 'admin';
$password = $options['password'] ?? bin2hex(random_bytes(6));
$role = $options['role'] ?? 'admin';

if (!in_array($role, ['admin', 'staff'], true)) {
    $role = 'admin';
}

$pdo = Database::connection();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = :u");
$stmt->execute(['u' => $username]);

if ((int) $stmt->fetchColumn() > 0) {
    echo "An admin with username '{$username}' already exists — skipping creation.\n";
    echo "To reset a password, delete that row from the 'admins' table and re-run this script.\n";
    exit(0);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$insert = $pdo->prepare("INSERT INTO admins (username, password_hash, role, created_at) VALUES (:u, :p, :r, datetime('now'))");
$insert->execute(['u' => $username, 'p' => $hash, 'r' => $role]);

echo "\n==================================================\n";
echo " Admin account created\n";
echo "==================================================\n";
echo " Username: {$username}\n";
echo " Password: {$password}\n";
echo " Role:     {$role}\n";
echo "==================================================\n";
echo " Save this password now — log in at /admin/login.php and change it\n";
echo " by creating a new admin user and removing this one, or by hashing\n";
echo " a new password directly in the database.\n";
echo "==================================================\n";
