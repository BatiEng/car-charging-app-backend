<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── GET: check whether session can be extended ────────────────
if ($method === 'GET' && $action === 'check_extension') {
    $user   = requireRole('driver');
    $sessId = (int)($_GET['session_id'] ?? 0);
    if (!$sessId) err('session_id required');

    $stmt = db()->prepare("
        SELECT cs.extension_count, cs.user_id, cs.charger_id,
               r.id AS res_id, r.end_time AS res_end, r.reservation_date,
               c.power, c.price_per_kwh
        FROM charging_sessions cs
        JOIN reservations r ON r.id = cs.reservation_id
        JOIN chargers     c ON c.id = cs.charger_id
        WHERE cs.id = ? AND cs.user_id = ? AND cs.status = 'active'
    ");
    $stmt->execute([$sessId, (int)$user['id']]);
    $sess = $stmt->fetch();
    if (!$sess) err('Active session not found', 404);

    if ((int)$sess['extension_count'] >= 1) {
        respond(['can_extend' => false, 'reason' => 'Yalnızca 1 kez uzatma yapabilirsiniz.']);
    }

    // New end = reservation end + 1 hour
    $currentEnd = new DateTime("{$sess['reservation_date']} {$sess['res_end']}");
    $newEnd     = (clone $currentEnd)->modify('+1 hour');

    // Conflict: any pending/active reservation in the next slot
    $conflict = db()->prepare("
        SELECT COUNT(*) FROM reservations
        WHERE charger_id = ? AND reservation_date = ?
          AND status IN ('pending','active')
          AND start_time < ? AND end_time > ?
    ");
    $conflict->execute([
        $sess['charger_id'],
        $sess['reservation_date'],
        $newEnd->format('H:i:s'),
        $currentEnd->format('H:i:s'),
    ]);
    if ((int)$conflict->fetchColumn() > 0) {
        respond(['can_extend' => false, 'reason' => 'Sonraki saat diliminde başka bir rezervasyon var.']);
    }

    $cost = round((float)$sess['power'] * (float)$sess['price_per_kwh'], 2);

    $walStmt = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
    $walStmt->execute([(int)$user['id']]);
    $balance = (float)$walStmt->fetchColumn();

    if ($balance < $cost) {
        respond(['can_extend' => false, 'reason' => "Yetersiz bakiye. Gerekli: {$cost} TL, Mevcut: {$balance} TL"]);
    }

    respond([
        'can_extend'     => true,
        'new_end_time'   => $newEnd->format('Y-m-d H:i:s'),
        'cost'           => $cost,
        'wallet_balance' => $balance,
    ]);
}

// ── GET: session history for current user (or all for admin) ──
if ($method === 'GET') {
    $user = requireAuth();
    if ($user['role'] === 'admin') {
        $stmt = db()->query("
            SELECT cs.*, u.name AS user_name, v.plate, v.brand, v.model,
                   c.type AS charger_type, c.power, s.name AS station_name
            FROM charging_sessions cs
            JOIN users u ON u.id = cs.user_id
            JOIN vehicles v ON v.id = cs.vehicle_id
            JOIN chargers c ON c.id = cs.charger_id
            JOIN stations s ON s.id = c.station_id
            ORDER BY cs.created_at DESC
        ");
    } else {
        $stmt = db()->prepare("
            SELECT cs.*, v.plate, v.brand, v.model,
                   c.type AS charger_type, c.power, c.connector_type, c.price_per_kwh,
                   s.name AS station_name, s.address AS station_address
            FROM charging_sessions cs
            JOIN vehicles v ON v.id = cs.vehicle_id
            JOIN chargers c ON c.id = cs.charger_id
            JOIN stations s ON s.id = c.station_id
            WHERE cs.user_id = ?
            ORDER BY cs.created_at DESC
        ");
        $stmt->execute([$user['id']]);
    }
    respond($stmt->fetchAll());
}

// ── POST: extend active session by 1 hour ────────────────────
if ($method === 'POST' && $action === 'extend') {
    $user   = requireRole('driver');
    $b      = body();
    $sessId = (int)($b['session_id'] ?? 0);
    if (!$sessId) err('session_id required');

    $stmt = db()->prepare("
        SELECT cs.extension_count, cs.charger_id,
               r.id AS res_id, r.end_time AS res_end, r.reservation_date,
               c.power, c.price_per_kwh
        FROM charging_sessions cs
        JOIN reservations r ON r.id = cs.reservation_id
        JOIN chargers     c ON c.id = cs.charger_id
        WHERE cs.id = ? AND cs.user_id = ? AND cs.status = 'active'
    ");
    $stmt->execute([$sessId, (int)$user['id']]);
    $sess = $stmt->fetch();
    if (!$sess) err('Active session not found', 404);
    if ((int)$sess['extension_count'] >= 1) err('Uzatma hakkınız doldu.');

    $currentEnd = new DateTime("{$sess['reservation_date']} {$sess['res_end']}");
    $newEnd     = (clone $currentEnd)->modify('+1 hour');

    $conflict = db()->prepare("
        SELECT COUNT(*) FROM reservations
        WHERE charger_id = ? AND reservation_date = ?
          AND status IN ('pending','active')
          AND start_time < ? AND end_time > ?
    ");
    $conflict->execute([
        $sess['charger_id'],
        $sess['reservation_date'],
        $newEnd->format('H:i:s'),
        $currentEnd->format('H:i:s'),
    ]);
    if ((int)$conflict->fetchColumn() > 0) err('Sonraki saat diliminde başka bir rezervasyon var.');

    $cost = round((float)$sess['power'] * (float)$sess['price_per_kwh'], 2);
    $uid  = (int)$user['id'];

    // Lock row and check balance
    $walStmt = db()->prepare("SELECT wallet_balance FROM users WHERE id=? FOR UPDATE");
    $walStmt->execute([$uid]);
    $balance = (float)$walStmt->fetchColumn();
    if ($balance < $cost) err("Yetersiz bakiye. Gerekli: {$cost} TL");

    $newBal = round($balance - $cost, 2);

    // Deduct wallet
    db()->prepare("UPDATE users SET wallet_balance=? WHERE id=?")->execute([$newBal, $uid]);

    // Wallet transaction record
    db()->prepare("
        INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
        VALUES (?, ?, 'debit', 'Oturum uzatma – 1 saat', ?)
    ")->execute([$uid, $cost, $newBal]);

    // Extend reservation end_time
    db()->prepare("UPDATE reservations SET end_time=? WHERE id=?")
        ->execute([$newEnd->format('H:i:s'), $sess['res_id']]);

    // Mark extension used
    db()->prepare("UPDATE charging_sessions SET extension_count=1 WHERE id=?")
        ->execute([$sessId]);

    respond([
        'ok'          => true,
        'new_end_time'=> $newEnd->format('Y-m-d H:i:s'),
        'cost'        => $cost,
        'new_balance' => $newBal,
    ]);
}

// ── POST: start session (enter vehicle PIN) ──
if ($method === 'POST') {
    $user = requireRole('driver', 'admin');
    $b    = body();
    $uid  = (int)$user['id'];
    $pin  = trim($b['vehicle_pin'] ?? '');
    $resId = (int)($b['reservation_id'] ?? 0);

    if (!$pin || !$resId) err('vehicle_pin and reservation_id required');

    // Load reservation with vehicle + charger
    $stmt = db()->prepare("
        SELECT r.*, v.vehicle_pin, v.plate, v.brand, v.model, v.battery_kwh,
               c.price_per_kwh, c.power AS charger_power, c.charger_code,
               c.station_id,
               s.name AS station_name
        FROM reservations r
        JOIN vehicles v ON v.id = r.vehicle_id
        JOIN chargers c ON c.id = r.charger_id
        JOIN stations s ON s.id = c.station_id
        WHERE r.id = ? AND r.user_id = ?
    ");
    $stmt->execute([$resId, $uid]);
    $res = $stmt->fetch();

    if (!$res) err('Reservation not found', 404);
    if ($res['status'] !== 'pending') err("Reservation is {$res['status']}, not pending");

    // Verify PIN
    if ($res['vehicle_pin'] !== $pin) err('Incorrect vehicle PIN');

    // Time window check: allow session to start within ±15 min of reservation start
    $resStart = new DateTime("{$res['reservation_date']} {$res['start_time']}");
    $resEnd   = new DateTime("{$res['reservation_date']} {$res['end_time']}");
    $now      = demoNow();
    $early    = (clone $resStart)->modify('-15 minutes');
    if ($now < $early) {
        $mins = (int)(($resStart->getTimestamp() - $now->getTimestamp()) / 60);
        err("Too early. Your reservation starts in {$mins} minutes. You can check in 15 minutes before.");
    }
    if ($now > $resEnd) err('Reservation time has expired');

    // Mark charger occupied
    db()->prepare("UPDATE chargers SET status='occupied' WHERE id=?")->execute([$res['charger_id']]);

    // Mark reservation active
    db()->prepare("UPDATE reservations SET status='active' WHERE id=?")->execute([$resId]);

    // Create session record
    $nowSQL = demoNowSQL();
    $ins = db()->prepare("
        INSERT INTO charging_sessions
            (reservation_id, user_id, vehicle_id, charger_id, start_time, status)
        VALUES (?,?,?,?,?,'active')
    ");
    $ins->execute([$resId, $uid, $res['vehicle_id'], $res['charger_id'], $nowSQL]);
    $sessId = db()->lastInsertId();

    notify($uid, 'session_started', 'Charging Started',
        "Your charging session at {$res['station_name']} has started. Session ID: #{$sessId}."
    );

    respond(['session_id' => (int)$sessId, 'started_at' => $nowSQL], 201);
}

// ── PATCH: mark charger as overstay ──────────────────────────
if ($method === 'PATCH') {
    $user   = requireAuth();
    $pAction = $_GET['action'] ?? '';
    $id     = (int)($_GET['id'] ?? 0);
    if (!$id) err('Session id required');

    if ($pAction === 'mark_overstay') {
        $stmt = db()->prepare("
            SELECT cs.charger_id FROM charging_sessions cs
            WHERE cs.id = ? AND cs.user_id = ? AND cs.status = 'active'
        ");
        $stmt->execute([$id, (int)$user['id']]);
        $sess = $stmt->fetch();
        if (!$sess) err('Active session not found', 404);
        db()->prepare("UPDATE chargers SET status='overstay' WHERE id=?")
            ->execute([$sess['charger_id']]);
        respond(['ok' => true]);
    }

    err('Unknown patch action', 400);
}

// ── PUT: end session ──
if ($method === 'PUT') {
    $user   = requireAuth();
    $id     = (int)($_GET['id'] ?? 0);
    if (!$id) err('Session id required');

    $stmt = db()->prepare("
        SELECT cs.*, c.price_per_kwh, c.power AS charger_power, v.battery_kwh,
               r.amount_deducted, r.estimated_cost,
               s.name AS station_name, s.address
        FROM charging_sessions cs
        JOIN chargers c  ON c.id  = cs.charger_id
        JOIN vehicles v  ON v.id  = cs.vehicle_id
        JOIN reservations r ON r.id = cs.reservation_id
        JOIN stations s  ON s.id  = c.station_id
        WHERE cs.id = ?
    ");
    $stmt->execute([$id]);
    $sess = $stmt->fetch();
    if (!$sess) err('Session not found', 404);
    if ((int)$sess['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') err('Forbidden', 403);
    if ($sess['status'] === 'completed') err('Session already completed');

    $b              = body();
    $kwhCharged     = round((float)($b['kwh_consumed']    ?? 0), 3);
    $overstayMins   = max(0, (int)($b['overstay_minutes'] ?? 0));
    $endTime        = demoNowSQL();
    $totalCost      = round($kwhCharged * (float)$sess['price_per_kwh'], 2);
    $uid            = (int)$sess['user_id'];

    // Penalty rate: 2 TL per simulated overstay minute
    $penaltyRate    = 2.00;
    $overstayPenalty = round($overstayMins * $penaltyRate, 2);

    // Refund difference if actual < estimated (energy part only)
    $refund = max(0, round((float)$sess['amount_deducted'] - $totalCost, 2));
    if ($refund > 0) {
        db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id=?")->execute([$refund, $uid]);
        $newBal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
        $newBal->execute([$uid]);
        $balance = (float)$newBal->fetchColumn();
        db()->prepare("
            INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
            VALUES (?,?,'credit','Refund – unused charging time',?)
        ")->execute([$uid, $refund, $balance]);
        if ((int)$uid === (int)$user['id']) $_SESSION['user']['wallet_balance'] = $balance;
    }

    // Deduct overstay penalty
    if ($overstayPenalty > 0) {
        db()->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id=?")->execute([$overstayPenalty, $uid]);
        $penaltyBal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
        $penaltyBal->execute([$uid]);
        $balAfterPenalty = (float)$penaltyBal->fetchColumn();
        db()->prepare("
            INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
            VALUES (?,?,'debit','Overstay cezası – {$overstayMins} dakika',?)
        ")->execute([$uid, $overstayPenalty, $balAfterPenalty]);
        if ((int)$uid === (int)$user['id']) $_SESSION['user']['wallet_balance'] = $balAfterPenalty;
        checkWalletAlert($uid, $balAfterPenalty);
    }

    // Receipt JSON
    $vehicle = db()->prepare("SELECT * FROM vehicles WHERE id=?");
    $vehicle->execute([$sess['vehicle_id']]);
    $v = $vehicle->fetch();

    $receipt = [
        'session_id'      => $id,
        'station'         => $sess['station_name'],
        'address'         => $sess['address'],
        'vehicle'         => "{$v['brand']} {$v['model']} ({$v['plate']})",
        'start_time'      => $sess['start_time'],
        'end_time'        => $endTime,
        'kwh_consumed'    => $kwhCharged,
        'price_per_kwh'   => (float)$sess['price_per_kwh'],
        'total_cost'      => $totalCost,
        'refund'          => $refund,
        'overstay_minutes'=> $overstayMins,
        'overstay_penalty'=> $overstayPenalty,
        'generated_at'    => demoNowSQL(),
    ];

    // Update session
    db()->prepare("
        UPDATE charging_sessions
        SET status='completed', end_time=?, kwh_consumed=?, total_cost=?, receipt_data=?,
            overstay_minutes=?, overstay_penalty=?
        WHERE id=?
    ")->execute([$endTime, $kwhCharged, $totalCost, json_encode($receipt), $overstayMins, $overstayPenalty, $id]);

    // Update charger & reservation
    db()->prepare("UPDATE chargers SET status='available' WHERE id=?")->execute([$sess['charger_id']]);
    db()->prepare("UPDATE reservations SET status='completed' WHERE id=?")->execute([$sess['reservation_id']]);

    // ── Notify first person waiting in queue for this connector type ──
    $queueCheck = db()->prepare("
        SELECT cq.id, cq.user_id, s.name AS station_name, ch.connector_type
        FROM charger_queue cq
        JOIN chargers  ch ON ch.station_id = cq.station_id
                         AND ch.connector_type = cq.connector_type
        JOIN stations  s  ON s.id = cq.station_id
        WHERE ch.id = ? AND cq.status = 'waiting'
        ORDER BY cq.created_at ASC
        LIMIT 1
    ");
    $queueCheck->execute([$sess['charger_id']]);
    $queueEntry = $queueCheck->fetch();
    if ($queueEntry) {
        db()->prepare("UPDATE charger_queue SET status='notified', notified_at=? WHERE id=?")
            ->execute([demoNowSQL(), $queueEntry['id']]);
        notify($queueEntry['user_id'], 'queue_notified', '🔔 Sıranız Geldi!',
            "{$queueEntry['station_name']} istasyonunda {$queueEntry['connector_type']} şarjcısı boşaldı! 30 dakika içinde rezervasyon yapın.");
    }

    $notifyMsg = "Session at {$sess['station_name']} finished. Energy: {$kwhCharged} kWh · Cost: {$totalCost} TL.";
    if ($overstayPenalty > 0) {
        $notifyMsg .= " Overstay penalty: {$overstayPenalty} TL ({$overstayMins} min).";
    }
    notify($uid, 'session_completed', 'Charging Complete', $notifyMsg);

    respond(['ok' => true, 'receipt' => $receipt]);
}

err('Method not allowed', 405);
