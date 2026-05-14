<?php
// ─── CORS + JWT bootstrap — include at top of every API file ───
$allowed_origins = [
    'https://unipazari.com',
    'https://car-charging-app.vercel.app',
    'http://localhost:5173',
    'http://localhost:3000',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// ─── Read JWT from Authorization: Bearer <token> ───
function getBearerToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return null;
}

function currentUser(): ?array {
    $token = getBearerToken();
    if (!$token) return null;
    $payload = jwt_decode($token);
    if (!$payload) return null;
    // strip JWT-only fields before returning
    unset($payload['exp'], $payload['iat']);
    return $payload;
}

function requireAuth(): array {
    $u = currentUser();
    if (!$u) err('Unauthorized', 401);
    return $u;
}

function requireRole(string ...$roles): array {
    $u = requireAuth();
    if (!in_array($u['role'], $roles)) err('Forbidden', 403);
    return $u;
}

// ── Gelmeme cezası: başlangıç saatinden 10 dk sonra hâlâ pending ise iptal et ──
// İstisna 1: önceki kullanıcının seansı hâlâ aktifse → iptal etme, kullanıcı girememiştir.
// İstisna 2: önceki kullanıcı geç çıktıysa (completed) → grace süresi uzar (çıkış + 5 dk).
function autoNoShowCancel(): void {
    $now    = demoNow();
    $nowSQL = demoNowSQL();

    // Normal grace (start + 10 dk) geçmiş tüm pending rezervasyonları al
    $stmt = db()->prepare("
        SELECT r.id, r.user_id, r.amount_deducted, r.charger_id,
               r.reservation_date, r.start_time, r.end_time,
               s.name AS station_name, c.charger_code
        FROM reservations r
        JOIN chargers c ON c.id = r.charger_id
        JOIN stations s ON s.id = c.station_id
        WHERE r.status = 'pending'
          AND DATE_ADD(TIMESTAMP(r.reservation_date, r.start_time), INTERVAL 10 MINUTE) < ?
    ");
    $stmt->execute([$nowSQL]);
    $candidates = $stmt->fetchAll();
    if (empty($candidates)) return;

    foreach ($candidates as $r) {

        // ── İstisna 1: Aynı şarjcıda hâlâ aktif bir seans var mı? ──
        // Önceki kullanıcı durdurmadıysa bu kullanıcı zaten girememiştir → ceza yok, atla.
        $activeStmt = db()->prepare("
            SELECT id FROM charging_sessions
            WHERE charger_id = ?
              AND status     = 'active'
              AND start_time < TIMESTAMP(?, ?)
            LIMIT 1
        ");
        $activeStmt->execute([
            $r['charger_id'],
            $r['reservation_date'], $r['start_time'],
        ]);
        if ($activeStmt->fetch()) continue; // önceki seans hâlâ devam ediyor, dokunma

        $startDT        = new DateTime("{$r['reservation_date']} {$r['start_time']}");
        $normalGrace    = (clone $startDT)->modify('+10 minutes');
        $effectiveGrace = $normalGrace;

        // ── İstisna 2: Önceki kullanıcı bu rezervasyonun başlangıcından sonra çıktıysa ──
        // grace süresi = çıkış zamanı + 5 dk
        $prevStmt = db()->prepare("
            SELECT MAX(cs.end_time) AS last_end
            FROM charging_sessions cs
            WHERE cs.charger_id = ?
              AND cs.status     = 'completed'
              AND cs.end_time   > TIMESTAMP(?, ?)
              AND cs.end_time  <= TIMESTAMP(?, ?)
        ");
        $prevStmt->execute([
            $r['charger_id'],
            $r['reservation_date'], $r['start_time'],
            $r['reservation_date'], $r['end_time'],
        ]);
        $prevRow = $prevStmt->fetch();

        if (!empty($prevRow['last_end'])) {
            $prevEndDT     = new DateTime($prevRow['last_end']);
            $extendedGrace = (clone $prevEndDT)->modify('+5 minutes');
            if ($extendedGrace > $effectiveGrace) {
                $effectiveGrace = $extendedGrace;
            }
        }

        // Etkin grace süresi henüz dolmadıysa bu rezervasyona dokunma
        if ($now <= $effectiveGrace) continue;

        // Grace doldu → iptal et + ceza
        db()->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")
            ->execute([(int)$r['id']]);

        $uid      = (int)$r['user_id'];
        $penalty  = round((float)($r['amount_deducted'] ?? 0), 2);
        $startFmt = substr($r['start_time'], 0, 5);
        $endFmt   = substr($r['end_time'],   0, 5);

        $title = 'Rezervasyon İptal – Gelmeme Cezası';
        $msg   = "{$r['station_name']} / {$r['charger_code']} için {$startFmt}-{$endFmt} "
               . "saatleri arasındaki rezervasyonunuzu saatinde başlatmadığınız için ceza "
               . "uygulanmıştır. Ödenen {$penalty} TL iade edilmeyecektir.";

        notify($uid, 'reservation_cancelled', $title, $msg);
    }
}
