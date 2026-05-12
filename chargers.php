<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── POST: create charger (admin or operator of that station) ──
if ($method === 'POST') {
    $user = requireRole('admin', 'operator');
    $b    = body();

    $station_id     = (int)($b['station_id']      ?? 0);
    $charger_code   = trim($b['charger_code']     ?? '');
    $type           = $b['type']                  ?? 'AC';
    $power          = (float)($b['power']         ?? 0);
    $connector_type = trim($b['connector_type']   ?? '');
    $price_per_kwh  = (float)($b['price_per_kwh'] ?? 3.50);

    if (!$station_id || !$charger_code || !$power || !$connector_type) err('Tüm alanlar zorunlu');
    if (!in_array($type, ['AC','DC'])) err('Geçersiz tip');

    // Operator can only add to their own station
    if ($user['role'] === 'operator') {
        $row = db()->prepare("SELECT id FROM stations WHERE id = ? AND operator_id = ?");
        $row->execute([$station_id, $user['id']]);
        if (!$row->fetch()) err('Forbidden', 403);
    }

    db()->prepare("
        INSERT INTO chargers (station_id, charger_code, type, power, connector_type, price_per_kwh)
        VALUES (?,?,?,?,?,?)
    ")->execute([$station_id, $charger_code, $type, $power, $connector_type, $price_per_kwh]);

    respond(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
}

// ── PUT: update charger fields (admin or owning operator) ──
if ($method === 'PUT') {
    $user = requireRole('operator', 'admin');
    $id   = (int)($_GET['id'] ?? 0);
    $b    = body();
    if (!$id) err('Charger id required');

    if ($user['role'] === 'operator') {
        $row = db()->prepare("SELECT c.id FROM chargers c JOIN stations s ON s.id=c.station_id WHERE c.id=? AND s.operator_id=?");
        $row->execute([$id, $user['id']]);
        if (!$row->fetch()) err('Forbidden', 403);
    }

    $fields = []; $params = [];
    $allowed = ['charger_code', 'type', 'power', 'connector_type', 'price_per_kwh', 'status'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $b)) { $fields[] = "$f = ?"; $params[] = $b[$f]; }
    }
    if (!$fields) err('Nothing to update');
    $params[] = $id;
    db()->prepare("UPDATE chargers SET " . implode(', ', $fields) . " WHERE id=?")->execute($params);
    respond(['ok' => true]);
}

// ── PATCH: update charger status ──
if ($method === 'PATCH') {
    $user   = requireRole('operator', 'technician', 'admin');
    $b      = body();
    $id     = (int)($_GET['id'] ?? $b['id'] ?? 0); // id query param veya body'den
    $status = $b['status'] ?? '';

    if (!$id) err('Charger id required');
    if (!in_array($status, ['available', 'occupied', 'offline'])) err('Invalid status');

    if ($user['role'] === 'operator') {
        $row = db()->prepare("
            SELECT c.id FROM chargers c
            JOIN stations s ON s.id = c.station_id
            WHERE c.id = ? AND s.operator_id = ?
        ");
        $row->execute([$id, $user['id']]);
        if (!$row->fetch()) err('Forbidden', 403);
    }

    db()->prepare("UPDATE chargers SET status = ? WHERE id = ?")->execute([$status, $id]);

    if ($status === 'offline') {
        $future = db()->prepare("
            SELECT r.user_id, r.id AS res_id, r.reservation_date, r.start_time,
                   s.name AS station_name
            FROM reservations r
            JOIN chargers c ON c.id = r.charger_id
            JOIN stations s ON s.id = c.station_id
            WHERE r.charger_id = ?
              AND r.status = 'pending'
              AND (r.reservation_date > CURDATE()
                   OR (r.reservation_date = CURDATE() AND r.start_time > CURTIME()))
        ");
        $future->execute([$id]);
        foreach ($future->fetchAll() as $res) {
            notify((int)$res['user_id'], 'station_malfunction', 'Station Issue Reported',
                "The charger at {$res['station_name']} for your reservation on {$res['reservation_date']} at {$res['start_time']} has been taken offline. Your reservation has been cancelled and the full amount refunded."
            );
            $stmt = db()->prepare("SELECT estimated_cost, user_id FROM reservations WHERE id = ?");
            $stmt->execute([$res['res_id']]);
            $r = $stmt->fetch();
            db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$res['res_id']]);
            db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$r['estimated_cost'], $r['user_id']]);
            db()->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
                           SELECT ?, ?, 'credit', 'Refund – charger offline', wallet_balance FROM users WHERE id = ?"
            )->execute([$r['user_id'], $r['estimated_cost'], $r['user_id']]);
        }
    }

    respond(['ok' => true]);
}

// ── DELETE: remove charger (admin or owning operator) ──
if ($method === 'DELETE') {
    $user = requireRole('operator', 'admin');
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) err('Charger id required');

    if ($user['role'] === 'operator') {
        $row = db()->prepare("SELECT c.id FROM chargers c JOIN stations s ON s.id=c.station_id WHERE c.id=? AND s.operator_id=?");
        $row->execute([$id, $user['id']]);
        if (!$row->fetch()) err('Forbidden', 403);
    }

    db()->prepare("DELETE FROM chargers WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

// ── GET charger detail (operator/admin/technician) ──
if ($method === 'GET') {
    $user = requireRole('operator', 'technician', 'admin');
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) err('Charger id required');

    $stmt = db()->prepare("SELECT * FROM chargers WHERE id = ?");
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) err('Not found', 404);
    respond($c);
}

err('Method not allowed', 405);
