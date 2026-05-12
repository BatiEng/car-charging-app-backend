<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET /auth.php?action=me  — validate token & return user
if ($method === 'GET' && $action === 'me') {
    $u = currentUser();
    if (!$u) err('Not logged in', 401);
    // Refresh wallet balance from DB
    $row = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $row->execute([$u['id']]);
    $u['wallet_balance'] = (float)$row->fetchColumn();
    respond($u);
}

if ($method === 'POST') {
    $b   = body();
    $act = $b['action'] ?? '';

    // ── Login ──────────────────────────────────────────────────
    if ($act === 'login') {
        $email    = trim($b['email']    ?? '');
        $password = trim($b['password'] ?? '');
        if (!$email || !$password) err('Email and password required');

        $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            err('Invalid email or password', 401);
        }

        unset($user['password']);

        // Issue JWT — embed user payload inside token
        $token = jwt_encode([
            'id'             => (int)$user['id'],
            'name'           => $user['name'],
            'email'          => $user['email'],
            'role'           => $user['role'],
            'wallet_balance' => (float)$user['wallet_balance'],
        ]);

        respond(['user' => $user, 'token' => $token]);
    }

    // ── Register (driver only) ──────────────────────────────────
    if ($act === 'register') {
        $name     = trim($b['name']     ?? '');
        $email    = trim($b['email']    ?? '');
        $password = trim($b['password'] ?? '');
        if (!$name || !$email || !$password) err('All fields required');
        if (strlen($password) < 6) err('Password must be at least 6 characters');

        $check = db()->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) err('Email already registered');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = db()->prepare(
            "INSERT INTO users (name, email, password, role, wallet_balance) VALUES (?,?,?,'driver', 0.00)"
        );
        $stmt->execute([$name, $email, $hash]);
        $id = (int)db()->lastInsertId();

        $user = [
            'id'             => $id,
            'name'           => $name,
            'email'          => $email,
            'role'           => 'driver',
            'wallet_balance' => 0.00,
        ];

        $token = jwt_encode($user);
        respond(['user' => $user, 'token' => $token], 201);
    }

    // ── Logout — stateless JWT; client just drops the token ────
    if ($act === 'logout') {
        respond(['ok' => true]);
    }
}

// ── DELETE /auth.php?action=delete — kullanıcı kendi hesabını siler ──
if ($method === 'DELETE' && $action === 'delete') {
    $u = requireAuth();
    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$u['id']]);
    respond(['ok' => true]);
}

err('Bad request');
