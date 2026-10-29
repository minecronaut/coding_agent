<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

session_boot();

$page = $_GET['page'] ?? '';

if ($page === 'logout') {
    auth_logout();
    header('Location: ./');
    exit;
}

$error = '';
$mode = ($_GET['mode'] ?? '') === 'register' ? 'register' : 'login';
$usernameValue = '';
$displayValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'login');
    $mode = $action === 'register' ? 'register' : 'login';
    $usernameValue = trim((string)($_POST['username'] ?? ''));
    $displayValue = trim((string)($_POST['display'] ?? ''));

    if (!csrf_ok(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
        $error = 'Your session expired. Try again.';
    } elseif ($action === 'register') {
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password2'] ?? '');

        if (!preg_match('/^[A-Za-z0-9_]{3,24}$/', $usernameValue)) {
            $error = 'Usernames are 3–24 characters: letters, numbers and underscores.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Use a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The two passwords do not match.';
        } elseif (Store::usernameTaken($usernameValue)) {
            $error = 'That username is taken.';
        } else {
            try {
                auth_register($usernameValue, $password, mb_substr($displayValue, 0, 40));
                header('Location: ./');
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } else {
        $password = (string)($_POST['password'] ?? '');
        try {
            if (auth_login($usernameValue, $password) !== null) {
                header('Location: ./');
                exit;
            }
            $error = 'Wrong username or password.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$user = current_user();
if ($user === null) {
    require __DIR__ . '/views/login.php';
    exit;
}

$state = state_payload($user);
require __DIR__ . '/views/app.php';
