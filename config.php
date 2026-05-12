<?php
// ─── Database credentials — update before uploading to cPanel ───
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');   // e.g. unipaza1_evcharge
define('DB_USER', 'your_db_user');   // e.g. unipaza1_admin
define('DB_PASS', 'your_db_password');

// ─── JWT Secret — change this to a long random string ───
define('JWT_SECRET', 'ev_charge_super_secret_key_change_me_2026');
define('JWT_TTL',    86400); // 24 hours

// ─── DB Connection (singleton) ───
function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ─── Response helpers ───
function respond(mixed $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function err(string $msg, int $code = 400): never {
    respond(['error' => $msg], $code);
}
function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ─── JWT helpers (no external library needed) ───────────────────
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode(array $payload): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_TTL;
    $payload['iat'] = time();
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

// ─── Demo time helpers ────────────────────────────────────────────
// Sunumlar için sistem saatini ilerletir.
// Admin demo.php üzerinden saniye cinsinden offset ayarlar.
function demoNow(): DateTime {
    static $offset = null;
    if ($offset === null) {
        try {
            $row = db()->query("SELECT value FROM demo_config WHERE `key`='time_offset_seconds'")->fetchColumn();
            $offset = (int)($row ?: 0);
        } catch (\Throwable) {
            $offset = 0;
        }
    }
    $dt = new DateTime('now');
    if ($offset !== 0) $dt->modify("{$offset} seconds");
    return $dt;
}
function demoNowSQL(): string {
    return demoNow()->format('Y-m-d H:i:s');
}

// ─── Create a notification for a user ───
function notify(int $userId, string $type, string $title, string $message): void {
    $s = db()->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)");
    $s->execute([$userId, $type, $title, $message]);
}

// ─── Check wallet balance after deduction; send low-balance alert ───
// Son 1 saatte zaten bildirim gönderildiyse tekrar gönderme
function checkWalletAlert(int $userId, float $balance): void {
    if ($balance >= 200) return;

    // Son 1 saatte wallet_low bildirimi var mı?
    $recent = db()->prepare("
        SELECT id FROM notifications
        WHERE user_id = ? AND type = 'wallet_low'
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        LIMIT 1
    ");
    $recent->execute([$userId]);
    if ($recent->fetch()) return; // Zaten gönderilmiş, atla

    notify($userId, 'wallet_low',
        '⚠️ Düşük Bakiye Uyarısı',
        "Cüzdan bakiyeniz " . number_format($balance, 2) . " TL'ye düştü. Hizmetlere kesintisiz erişim için lütfen bakiye yükleyin."
    );
}
