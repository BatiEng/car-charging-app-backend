<?php
/**
 * One-time setup script — run ONCE after importing database.sql
 * URL: https://unipazari.com/fse-project/api/setup.php
 * DELETE or rename this file after running!
 */
require_once __DIR__ . '/config.php';

$users = [
    ['admin@ev.com',   'Admin123!'],
    ['op1@ev.com',     'Operator1!'],
    ['op2@ev.com',     'Operator2!'],
    ['op3@ev.com',     'Operator3!'],
    ['op4@ev.com',     'Operator4!'],
    ['op5@ev.com',     'Operator5!'],
    ['op6@ev.com',     'Operator6!'],
    ['tech@ev.com',    'Tech123!'],
    ['driver@ev.com',  'Driver123!'],
];

$stmt = db()->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$done = [];
foreach ($users as [$email, $plain]) {
    $hash = password_hash($plain, PASSWORD_BCRYPT);
    $stmt->execute([$hash, $email]);
    $done[] = "$email ✓";
}

echo "<pre>Passwords updated:\n" . implode("\n", $done) . "\n\nDELETE THIS FILE NOW!</pre>";
