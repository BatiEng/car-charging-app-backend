<?php
require_once __DIR__ . '/cors.php';
$user = requireRole('admin');

$method = $_SERVER['REQUEST_METHOD'];
$type   = $_GET['type'] ?? '';

// ── GET various report types ──
if ($method === 'GET') {

    // User list
    if ($type === 'users') {
        $stmt = db()->query("
            SELECT id, name, email, role, wallet_balance, created_at
            FROM users ORDER BY created_at DESC
        ");
        respond($stmt->fetchAll());
    }

    // Technician list (for issue assignment)
    if ($type === 'technicians') {
        $stmt = db()->query("
            SELECT id, name, email
            FROM users
            WHERE role = 'technician'
            ORDER BY name ASC
        ");
        respond($stmt->fetchAll());
    }

    // Station + full charger list
    if ($type === 'stations') {
        $stmtS = db()->query("
            SELECT s.*, u.name AS operator_name
            FROM stations s
            LEFT JOIN users u ON u.id = s.operator_id
            ORDER BY s.id
        ");
        $stations = $stmtS->fetchAll();

        $stmtC = db()->query("SELECT * FROM chargers ORDER BY station_id, id");
        $byStation = [];
        foreach ($stmtC->fetchAll() as $c) {
            $byStation[$c['station_id']][] = $c;
        }

        foreach ($stations as &$s) {
            $sid = $s['id'];
            $chs = $byStation[$sid] ?? [];
            $s['chargers']       = $chs;
            $s['charger_count']  = count($chs);
            $s['available_count']= count(array_filter($chs, fn($c) => $c['status'] === 'available'));
            $s['lat'] = (float)$s['lat'];
            $s['lng'] = (float)$s['lng'];
        }
        respond($stations);
    }

    // All reservations
    if ($type === 'reservations') {
        $stmt = db()->query("
            SELECT r.*, u.name AS user_name, v.plate, v.brand, v.model,
                   c.type AS charger_type, c.power, c.connector_type,
                   s.name AS station_name
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN vehicles v ON v.id = r.vehicle_id
            JOIN chargers c ON c.id = r.charger_id
            JOIN stations s ON s.id = c.station_id
            ORDER BY r.created_at DESC
        ");
        respond($stmt->fetchAll());
    }

    // Charging sessions + receipts
    if ($type === 'sessions') {
        $stmt = db()->query("
            SELECT cs.*, u.name AS user_name, v.plate, v.brand, v.model,
                   s.name AS station_name
            FROM charging_sessions cs
            JOIN users u ON u.id = cs.user_id
            JOIN vehicles v ON v.id = cs.vehicle_id
            JOIN chargers c ON c.id = cs.charger_id
            JOIN stations s ON s.id = c.station_id
            ORDER BY cs.created_at DESC
        ");
        respond($stmt->fetchAll());
    }

    // Revenue summary
    if ($type === 'revenue') {
        $total = db()->query("SELECT COALESCE(SUM(total_cost),0) AS total FROM charging_sessions WHERE status='completed'")->fetchColumn();
        $monthly = db()->query("
            SELECT DATE_FORMAT(start_time,'%Y-%m') AS month, SUM(total_cost) AS revenue, COUNT(*) AS sessions
            FROM charging_sessions WHERE status='completed'
            GROUP BY month ORDER BY month DESC LIMIT 12
        ")->fetchAll();
        $byStation = db()->query("
            SELECT s.name, SUM(cs.total_cost) AS revenue, COUNT(*) AS sessions
            FROM charging_sessions cs
            JOIN chargers c ON c.id=cs.charger_id
            JOIN stations s ON s.id=c.station_id
            WHERE cs.status='completed'
            GROUP BY s.id ORDER BY revenue DESC
        ")->fetchAll();
        respond(compact('total', 'monthly', 'byStation'));
    }

    // İstasyon kullanım raporu (son 30 gün)
    if ($type === 'station_usage') {

        // İstasyon başına rezervasyon sayısı + günlük kullanım yüzdesi
        // Formül: rez sayısı / (aktif gün × şarjcı sayısı × 14 çalışma saati) × 100
        $stations = db()->query("
            SELECT
                s.id, s.name,
                (SELECT COUNT(*) FROM chargers WHERE station_id = s.id) AS charger_count,
                COUNT(DISTINCT r.reservation_date)                       AS active_days,
                COUNT(r.id)                                              AS total_reservations,
                ROUND(
                    COUNT(r.id) / GREATEST(
                        COUNT(DISTINCT r.reservation_date)
                        * GREATEST((SELECT COUNT(*) FROM chargers WHERE station_id = s.id), 1)
                        * 14,
                        1
                    ) * 100, 1
                ) AS usage_pct
            FROM stations s
            LEFT JOIN chargers c    ON c.station_id = s.id
            LEFT JOIN reservations r ON r.charger_id = c.id
                AND r.status IN ('completed','active','pending')
                AND r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY s.id
            ORDER BY usage_pct DESC
        ")->fetchAll();

        // Her istasyon için en yoğun saat
        $hourRows = db()->query("
            SELECT
                s.id AS station_id,
                HOUR(r.start_time) AS hour,
                COUNT(*)           AS cnt
            FROM reservations r
            JOIN chargers c ON c.id = r.charger_id
            JOIN stations s ON s.id = c.station_id
            WHERE r.status IN ('completed','active','pending')
              AND r.reservation_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY s.id, HOUR(r.start_time)
            ORDER BY s.id, cnt DESC
        ")->fetchAll();

        // Her istasyon için ilk (en yoğun) saati al
        $peakByStation = [];
        foreach ($hourRows as $h) {
            $sid = $h['station_id'];
            if (!isset($peakByStation[$sid])) {
                $peakByStation[$sid] = (int)$h['hour'];
            }
        }

        foreach ($stations as &$s) {
            $sid = $s['id'];
            if (isset($peakByStation[$sid])) {
                $ph = $peakByStation[$sid];
                $s['peak_hour_range'] = sprintf('%02d:00 – %02d:00', $ph, $ph + 1);
            } else {
                $s['peak_hour_range'] = 'Veri yok';
            }
            $s['usage_pct']     = (float)$s['usage_pct'];
            $s['charger_count'] = (int)$s['charger_count'];
        }

        respond($stations);
    }

    // All vehicles
    if ($type === 'vehicles') {
        $stmt = db()->query("
            SELECT v.*, u.name AS owner_name, u.email AS owner_email
            FROM vehicles v JOIN users u ON u.id=v.user_id
            ORDER BY v.created_at DESC
        ");
        respond($stmt->fetchAll());
    }

    err("Unknown report type '$type'");
}

// ── PUT: update entity (user / station / reservation) ──
if ($method === 'PUT') {
    $b      = body();
    $entity = $b['entity'] ?? 'user';

    // ── Update station ──
    if ($entity === 'station') {
        $id = (int)($b['id'] ?? 0);
        if (!$id) err('Station id required');

        $fields = []; $params = [];
        $allowed = ['name', 'address', 'status', 'lat', 'lng'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $b)) { $fields[] = "$f = ?"; $params[] = $b[$f]; }
        }
        if (!$fields) err('Nothing to update');
        $params[] = $id;
        db()->prepare("UPDATE stations SET " . implode(', ', $fields) . " WHERE id=?")->execute($params);
        respond(['ok' => true]);
    }

    // ── Update reservation status ──
    if ($entity === 'reservation') {
        $id     = (int)($b['id']     ?? 0);
        $status = $b['status'] ?? '';
        if (!$id) err('Reservation id required');
        $validStatuses = ['pending', 'active', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses)) err('Gecersiz durum');

        $stmt = db()->prepare("
            SELECT r.*, u.name AS user_name, s.name AS station_name
            FROM reservations r
            JOIN chargers c  ON c.id = r.charger_id
            JOIN stations s  ON s.id = c.station_id
            JOIN users u     ON u.id = r.user_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        if (!$res) err('Reservation not found', 404);

        // Önceki durum kontrolü — zaten iptal/tamamlanmışsa tekrar işlem yapma
        $prevStatus = $res['status'];

        db()->prepare("UPDATE reservations SET status=? WHERE id=?")->execute([$status, $id]);

        // ── Admin iptal edince para iadesi yap ──
        $refunded = 0;
        if ($status === 'cancelled' && $prevStatus !== 'cancelled' && $prevStatus !== 'completed') {
            $refund = (float)$res['amount_deducted'];
            if ($refund > 0) {
                db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
                    ->execute([$refund, $res['user_id']]);

                $newBal = db()->prepare("SELECT wallet_balance FROM users WHERE id=?");
                $newBal->execute([$res['user_id']]);
                $balance = (float)$newBal->fetchColumn();

                db()->prepare("
                    INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
                    VALUES (?,?,'credit','İade – Admin tarafından iptal edildi',?)
                ")->execute([$res['user_id'], $refund, $balance]);

                $refunded = $refund;
            }
        }

        $labels = ['pending'=>'Beklemede','active'=>'Aktif','completed'=>'Tamamlandı','cancelled'=>'İptal Edildi'];
        $label  = $labels[$status] ?? $status;
        $msg    = "Rezervasyonunuz güncellendi → {$label}. İstasyon: {$res['station_name']}, Tarih: {$res['reservation_date']} {$res['start_time']}.";
        if ($refunded > 0) $msg .= " {$refunded} TL cüzdanınıza iade edildi.";
        notify(
            (int)$res['user_id'],
            'reservation_status_updated',
            'Rezervasyon Durumu Güncellendi',
            $msg
        );
        respond(['ok' => true, 'refunded' => $refunded]);
    }

    // ── Default: update user (role, wallet) ──
    $id = (int)($b['id'] ?? 0);
    if (!$id) err('User id required');

    $fields = []; $params = [];
    if (isset($b['role']) && in_array($b['role'], ['driver','operator','technician','admin'])) {
        $fields[] = 'role = ?'; $params[] = $b['role'];
    }
    if (isset($b['wallet_balance'])) {
        $fields[] = 'wallet_balance = ?'; $params[] = (float)$b['wallet_balance'];
    }
    if (!$fields) err('Nothing to update');
    $params[] = $id;
    db()->prepare("UPDATE users SET ".implode(', ', $fields)." WHERE id=?")->execute($params);

    // If assigning operator to a station
    if (isset($b['station_id'])) {
        db()->prepare("UPDATE stations SET operator_id=? WHERE id=?")->execute([$id, (int)$b['station_id']]);
    }
    respond(['ok' => true]);
}

// ── DELETE: kullanıcı sil (admin only) ──
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) err('User id required');

    // Kendi hesabını silemesin
    if ($id === (int)$user['id']) err('Kendi hesabınızı silemezsiniz.');

    $stmt = db()->prepare("SELECT id, role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) err('Kullanıcı bulunamadı.', 404);

    db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    respond(['ok' => true]);
}

err('Method not allowed', 405);
