<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET: herkes görebilir (frontend saati göstermek için)
if ($method === 'GET') {
    requireAuth();
    $offset = 0;
    try {
        $row = db()->query("SELECT value FROM demo_config WHERE `key`='time_offset_seconds'")->fetchColumn();
        $offset = (int)($row ?: 0);
    } catch (\Throwable) {}

    $now = demoNow();
    respond([
        'offset_seconds' => $offset,
        'demo_time'      => $now->format('Y-m-d H:i:s'),
        'demo_time_tr'   => $now->format('d.m.Y H:i'),
        'real_time'      => (new DateTime())->format('Y-m-d H:i:s'),
    ]);
}

// POST: admin – offset ayarla (saniye cinsinden)
if ($method === 'POST') {
    requireRole('admin');
    $b      = body();
    $add    = (int)($b['add_seconds'] ?? 0); // mevcut offset'e ekle
    $set    = isset($b['set_seconds']) ? (int)$b['set_seconds'] : null; // direkt set

    try {
        $current = (int)(db()->query("SELECT value FROM demo_config WHERE `key`='time_offset_seconds'")->fetchColumn() ?: 0);
    } catch (\Throwable) { $current = 0; }

    $newOffset = $set !== null ? $set : ($current + $add);

    db()->prepare("UPDATE demo_config SET value=? WHERE `key`='time_offset_seconds'")
        ->execute([$newOffset]);

    $now = new DateTime('now');
    $now->modify("{$newOffset} seconds");
    respond([
        'ok'             => true,
        'offset_seconds' => $newOffset,
        'demo_time'      => $now->format('Y-m-d H:i:s'),
        'demo_time_tr'   => $now->format('d.m.Y H:i'),
    ]);
}

// DELETE: admin – sıfırla
if ($method === 'DELETE') {
    requireRole('admin');
    db()->prepare("UPDATE demo_config SET value='0' WHERE `key`='time_offset_seconds'")->execute();
    respond([
        'ok'             => true,
        'offset_seconds' => 0,
        'demo_time'      => (new DateTime())->format('Y-m-d H:i:s'),
        'demo_time_tr'   => (new DateTime())->format('d.m.Y H:i'),
    ]);
}

err('Method not allowed', 405);
