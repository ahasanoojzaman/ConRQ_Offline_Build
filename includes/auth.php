<?php
/**
 * Auth - handles both tenant-user sessions and super-admin sessions.
 * Two separate session namespaces so an admin and a tenant user
 * can never be confused for one another.
 */

class Auth
{
    // ---------- Tenant user ----------

    public static function attempt(PDO $db, string $email, string $password): bool
    {
        $stmt = $db->prepare("SELECT u.*, t.status AS tenant_status, t.company_name, t.slug
                               FROM users u
                               JOIN tenants t ON t.id = u.tenant_id
                               WHERE u.email = ? AND u.status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        if (!in_array($user['tenant_status'], ['trial', 'active'], true)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['tenant_user'] = [
            'id'        => (int)$user['id'],
            'tenant_id' => (int)$user['tenant_id'],
            'name'      => $user['name'],
            'email'     => $user['email'],
            'role'      => $user['role'],
            'company'   => $user['company_name'],
            'slug'      => $user['slug'],
        ];

        $upd = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
        $upd->execute([$user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['tenant_user']);
    }

    public static function user(): ?array
    {
        return $_SESSION['tenant_user'] ?? null;
    }

    public static function tenantId(): ?int
    {
        return $_SESSION['tenant_user']['tenant_id'] ?? null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect(base_url('login.php'));
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['tenant_user']);
    }

    // ---------- Super admin ----------

    public static function adminAttempt(PDO $db, string $email, string $password): bool
    {
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE email = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_user'] = [
            'id'    => (int)$admin['id'],
            'name'  => $admin['name'],
            'email' => $admin['email'],
        ];

        $upd = $db->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = ?");
        $upd->execute([$admin['id']]);

        return true;
    }

    public static function adminCheck(): bool
    {
        return !empty($_SESSION['admin_user']);
    }

    public static function adminUser(): ?array
    {
        return $_SESSION['admin_user'] ?? null;
    }

    public static function requireAdmin(): void
    {
        if (!self::adminCheck()) {
            redirect(base_url('admin/login.php'));
        }
    }

    public static function adminLogout(): void
    {
        unset($_SESSION['admin_user']);
    }
}

function base_url(string $path = ''): string
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}
