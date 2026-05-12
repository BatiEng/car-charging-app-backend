<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: current user's vehicles ──
if ($method === 'GET') {
    $user  = requireAuth();
    $stmt  = db()->prepare("SELECT * FROM vehicles WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user['id']]);
    respond($stmt->fetchAll());
}

// ── POST: add vehicle ──
if ($method === 'POST') {
    $user = requireRole('driver', 'admin');
    $b    = body();

    $required = ['brand', 'model', 'battery_kwh', 'connector_type', 'plate'];
    foreach ($required as $f) {
        if (empty($b[$f])) err("Field '$f' is required");
    }
    if (!in_array($b['connector_type'], ['CCS', 'CHAdeMO', 'Type 2'])) err('Invalid connector type');
    if ((int)$b['battery_kwh'] < 10 || (int)$b['battery_kwh'] > 220) err('Battery must be 10–220 kWh');

    // Check plate uniqueness for this user
    $chk = db()->prepare("SELECT id FROM vehicles WHERE plate = ? AND user_id = ?");
    $chk->execute([$b['plate'], $user['id']]);
    if ($chk->fetch()) err('You already registered a vehicle with this plate');

    // Generate a unique 4-digit PIN for the vehicle
    do {
        $pin = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $pinChk = db()->prepare("SELECT id FROM vehicles WHERE vehicle_pin = ?");
        $pinChk->execute([$pin]);
    } while ($pinChk->fetch());

    $stmt = db()->prepare("
        INSERT INTO vehicles (user_id, brand, model, battery_kwh, connector_type, plate, vehicle_pin)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $user['id'],
        $b['brand'],
        $b['model'],
        (int)$b['battery_kwh'],
        $b['connector_type'],
        strtoupper($b['plate']),
        $pin,
    ]);

    $id   = db()->lastInsertId();
    $row  = db()->prepare("SELECT * FROM vehicles WHERE id = ?");
    $row->execute([$id]);
    respond($row->fetch(), 201);
}

// ── PUT: update vehicle ──
if ($method === 'PUT') {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) err('Vehicle id required');

    // Ownership check
    $own = db()->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
    $own->execute([$id, $user['id']]);
    if (!$own->fetch()) err('Vehicle not found', 404);

    $b = body();
    $fields = []; $params = [];

    if (!empty($b['brand']))          { $fields[] = 'brand = ?';          $params[] = $b['brand']; }
    if (!empty($b['model']))          { $fields[] = 'model = ?';          $params[] = $b['model']; }
    if (!empty($b['plate']))          { $fields[] = 'plate = ?';          $params[] = strtoupper($b['plate']); }
    if (!empty($b['battery_kwh']))    { $fields[] = 'battery_kwh = ?';    $params[] = (float)$b['battery_kwh']; }
    if (!empty($b['connector_type'])) { $fields[] = 'connector_type = ?'; $params[] = $b['connector_type']; }

    if (!$fields) err('Nothing to update');
    $params[] = $id;
    db()->prepare("UPDATE vehicles SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);

    $row = db()->prepare("SELECT * FROM vehicles WHERE id = ?");
    $row->execute([$id]);
    respond($row->fetch());
}

// ── DELETE: remove vehicle ──
if ($method === 'DELETE') {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) err('Vehicle id required');

    // Check no active/pending reservations
    $active = db()->prepare("
        SELECT id FROM reservations WHERE vehicle_id = ? AND status IN ('pending','active')
    ");
    $active->execute([$id]);
    if ($active->fetch()) err('Cannot delete – vehicle has active reservations. Cancel them first.');

    $stmt = db()->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);
    respond(['ok' => true]);
}

err('Method not allowed', 405);
