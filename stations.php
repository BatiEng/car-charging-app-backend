<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET: kullanıcının en çok kullandığı istasyon ──
if ($method === 'GET' && $action === 'my_top') {
    $user = requireAuth();
    $stmt = db()->prepare("
        SELECT s.id, s.name, s.address, s.lat, s.lng, s.status,
               COUNT(cs.id) AS session_count
        FROM charging_sessions cs
        JOIN chargers c ON c.id = cs.charger_id
        JOIN stations s ON s.id = c.station_id
        WHERE cs.user_id = ? AND cs.status = 'completed'
        GROUP BY s.id
        ORDER BY session_count DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $top = $stmt->fetch();
    if ($top) {
        $top['lat']           = (float)$top['lat'];
        $top['lng']           = (float)$top['lng'];
        $top['session_count'] = (int)$top['session_count'];
    }
    respond($top ?: null);
}

// ── GET: kullanıcının favori istasyonları ──
if ($method === 'GET' && $action === 'my_favorites') {
    $user = requireAuth();
    $stmt = db()->prepare("
        SELECT s.id, s.name, s.address, s.lat, s.lng, s.status,
               sf.created_at AS favorited_at
        FROM station_favorites sf
        JOIN stations s ON s.id = sf.station_id
        WHERE sf.user_id = ?
        ORDER BY sf.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['lat'] = (float)$r['lat'];
        $r['lng'] = (float)$r['lng'];
        $r['id']  = (int)$r['id'];
    }
    respond($rows);
}

// ── POST: favori ekle ──
if ($method === 'POST' && $action === 'favorite') {
    $user       = requireAuth();
    $b          = body();
    $station_id = (int)($b['station_id'] ?? 0);
    if (!$station_id) err('station_id gerekli');

    $check = db()->prepare("SELECT id FROM stations WHERE id = ?");
    $check->execute([$station_id]);
    if (!$check->fetch()) err('İstasyon bulunamadı', 404);

    $exists = db()->prepare("SELECT id FROM station_favorites WHERE user_id = ? AND station_id = ?");
    $exists->execute([$user['id'], $station_id]);
    if ($exists->fetch()) respond(['ok' => true, 'already' => true]);

    $stmt = db()->prepare("INSERT INTO station_favorites (user_id, station_id) VALUES (?,?)");
    $stmt->execute([$user['id'], $station_id]);
    respond(['ok' => true, 'added' => true], 201);
}

// ── DELETE: favoriyi kaldır ──
if ($method === 'DELETE' && $action === 'favorite') {
    $user       = requireAuth();
    $station_id = (int)($_GET['station_id'] ?? 0);
    if (!$station_id) err('station_id gerekli');

    $stmt = db()->prepare("DELETE FROM station_favorites WHERE user_id = ? AND station_id = ?");
    $stmt->execute([$user['id'], $station_id]);
    respond(['ok' => true, 'removed' => true]);
}

// ── GET all stations with their chargers ──
if ($method === 'GET') {
    $stmtS = db()->query("
        SELECT s.*, u.name AS operator_name
        FROM stations s
        LEFT JOIN users u ON u.id = s.operator_id
        ORDER BY s.id
    ");
    $stations = $stmtS->fetchAll();

    $stmtC = db()->query("SELECT * FROM chargers ORDER BY station_id, id");
    $chargers = $stmtC->fetchAll();

    // Operator/admin/technician charger_code görebilir
    $authUser = currentUser();
    $showCode = $authUser && in_array($authUser['role'], ['operator','admin','technician']);

    $byStation = [];
    foreach ($chargers as $c) {
        $sid = $c['station_id'];
        if (!$showCode) unset($c['charger_code']);
        $byStation[$sid][] = $c;
    }

    foreach ($stations as &$s) {
        $s['chargers'] = $byStation[$s['id']] ?? [];
        $s['lat']      = (float)$s['lat'];
        $s['lng']      = (float)$s['lng'];
        $s['id']       = (int)$s['id'];
    }

    respond($stations);
}

// ── POST: create new station (admin only) ──
if ($method === 'POST') {
    $user = requireRole('admin');
    $b    = body();

    $name        = trim($b['name']    ?? '');
    $address     = trim($b['address'] ?? '');
    $lat         = (float)($b['lat']  ?? 0);
    $lng         = (float)($b['lng']  ?? 0);
    $status      = $b['status']       ?? 'active';
    $operator_id = (isset($b['operator_id']) && $b['operator_id'] !== '') ? (int)$b['operator_id'] : null;

    if (!$name || !$address || !$lat || !$lng) err('Ad, adres ve konum zorunlu');
    if (!in_array($status, ['active','inactive','maintenance'])) err('Geçersiz durum');

    $stmt = db()->prepare("INSERT INTO stations (name, address, lat, lng, status, operator_id) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$name, $address, $lat, $lng, $status, $operator_id]);
    respond(['ok' => true, 'id' => (int)db()->lastInsertId()], 201);
}

// ── PUT: update station info (operator updates own station, admin updates any) ──
if ($method === 'PUT') {
    $user = requireRole('operator', 'admin');
    $b    = body();
    $id   = (int)($_GET['id'] ?? $b['id'] ?? 0);
    if (!$id) err('Station id required');

    // Operators can only edit their own station
    if ($user['role'] === 'operator') {
        $row = db()->prepare("SELECT operator_id FROM stations WHERE id = ?");
        $row->execute([$id]);
        $st = $row->fetch();
        if (!$st || (int)$st['operator_id'] !== (int)$user['id']) err('Forbidden', 403);
    }

    $fields  = [];
    $params  = [];
    $allowed = ['name', 'address', 'status', 'rating', 'lat', 'lng'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $b)) { $fields[] = "$f = ?"; $params[] = $b[$f]; }
    }
    if (!$fields) err('Nothing to update');
    $params[] = $id;

    db()->prepare("UPDATE stations SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    respond(['ok' => true]);
}

err('Method not allowed', 405);
