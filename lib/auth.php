<?php
declare(strict_types=1);

function current_user_id(): ?string
{
    session_boot();
    $id = $_SESSION['uid'] ?? null;
    return is_string($id) && $id !== '' ? $id : null;
}

function current_user(): ?array
{
    static $cached = null;
    static $cachedId = null;
    $id = current_user_id();
    if ($id === null) {
        return null;
    }
    if ($cached !== null && $cachedId === $id) {
        return $cached;
    }
    $user = Store::load($id);
    $cached = $user;
    $cachedId = $id;
    return $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        fail('Not signed in.', 401);
    }
    return $user;
}

function auth_login(string $username, string $password): ?array
{
    $user = Store::findUserByName($username);
    if ($user === null || !password_verify($password, (string)($user['pass'] ?? ''))) {
        return null;
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    return $user;
}

function auth_register(string $username, string $password, string $display): array
{
    $user = Store::create($username, $password, $display);
    session_boot();
    session_regenerate_id(true);
    $_SESSION['uid'] = $user['id'];
    return $user;
}

function auth_logout(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}
