<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: balance + transaction history ──
if ($method === 'GET') {
    $user = requireAuth();
    $uid  = (int)$user['id'];

    $bal  = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $bal->execute([$uid]);
    $balance = (float)$bal->fetchColumn();

    $txn  = db()->prepare("
        SELECT * FROM wallet_transactions WHERE user_id = ?
        ORDER BY created_at DESC LIMIT 50
    ");
    $txn->execute([$uid]);
    $transactions = $txn->fetchAll();

    respond(['balance' => $balance, 'transactions' => $transactions]);
}

// ── POST: top up wallet (fake payment) ──
if ($method === 'POST') {
    $user   = requireAuth();
    $uid    = (int)$user['id'];
    $b      = body();
    $amount = (float)($b['amount'] ?? 0);

    if ($amount <= 0 || $amount > 5000) err('Amount must be 1 – 5000 TL');

    // "Fake" payment — card details accepted, never validated
    $card = $b['card_number'] ?? '';
    if (!$card) err('Card number required for payment simulation');

    // Add to wallet
    db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$amount, $uid]);

    // Get new balance
    $newBal = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $newBal->execute([$uid]);
    $balance = (float)$newBal->fetchColumn();

    // Record transaction
    db()->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
        VALUES (?,?,'credit',?,?)
    ")->execute([$uid, $amount, "Top-up via card ending ".substr($card, -4), $balance]);

    // Update session
    $_SESSION['user']['wallet_balance'] = $balance;

    respond(['balance' => $balance, 'added' => $amount]);
}

err('Method not allowed', 405);
