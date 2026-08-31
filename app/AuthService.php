<?php
/**
 * app/AuthService.php
 * Session-based admin/staff authentication with brute-force lockout,
 * IP rate limiting, idle timeout, and strong password rules.
 */

declare(strict_types=1);

final class AuthService
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 30;
    private const IP_MAX_ATTEMPTS = 12;
    private const IP_WINDOW_SECONDS = 900; // 15 minutes
    private const SESSION_IDLE_SECONDS = 7200; // 2 hours
    private const SESSION_REGEN_SECONDS = 1800; // 30 minutes
    private const MIN_PASSWORD_LENGTH = 12;

    public static function attempt(string $username, string $password): array
    {
        $username = strtolower(trim($username));
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        if (self::ipRateLimited('login_fail:' . $ip, self::IP_MAX_ATTEMPTS, self::IP_WINDOW_SECONDS)) {
            return ['ok' => false, 'error' => 'RATE_LIMITED'];
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = :u LIMIT 1");
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch();

        // Always spend similar time on verify (mitigate username enumeration timing)
        $dummyHash = '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUV';
        if (!$admin) {
            password_verify($password, $dummyHash);
            self::ipRateHit('login_fail:' . $ip);
            usleep(random_int(200000, 400000));
            return ['ok' => false, 'error' => 'INVALID_CREDENTIALS'];
        }

        if (!empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
            self::ipRateHit('login_fail:' . $ip);
            return ['ok' => false, 'error' => 'LOCKED', 'locked_until' => $admin['locked_until']];
        }

        if (!password_verify($password, $admin['password_hash'])) {
            $attempts = (int) $admin['failed_attempts'] + 1;
            $lockedUntil = null;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
                $attempts = 0;
            }
            $upd = $pdo->prepare("UPDATE admins SET failed_attempts = :a, locked_until = :l WHERE id = :id");
            $upd->execute(['a' => $attempts, 'l' => $lockedUntil, 'id' => $admin['id']]);
            self::ipRateHit('login_fail:' . $ip);
            usleep(random_int(200000, 400000));
            return ['ok' => false, 'error' => $lockedUntil ? 'LOCKED' : 'INVALID_CREDENTIALS'];
        }

        // Success — reset attempts, bind session
        $upd = $pdo->prepare("UPDATE admins SET failed_attempts = 0, locked_until = NULL, last_login_at = datetime('now') WHERE id = :id");
        $upd->execute(['id' => $admin['id']]);

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['admin_login_at'] = time();
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['admin_ua_hash'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        // Rotate CSRF after privilege change
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        AuditLog::record($admin['username'], 'LOGIN_SUCCESS', null, $ip);

        return ['ok' => true, 'admin' => $admin];
    }

    public static function logout(): void
    {
        if (isset($_SESSION['admin_username'])) {
            AuditLog::record($_SESSION['admin_username'], 'LOGOUT', null, $_SERVER['REMOTE_ADDR'] ?? null);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        if (empty($_SESSION['admin_id'])) {
            return false;
        }

        $uaHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!empty($_SESSION['admin_ua_hash']) && !hash_equals((string) $_SESSION['admin_ua_hash'], $uaHash)) {
            self::logout();
            return false;
        }

        $last = (int) ($_SESSION['admin_last_activity'] ?? 0);
        if ($last > 0 && (time() - $last) > self::SESSION_IDLE_SECONDS) {
            self::logout();
            return false;
        }

        $_SESSION['admin_last_activity'] = time();

        $loginAt = (int) ($_SESSION['admin_login_at'] ?? time());
        if ((time() - $loginAt) > self::SESSION_REGEN_SECONDS) {
            session_regenerate_id(true);
            $_SESSION['admin_login_at'] = time();
        }

        return true;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect(admin_url('login.php'));
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        if ($role === 'admin' && ($_SESSION['admin_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo 'Forbidden: admin access required.';
            exit;
        }
    }

    /** @param list<string> $roles */
    public static function requireAnyRole(array $roles): void
    {
        self::requireLogin();
        if (!in_array(self::currentRole(), $roles, true)) {
            http_response_code(403);
            echo 'Forbidden.';
            exit;
        }
    }

    public static function currentUsername(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    public static function currentRole(): ?string
    {
        return $_SESSION['admin_role'] ?? null;
    }

    public static function isAdmin(): bool
    {
        return self::currentRole() === 'admin';
    }

    public static function isStaff(): bool
    {
        return self::currentRole() === 'staff';
    }

    public static function isSubAdmin(): bool
    {
        return self::currentRole() === 'subadmin';
    }

    /** Where to send the user after a successful login. */
    public static function homePath(): string
    {
        return match (self::currentRole()) {
            'admin' => admin_url('dashboard.php'),
            'subadmin' => admin_url('dashboard.php'),
            default => '/verify.php',
        };
    }

    /** @return array{ok:bool, error?:string} */
    public static function validatePasswordStrength(string $password): array
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.'];
        }
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)) {
            return ['ok' => false, 'error' => 'Password must include upper and lower case letters.'];
        }
        if (!preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            return ['ok' => false, 'error' => 'Password must include a number and a special character.'];
        }
        return ['ok' => true];
    }

    /**
     * Create an admin, sub-admin, or staff account.
     * @return array{ok:bool, error?:string}
     */
    public static function createUser(string $username, string $password, string $role = 'staff'): array
    {
        $username = strtolower(trim($username));
        $role = match ($role) {
            'admin' => 'admin',
            'subadmin' => 'subadmin',
            default => 'staff',
        };

        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            return ['ok' => false, 'error' => 'Username must be 3–40 characters (letters, numbers, . _ -).'];
        }

        $pw = self::validatePasswordStrength($password);
        if (!$pw['ok']) {
            return $pw;
        }

        $pdo = Database::connection();
        $exists = $pdo->prepare("SELECT id FROM admins WHERE username = :u LIMIT 1");
        $exists->execute(['u' => $username]);
        if ($exists->fetch()) {
            return ['ok' => false, 'error' => 'That username already exists.'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO admins (username, password_hash, role, created_at)
            VALUES (:u, :p, :r, datetime('now'))
        ");
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);

        return ['ok' => true];
    }

    /** Reset password for an existing username. */
    public static function setPassword(string $username, string $password): array
    {
        $username = strtolower(trim($username));
        $pw = self::validatePasswordStrength($password);
        if (!$pw['ok']) {
            return $pw;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            UPDATE admins
            SET password_hash = :p, failed_attempts = 0, locked_until = NULL
            WHERE username = :u
        ");
        $stmt->execute([
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'u' => $username,
        ]);
        if ($stmt->rowCount() < 1) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        return ['ok' => true];
    }

    /** @return list<array{id:int,username:string,role:string,last_login_at:?string}> */
    public static function listUsers(): array
    {
        $pdo = Database::connection();
        return $pdo->query("SELECT id, username, role, last_login_at FROM admins ORDER BY role ASC, username ASC")->fetchAll();
    }

    /**
     * Update role and/or password for an existing account.
     * @return array{ok:bool, error?:string}
     */
    public static function updateUser(int $id, string $role, ?string $password = null): array
    {
        $role = match ($role) {
            'admin' => 'admin',
            'subadmin' => 'subadmin',
            default => 'staff',
        };

        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id, username, role FROM admins WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $currentId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($currentId > 0 && $currentId === $id && $role !== 'admin') {
            return ['ok' => false, 'error' => 'You cannot remove your own admin role.'];
        }

        // Don't demote/remove the last admin
        if (($user['role'] ?? '') === 'admin' && $role !== 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                return ['ok' => false, 'error' => 'Cannot change role of the last admin account.'];
            }
        }

        if ($password !== null && $password !== '') {
            $pw = self::validatePasswordStrength($password);
            if (!$pw['ok']) {
                return $pw;
            }
            $upd = $pdo->prepare("
                UPDATE admins
                SET role = :r, password_hash = :p, failed_attempts = 0, locked_until = NULL
                WHERE id = :id
            ");
            $upd->execute([
                'r' => $role,
                'p' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $id,
            ]);
        } else {
            $upd = $pdo->prepare("UPDATE admins SET role = :r WHERE id = :id");
            $upd->execute(['r' => $role, 'id' => $id]);
        }

        return ['ok' => true];
    }

    /**
     * Delete an account. Cannot delete yourself or the last admin.
     * @return array{ok:bool, error?:string, username?:string}
     */
    public static function deleteUser(int $id): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id, username, role FROM admins WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['ok' => false, 'error' => 'User not found.'];
        }

        $currentId = (int) ($_SESSION['admin_id'] ?? 0);
        if ($currentId > 0 && $currentId === $id) {
            return ['ok' => false, 'error' => 'You cannot delete your own account.'];
        }

        if (($user['role'] ?? '') === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                return ['ok' => false, 'error' => 'Cannot delete the last admin account.'];
            }
        }

        $del = $pdo->prepare("DELETE FROM admins WHERE id = :id");
        $del->execute(['id' => $id]);
        return ['ok' => true, 'username' => (string) $user['username']];
    }

    /** Session-based rate limiter (forms / registration). */
    public static function rateLimited(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $now = time();
        $bucket = $_SESSION['rate_' . $key] ?? [];
        $bucket = array_filter($bucket, fn($t) => $t > $now - $windowSeconds);
        if (count($bucket) >= $maxAttempts) {
            $_SESSION['rate_' . $key] = $bucket;
            return true;
        }
        $bucket[] = $now;
        $_SESSION['rate_' . $key] = $bucket;
        return false;
    }

    /** File-based IP limiter — survives cookie clears / multi-session attacks. */
    private static function rateFile(string $key): string
    {
        $dir = APP_ROOT . '/storage/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    private static function ipRateLimited(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = self::rateFile($key);
        $now = time();
        $bucket = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $bucket = array_values(array_filter($decoded, fn($t) => is_int($t) && $t > $now - $windowSeconds));
            }
        }
        return count($bucket) >= $maxAttempts;
    }

    private static function ipRateHit(string $key): void
    {
        $file = self::rateFile($key);
        $now = time();
        $bucket = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $bucket = array_values(array_filter($decoded, fn($t) => is_int($t) && $t > $now - self::IP_WINDOW_SECONDS));
            }
        }
        $bucket[] = $now;
        @file_put_contents($file, json_encode($bucket), LOCK_EX);
    }
}
