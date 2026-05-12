<?php
require_once __DIR__ . '/cors.php';

// Herhangi bir yetkili kullanıcı teknisyen listesini görebilir
$user = requireRole('admin', 'operator', 'technician');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = db()->query("
        SELECT id, name, email
        FROM users
        WHERE role = 'technician'
        ORDER BY name ASC
    ");
    respond($stmt->fetchAll());
}

err('Method not allowed', 405);
