<?php

function current_user()
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }
    $stmt = db()->prepare('SELECT id, tenant_id, name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute(array($_SESSION['user_id']));
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active']) {
        unset($_SESSION['user_id']);
        $user = null;
    }
    return $user;
}

function require_login()
{
    if (!current_user()) {
        redirect('login');
    }
}

function attempt_login($email, $password)
{
    $stmt = db()->prepare('SELECT id, password_hash, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute(array($email));
    $user = $stmt->fetch();
    if (!$user || !(int) $user['is_active'] || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute(array($user['id']));
    return true;
}

function logout_user()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
