<?php
// ─── CORS + JWT bootstrap — include at top of every API file ───
$allowed_origins = [
    'https://unipazari.com',
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
