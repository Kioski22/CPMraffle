<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('auth_normalize_role')) {
    function auth_normalize_role(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));
        return in_array($normalized, ['admin', 'secretariat', 'chapter'], true) ? $normalized : '';
    }
}

if (!function_exists('auth_is_logged_in')) {
    function auth_is_logged_in(): bool
    {
        return isset($_SESSION['id']) || isset($_SESSION['user_id']);
    }
}

if (!function_exists('auth_user_id')) {
    function auth_user_id(): int
    {
        return (int) ($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
    }
}

if (!function_exists('auth_role')) {
    function auth_role(): string
    {
        $sessionRole = auth_normalize_role((string) ($_SESSION['role'] ?? ''));
        return $sessionRole !== '' ? $sessionRole : 'admin';
    }
}

if (!function_exists('auth_is_admin')) {
    function auth_is_admin(): bool
    {
        return auth_role() === 'admin';
    }
}

if (!function_exists('auth_is_secretariat')) {
    function auth_is_secretariat(): bool
    {
        return auth_role() === 'secretariat';
    }
}

if (!function_exists('auth_dashboard_path_for_role')) {
    function auth_dashboard_path_for_role(string $role): string
    {
        if ($role === 'secretariat') {
            return 'secretariat_dashboard.php';
        }
        if ($role === 'chapter') {
            return 'chapter_dashboard.php';
        }
        return 'admin_dashboard.php';
    }
}

if (!function_exists('auth_redirect_to_dashboard')) {
    function auth_redirect_to_dashboard(): void
    {
        $role = auth_role();
        if ($role === '') {
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit();
        }
        header('Location: ' . auth_dashboard_path_for_role($role));
        exit();
    }
}

if (!function_exists('auth_require_login')) {
    function auth_require_login(string $redirect = 'login.php'): void
    {
        if (!auth_is_logged_in()) {
            header('Location: ' . $redirect);
            exit();
        }
    }
}

if (!function_exists('auth_require_roles')) {
    function auth_require_roles(array $allowedRoles, string $redirect = 'login.php'): void
    {
        auth_require_login($redirect);

        $normalized = [];
        foreach ($allowedRoles as $allowedRole) {
            $role = auth_normalize_role((string) $allowedRole);
            if ($role !== '') {
                $normalized[] = $role;
            }
        }

        if ($normalized === []) {
            http_response_code(500);
            echo 'Access rules are not configured.';
            exit();
        }

        if (!in_array(auth_role(), $normalized, true)) {
            http_response_code(403);
            echo 'You do not have permission to access this page.';
            exit();
        }
    }
}
