<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];
$user   = requireAuth();
$uid    = (int)$user['id'];

// ── GET: user's notifications ──
if ($method === 'GET') {
    $stmt = db()->prepare("
        SELECT * FROM notifications WHERE user_id = ?
        ORDER BY created_at DESC LIMIT 50
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    $unread = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $unread->execute([$uid]);

    respond(['notifications' => $rows, 'unread_count' => (int)$unread->fetchColumn()]);
}

// ── PUT: mark as read ──
if ($method === 'PUT') {
    $b      = body();
    $action = $b['action'] ?? '';

    if ($action === 'mark_all_read') {
        db()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
        respond(['ok' => true]);
    }

    $id = (int)($b['id'] ?? 0);
    if (!$id) err('Notification id required');
    db()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$id, $uid]);
    respond(['ok' => true]);
}

err('Method not allowed', 405);
