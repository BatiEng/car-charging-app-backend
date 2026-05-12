<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Auto-expire notified entries older than 30 min, then notify next ──
function processExpiredQueue() {
    $nowSQL    = demoNowSQL();
    $threshold = (new DateTime($nowSQL))->modify('-30 minutes')->format('Y-m-d H:i:s');

    $expired = db()->prepare("
        SELECT id, station_id, connector_type
        FROM charger_queue
        WHERE status = 'notified'
          AND notified_at < ?
    ");
    $expired->execute([$threshold]);
    $expiredRows = $expired->fetchAll();

    foreach ($expiredRows as $row) {
        db()->prepare("UPDATE charger_queue SET status='expired' WHERE id=?")
            ->execute([$row['id']]);

        // Notify next waiting user for this station+connector
        $next = db()->prepare("
            SELECT cq.id, cq.user_id, s.name AS station_name
            FROM charger_queue cq
            JOIN stations s ON s.id = cq.station_id
            WHERE cq.station_id = ? AND cq.connector_type = ? AND cq.status = 'waiting'
            ORDER BY cq.created_at ASC LIMIT 1
        ");
        $next->execute([$row['station_id'], $row['connector_type']]);
        $nextRow = $next->fetch();
        if ($nextRow) {
            db()->prepare("UPDATE charger_queue SET status='notified', notified_at=? WHERE id=?")
                ->execute([$nowSQL, $nextRow['id']]);
            notify($nextRow['user_id'], 'queue_notified', '🔔 Sıranız Geldi!',
                "{$nextRow['station_name']} istasyonunda {$row['connector_type']} şarjcısı hazır! 30 dakika içinde rezervasyon yapın.");
        }
    }
}

// ── GET: my active queue entries with position ─────────────────
if ($method === 'GET') {
    $user = requireRole('driver');

    processExpiredQueue();

    $stmt = db()->prepare("
        SELECT cq.id, cq.station_id, cq.connector_type, cq.status,
               cq.notified_at, cq.created_at,
               s.name AS station_name, s.address AS station_address,
               (SELECT COUNT(*) FROM charger_queue cq2
                WHERE cq2.station_id  = cq.station_id
                  AND cq2.connector_type = cq.connector_type
                  AND cq2.status IN ('waiting','notified')
                  AND cq2.created_at <= cq.created_at) AS position
        FROM charger_queue cq
        JOIN stations s ON s.id = cq.station_id
        WHERE cq.user_id = ? AND cq.status IN ('waiting','notified')
        ORDER BY cq.created_at ASC
    ");
    $stmt->execute([(int)$user['id']]);
    respond($stmt->fetchAll());
}

// ── POST: join queue ───────────────────────────────────────────
if ($method === 'POST') {
    $user = requireRole('driver');
    $b    = body();

    $stationId     = (int)($b['station_id']     ?? 0);
    $connectorType = trim($b['connector_type']  ?? '');
    if (!$stationId || !$connectorType) err('station_id and connector_type required');

    // Already in queue for this station?
    $existing = db()->prepare("
        SELECT id FROM charger_queue
        WHERE user_id = ? AND station_id = ? AND status IN ('waiting','notified')
    ");
    $existing->execute([(int)$user['id'], $stationId]);
    if ($existing->fetch()) err('Zaten bu istasyonda kuyruktasınız.');

    // Insert
    db()->prepare("
        INSERT INTO charger_queue (user_id, station_id, connector_type, status)
        VALUES (?, ?, ?, 'waiting')
    ")->execute([(int)$user['id'], $stationId, $connectorType]);

    // Calculate position
    $pos = db()->prepare("
        SELECT COUNT(*) FROM charger_queue
        WHERE station_id = ? AND connector_type = ? AND status IN ('waiting','notified')
    ");
    $pos->execute([$stationId, $connectorType]);
    $position = (int)$pos->fetchColumn();

    // Confirm notification
    $sName = db()->prepare("SELECT name FROM stations WHERE id=?");
    $sName->execute([$stationId]);
    $stationName = $sName->fetchColumn();

    notify((int)$user['id'], 'queue_joined', 'Kuyruğa Katıldınız',
        "{$stationName} istasyonunda {$position}. sıradasınız. Şarjcı boşaldığında bildirim alacaksınız.");

    respond(['ok' => true, 'position' => $position], 201);
}

// ── DELETE: leave queue ────────────────────────────────────────
if ($method === 'DELETE') {
    $user      = requireRole('driver');
    $stationId = (int)($_GET['station_id'] ?? 0);
    if (!$stationId) err('station_id required');

    db()->prepare("
        UPDATE charger_queue SET status='expired'
        WHERE user_id = ? AND station_id = ? AND status IN ('waiting','notified')
    ")->execute([(int)$user['id'], $stationId]);

    respond(['ok' => true]);
}

err('Method not allowed', 405);
