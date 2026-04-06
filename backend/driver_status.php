<?php
/**
 * driver_status.php
 * Returns a list of currently online/available drivers.
 * Used by the commuter dashboard to detect when drivers go offline.
 *
 * Place this file at: /backend/driver_status.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once 'config.php';

$result = $conn->query(
    "SELECT id, username FROM users WHERE role = 'driver' AND is_available = 1 AND status = 'active'"
);

$online = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $online[] = [
            'id'       => (int) $row['id'],
            'username' => $row['username'],
        ];
    }
}

echo json_encode([
    'success'        => true,
    'online_drivers' => $online,
    'count'          => count($online),
    'timestamp'      => time(),
]);