<?php
require_once __DIR__ . '/cors.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── POST: sorun bildir ──
if ($method === 'POST') {
    $user = requireAuth();
    $b    = body();

    $station_id  = (int)($b['station_id']  ?? 0);
    $charger_id  = isset($b['charger_id']) && $b['charger_id'] !== '' ? (int)$b['charger_id'] : null;
    $title       = trim($b['title']       ?? '');
    $description = trim($b['description'] ?? '');

    if (!$station_id)   err('station_id gerekli');
    if (!$title)        err('Başlık gerekli');
    if (!$description)  err('Açıklama gerekli');
    if (mb_strlen($title) > 120) err('Başlık en fazla 120 karakter olabilir');

    // İstasyon var mı?
    $check = db()->prepare("SELECT id FROM stations WHERE id = ?");
    $check->execute([$station_id]);
    if (!$check->fetch()) err('İstasyon bulunamadı', 404);

    // Şarjcı belirtildiyse, bu istasyona ait mi?
    if ($charger_id) {
        $chk = db()->prepare("SELECT id FROM chargers WHERE id = ? AND station_id = ?");
        $chk->execute([$charger_id, $station_id]);
        if (!$chk->fetch()) err('Şarjcı bu istasyona ait değil', 400);
    }

    $stmt = db()->prepare("
        INSERT INTO station_issues (user_id, station_id, charger_id, title, description)
        VALUES (?,?,?,?,?)
    ");
    $stmt->execute([$user['id'], $station_id, $charger_id, $title, $description]);
    $newId = (int)db()->lastInsertId();

    // İstasyon adı ve operator_id
    $stRow = db()->prepare("SELECT name, operator_id FROM stations WHERE id = ?");
    $stRow->execute([$station_id]);
    $st          = $stRow->fetch();
    $stationName = $st['name'] ?? 'İstasyon';

    $notifTitle = '🔧 Yeni Arıza Bildirimi';
    $notifMsg   = "\"$stationName\" istasyonunda yeni bir arıza kaydı oluşturuldu: $title";

    // İlgili operatöre bildirim
    if ($st && $st['operator_id']) {
        notify((int)$st['operator_id'], 'station_issue_reported', $notifTitle, $notifMsg);
    }

    // Tüm adminlere bildirim
    $admins = db()->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll();
    foreach ($admins as $admin) {
        notify((int)$admin['id'], 'station_issue_reported', $notifTitle, $notifMsg);
    }

    respond(['ok' => true, 'id' => $newId], 201);
}

// ── GET: sorunları listele ──
if ($method === 'GET') {
    $user = requireAuth();

    // Driver sadece kendi bildirdiklerini görür
    if ($user['role'] === 'driver') {
        $stmt = db()->prepare("
            SELECT si.id, si.station_id, si.title, si.description, si.status, si.created_at,
                   s.name AS station_name,
                   c.charger_code
            FROM station_issues si
            JOIN stations s ON s.id = si.station_id
            LEFT JOIN chargers c ON c.id = si.charger_id
            WHERE si.user_id = ?
            ORDER BY si.created_at DESC
        ");
        $stmt->execute([$user['id']]);

    // Operator sadece kendi istasyonlarına ait sorunları görür
    } elseif ($user['role'] === 'operator') {
        $stmt = db()->prepare("
            SELECT si.id, si.station_id, si.title, si.description, si.status, si.created_at,
                   s.name AS station_name,
                   c.charger_code,
                   u.name AS reporter_name
            FROM station_issues si
            JOIN stations s ON s.id = si.station_id AND s.operator_id = ?
            LEFT JOIN chargers c ON c.id = si.charger_id
            JOIN users u ON u.id = si.user_id
            ORDER BY si.created_at DESC
        ");
        $stmt->execute([$user['id']]);

    // Admin/technician tümünü görür
    } elseif (in_array($user['role'], ['admin', 'technician'])) {
        $stmt = db()->query("
            SELECT si.id, si.station_id, si.title, si.description, si.status,
                   si.assigned_technician_id, si.created_at,
                   s.name AS station_name,
                   c.charger_code,
                   u.name AS reporter_name
            FROM station_issues si
            JOIN stations s ON s.id = si.station_id
            LEFT JOIN chargers c ON c.id = si.charger_id
            JOIN users u ON u.id = si.user_id
            ORDER BY si.created_at DESC
        ");
    } else {
        err('Forbidden', 403);
    }

    respond($stmt->fetchAll());
}

// ── PATCH: durum güncelle (admin / operator / technician) ──
if ($method === 'PATCH') {
    $user         = requireRole('admin', 'operator', 'technician');
    $id           = (int)($_GET['id'] ?? 0);
    $b            = body();
    $status       = $b['status'] ?? '';
    $technicianId = isset($b['technician_id']) && $b['technician_id'] !== '' ? (int)$b['technician_id'] : null;

    if (!$id) err('id gerekli');
    if (!in_array($status, ['open', 'in_progress', 'resolved', 'cannot_fix'])) err('Geçersiz durum');

    // Operator sadece kendi istasyonuna ait sorunları güncelleyebilir
    if ($user['role'] === 'operator') {
        $chk = db()->prepare("
            SELECT si.id FROM station_issues si
            JOIN stations s ON s.id = si.station_id AND s.operator_id = ?
            WHERE si.id = ?
        ");
        $chk->execute([$user['id'], $id]);
        if (!$chk->fetch()) err('Forbidden', 403);
    }

    // Mevcut kaydı al
    $issueRow = db()->prepare("
        SELECT si.*, s.name AS station_name, s.id AS sid
        FROM station_issues si
        JOIN stations s ON s.id = si.station_id
        WHERE si.id = ?
    ");
    $issueRow->execute([$id]);
    $issue = $issueRow->fetch();
    if (!$issue) err('Kayıt bulunamadı', 404);

    $chargerId = $issue['charger_id'] ? (int)$issue['charger_id'] : null;

    // ── in_progress: teknisyen zorunlu, bildirim gönder, bakıma al ──
    if ($status === 'in_progress') {
        if (!$technicianId) err('Teknisyen seçimi zorunlu');

        // Bakım zaman aralığı (datetime-local → "Y-m-d H:i:s")
        $maintStart = isset($b['maintenance_start']) && $b['maintenance_start'] !== ''
            ? (new DateTime(str_replace('T', ' ', $b['maintenance_start'])))->format('Y-m-d H:i:s')
            : demoNowSQL();
        $maintEnd   = isset($b['maintenance_end']) && $b['maintenance_end'] !== ''
            ? (new DateTime(str_replace('T', ' ', $b['maintenance_end'])))->format('Y-m-d H:i:s')
            : (new DateTime($maintStart))->modify('+24 hours')->format('Y-m-d H:i:s');

        // Teknisyen geçerli mi?
        $techRow = db()->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'technician'");
        $techRow->execute([$technicianId]);
        $tech = $techRow->fetch();
        if (!$tech) err('Geçerli bir teknisyen seçin');

        // Kaydı güncelle
        db()->prepare("
            UPDATE station_issues
            SET status = 'in_progress', assigned_technician_id = ?
            WHERE id = ?
        ")->execute([$technicianId, $id]);

        if ($chargerId) {
            // Sadece ilgili şarjcıyı offline yap
            db()->prepare("UPDATE chargers SET status = 'offline' WHERE id = ?")
                ->execute([$chargerId]);
        } else {
            // İstasyonu bakıma al + tüm müsait şarjcıları offline yap
            db()->prepare("UPDATE stations SET status = 'maintenance' WHERE id = ?")
                ->execute([$issue['sid']]);
            db()->prepare("UPDATE chargers SET status = 'offline' WHERE station_id = ? AND status = 'available'")
                ->execute([$issue['sid']]);
        }

        // ── Bakım penceresindeki rezervasyonları iptal et & iade et ──
        if ($chargerId) {
            // Sadece o şarjcıya ait rezervasyonlar
            $affStmt = db()->prepare("
                SELECT r.id, r.user_id, r.amount_deducted,
                       r.reservation_date, r.start_time, r.end_time
                FROM reservations r
                WHERE r.charger_id = ?
                  AND r.status IN ('pending','active')
                  AND TIMESTAMP(r.reservation_date, r.end_time)   > ?
                  AND TIMESTAMP(r.reservation_date, r.start_time) < ?
            ");
            $affStmt->execute([$chargerId, $maintStart, $maintEnd]);
        } else {
            // İstasyondaki tüm şarjcılara ait rezervasyonlar
            $affStmt = db()->prepare("
                SELECT r.id, r.user_id, r.amount_deducted,
                       r.reservation_date, r.start_time, r.end_time
                FROM reservations r
                JOIN chargers c ON c.id = r.charger_id AND c.station_id = ?
                WHERE r.status IN ('pending','active')
                  AND TIMESTAMP(r.reservation_date, r.end_time)   > ?
                  AND TIMESTAMP(r.reservation_date, r.start_time) < ?
            ");
            $affStmt->execute([$issue['sid'], $maintStart, $maintEnd]);
        }
        $affected = $affStmt->fetchAll();

        $maintEndDT = new DateTime($maintEnd);
        $maintEndTR = $maintEndDT->format('d.m.Y H:i');

        foreach ($affected as $res) {
            // İptal et
            db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")
                ->execute([$res['id']]);

            // İade
            $refund = (float)$res['amount_deducted'];
            db()->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
                ->execute([$refund, $res['user_id']]);
            $newBal = (float)db()->prepare("SELECT wallet_balance FROM users WHERE id = ?")
                ->execute([$res['user_id']]) ?: 0;
            $newBalRow = db()->prepare("SELECT wallet_balance FROM users WHERE id = ?");
            $newBalRow->execute([$res['user_id']]);
            $newBal = (float)$newBalRow->fetchColumn();

            db()->prepare("
                INSERT INTO wallet_transactions (user_id, amount, type, description, balance_after)
                VALUES (?,?,'credit',?,?)
            ")->execute([
                $res['user_id'],
                $refund,
                "İade – Bakım nedeniyle rezervasyon iptal ({$issue['station_name']})",
                $newBal
            ]);

            // Kullanıcıya bildirim
            $target2 = $chargerId
                ? "Şarjcı ({$issue['station_name']})"
                : "\"{$issue['station_name']}\"";
            notify(
                (int)$res['user_id'],
                'reservation_cancelled_maintenance',
                'Rezervasyon İptal – Bakım',
                "{$target2} istasyonu bakım çalışması nedeniyle {$res['reservation_date']} {$res['start_time']}–{$res['end_time']} saatleri arasındaki rezervasyonunuz iptal edildi. " .
                number_format($refund, 2) . " TL cüzdanınıza iade edildi. " .
                "İstasyon {$maintEndTR} sonrasında tekrar aktif olacaktır."
            );
        }

        // Teknisyene bildirim
        $target = $chargerId
            ? "Şarjcı #{$chargerId} ({$issue['station_name']})"
            : "\"{$issue['station_name']}\" istasyonu";
        notify(
            $technicianId,
            'technician_assigned',
            'Yeni Görev: ' . $issue['station_name'],
            "{$target} için arıza bildirildi: {$issue['title']}. Bakım penceresi: " .
            (new DateTime($maintStart))->format('d.m.Y H:i') . " – {$maintEndTR}. Lütfen en kısa sürede ilgilenin."
        );

        respond([
            'ok'              => true,
            'charger_targeted'=> (bool)$chargerId,
            'cancelled_count' => count($affected),
        ]);
    }

    // ── resolved: bakımdan çıkar ──
    if ($status === 'resolved') {
        db()->prepare("UPDATE station_issues SET status = 'resolved' WHERE id = ?")->execute([$id]);

        if ($chargerId) {
            // Sadece o şarjcıyı müsaite döndür
            db()->prepare("UPDATE chargers SET status = 'available' WHERE id = ?")
                ->execute([$chargerId]);
        } else {
            // İstasyonu aktife al + offline şarjcıları müsaite döndür
            db()->prepare("UPDATE stations SET status = 'active' WHERE id = ? AND status = 'maintenance'")
                ->execute([$issue['sid']]);
            db()->prepare("UPDATE chargers SET status = 'available' WHERE station_id = ? AND status = 'offline'")
                ->execute([$issue['sid']]);
        }

        respond(['ok' => true, 'charger_targeted' => (bool)$chargerId]);
    }

    // ── cannot_fix: ticket'ı open'a döndür, hedefi pasife al ──
    if ($status === 'cannot_fix') {
        db()->prepare("UPDATE station_issues SET status = 'open', assigned_technician_id = NULL WHERE id = ?")
            ->execute([$id]);

        if ($chargerId) {
            // Şarjcı offline kalır (zaten offline), istasyon dokunulmaz
            // (offline zaten set edilmişti, burada ekstra işlem gerekmez)
        } else {
            // İstasyonu inactive yap
            db()->prepare("UPDATE stations SET status = 'inactive' WHERE id = ?")
                ->execute([$issue['sid']]);
        }

        respond(['ok' => true, 'charger_targeted' => (bool)$chargerId]);
    }

    // open (geri alma / sıfırlama)
    db()->prepare("UPDATE station_issues SET status = ? WHERE id = ?")->execute([$status, $id]);
    respond(['ok' => true]);
}

err('Method not allowed', 405);
