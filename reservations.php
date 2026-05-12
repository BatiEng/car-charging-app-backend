<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── Auto-cancel expired pending reservations (no refund) ──────
function autoCancelExpired() {
    $nowSQL = demoNowSQL();
    $stmt = db()->prepare("
        SELECT id, user_id
        FROM reservations
        WHERE status = 'pending'
          AND TIMESTAMP(reservation_date, end_time) < ?
    ");
    $stmt->execute([$nowSQL]);
    $expired = $stmt->fetchAll();
    if (empty($expired)) return;

    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $expired));
    db()->exec("UPDATE reservations SET status = 'cancelled' WHERE id IN ($ids)");
}

// ── GET: reservations ──
if ($method === 'GET') {
    $user = requireAuth();
    autoCancelExpired();

    if ($user['role'] === 'admin') {
        $stmt = db()->query("
            SELECT r.*, u.name AS user_name, v.plate, v.brand, v.model,
                   c.type AS charger_type, c.power AS charger_power, c.connector_type,
                   s.name AS station_name
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN vehicles v ON v.id = r.vehicle_id
            JOIN chargers c ON c.id = r.charger_id
            JOIN stations s ON s.id = c.station_id
            ORDER BY r.created_at DESC
        ");
    } else {
        $stmt = db()->prepare("
            SELECT r.*, v.plate, v.brand, v.model,
                   c.type AS charger_type, c.power AS charger_power, c.connector_type, c.charger_code,
                   s.name AS station_name, s.address AS station_address, s.lat, s.lng
            FROM reservations r
            JOIN vehicles v ON v.id = r.vehicle_id
            JOIN chargers c ON c.id = r.charger_id
            JOIN stations s ON s.id = c.station_id
            WHERE r.user_id = ?
            ORDER BY r.reservation_date DESC, r.start_time DESC
        ");
        $stmt->execute([$user['id']]);
    }
    respond($stmt->fetchAll());
}

// ── POST: create reservation ──
if ($method === 'POST') {
    $user = requireRole('driver', 'admin');
    $b    = body();
    $uid  = (int)$user['id'];

    $vehicleId  = (int)($b['vehicle_id']  ?? 0);
    $chargerId  = (int)($b['charger_id']  ?? 0);
    $date       = $b['date']       ?? '';
    $startTime  = $b['start_time'] ?? '';   // "14:00"
    $duration   = (int)($b['duration'] ?? 0); // hours

    if (!$vehicleId || !$chargerId || !$date || !$startTime || $duration < 1) {
        err('Missing required fields');
    }
    if ($duration > 2) err('Maximum reservation duration is 2 hours');

    // Compute end time (supports HH:MM format)
    [$h, $m] = explode(':', $startTime);
    $endMinutes = (int)$h * 60 + (int)$m + $duration * 60;
    $endTime    = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

    // Max 24h in advance
    $reservationDT = new DateTime("$date $startTime");
    $now           = demoNow();
    $diff          = $now->diff($reservationDT)->h + ($now->diff($reservationDT)->days * 24);
    if ($reservationDT < $now) err('Reservation time is in the past');
    if ($diff > 24)            err('Cannot reserve more than 24 hours in advance');

    // Charger must be available (not offline, not occupied)
    $charger = db()->prepare("SELECT c.*, s.status AS station_status, s.name AS station_name FROM chargers c JOIN stations s ON s.id = c.station_id WHERE c.id = ?");
    $charger->execute([$chargerId]);
    $ch = $charger->fetch();
    if (!$ch) err('Şarjcı bulunamadı.');

    // İstasyon durumu kontrolü
    if ($ch['station_status'] === 'maintenance') err("'{$ch['station_name']}' istasyonu şu anda bakımda, rezervasyon yapılamaz.");
    if ($ch['station_status'] === 'inactive')    err("'{$ch['station_name']}' istasyonu şu anda pasif, rezervasyon yapılamaz.");

    // Şarjcı durumu kontrolü
    if ($ch['status'] === 'offline')  err('Bu şarjcı şu anda çevrimdışı, rezervasyon yapılamaz.');
    if ($ch['status'] === 'occupied') err('Bu şarjcı şu anda meşgul, rezervasyon yapılamaz.');

    // Vehicle belongs to user?
    $veh = db()->prepare("SELECT * FROM vehicles WHERE id = ? AND user_id = ?");
    $veh->execute([$vehicleId, $uid]);
    $v = $veh->fetch();
    if (!$v) err('Vehicle not found');

    // Connector compatibility
    if ($v['connector_type'] !== $ch['connector_type']) {
        err("Connector mismatch: your vehicle has {$v['connector_type']}, charger supports {$ch['connector_type']}");
    }

    // ── Double booking #1: same charger, overlapping time ──
    $overlap = db()->prepare("
        SELECT id FROM reservations
        WHERE charger_id = ?
          AND reservation_date = ?
          AND status IN ('pending','active')
          AND NOT (end_time <= ? OR start_time >= ?)
    ");
    $overlap->execute([$chargerId, $date, $startTime, $endTime]);
    if ($overlap->fetch()) err('This charger is already booked for the selected time slot');

    // ── Double booking #2: same vehicle, any reservation (only 1 allowed) ──
    $vehBook = db()->prepare("
        SELECT id FROM reservations
        WHERE vehicle_id = ? AND status IN ('pending','active')
    ");
    $vehBook->execute([$vehicleId]);
    if ($vehBook->fetch()) err('This vehicle already has an active reservation. Cancel it first.');

    // Estimated cost = charger power * duration * price
    $estimatedCost = round($ch['power'] * $duration * $ch['price_per_kwh'], 2);

    // Check wallet balance
    $balRow = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $balRow->execute([$uid]);
    $balance = (float)$balRow->fetchColumn();
    if ($balance < $estimatedCost) {
        err("Insufficient wallet balance. You need {$estimatedCost} TL but have {$balance} TL. Please top up.");
    }

    // Deduct from wallet
    db()->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?")->execute([$estimatedCost, $uid]);

    $newBal = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $newBal->execute([$uid]);
    $balance = (float)$newBal->fetchColumn();

    // Record wallet transaction
    db()->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
        VALUES (?,?,'debit',?,?)
    ")->execute([$uid, $estimatedCost, "Rezervasyon – {$ch['station_name']} / {$ch['charger_code']}", $balance]);

    // Update session wallet
    $_SESSION['user']['wallet_balance'] = $balance;

    // Insert reservation
    $ins = db()->prepare("
        INSERT INTO reservations
            (user_id, vehicle_id, charger_id, reservation_date, start_time, end_time, duration, estimated_cost, amount_deducted)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([$uid, $vehicleId, $chargerId, $date, $startTime, $endTime, $duration, $estimatedCost, $estimatedCost]);
    $resId = db()->lastInsertId();

    // Notify user
    $sName = db()->prepare("SELECT s.name FROM stations s JOIN chargers c ON c.station_id=s.id WHERE c.id=?");
    $sName->execute([$chargerId]);
    $stationName = $sName->fetchColumn();

    notify($uid, 'reservation_confirmed', 'Reservation Confirmed',
        "Your reservation at {$stationName} on {$date} {$startTime}–{$endTime} is confirmed. Estimated cost: {$estimatedCost} TL."
    );

    // Low balance check
    checkWalletAlert($uid, $balance);

    // Return full reservation
    $row = db()->prepare("
        SELECT r.*, c.charger_code, c.power AS charger_power, c.type AS charger_type,
               c.connector_type, s.name AS station_name, s.address AS station_address, s.lat, s.lng
        FROM reservations r
        JOIN chargers c ON c.id = r.charger_id
        JOIN stations s ON s.id = c.station_id
        WHERE r.id = ?
    ");
    $row->execute([$resId]);
    respond(array_merge($row->fetch(), ['wallet_balance' => $balance]), 201);
}

// ── DELETE: cancel reservation ──
if ($method === 'DELETE') {
    $user  = requireAuth();
    $id    = (int)($_GET['id'] ?? 0);
    if (!$id) err('Reservation id required');

    $stmt = db()->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([$id]);
    $res = $stmt->fetch();
    if (!$res) err('Not found', 404);
    if ((int)$res['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') err('Forbidden', 403);
    if ($res['status'] === 'active')    err('Aktif şarj seansı iptal edilemez.');
    if ($res['status'] === 'completed') err('Tamamlanmış rezervasyon iptal edilemez.');
    if ($res['status'] === 'cancelled') err('Rezervasyon zaten iptal edilmiş.');

    // ── 3 saat kuralı (sadece kullanıcı için, admin geçebilir) ──
    if ($user['role'] !== 'admin') {
        $resDateTime = new DateTime($res['reservation_date'] . ' ' . $res['start_time']);
        $now         = demoNow();
        $diffMins    = ($resDateTime->getTimestamp() - $now->getTimestamp()) / 60;
        if ($diffMins < 180) {
            err('Rezervasyon başlangıcına 3 saatten az kaldığı için iptal edilemez.');
        }
    }

    db()->prepare("UPDATE reservations SET status='cancelled' WHERE id=?")->execute([$id]);

    // Full refund
    $refund = (float)$res['amount_deducted'];
    db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")->execute([$refund, $res['user_id']]);
    $newBal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $newBal->execute([$res['user_id']]);
    $balance = (float)$newBal->fetchColumn();

    db()->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
        VALUES (?,?,'credit','Refund – reservation cancelled',?)
    ")->execute([$res['user_id'], $refund, $balance]);

    if ((int)$res['user_id'] === (int)$user['id']) {
        $_SESSION['user']['wallet_balance'] = $balance;
    }

    notify((int)$res['user_id'], 'reservation_cancelled', 'Reservation Cancelled',
        "Your reservation on {$res['reservation_date']} at {$res['start_time']} has been cancelled. {$refund} TL refunded to your wallet."
    );

    respond(['ok' => true, 'refunded' => $refund, 'wallet_balance' => $balance]);
}

err('Method not allowed', 405);
