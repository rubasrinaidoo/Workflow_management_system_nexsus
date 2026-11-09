<?php
declare(strict_types=1);
// Delete this localhost-only setup test after verifying the installation.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local test only.');
}
require_once __DIR__ . '/conn_db.php';
header('Content-Type: text/plain; charset=UTF-8');
echo "MySQL connection successful.\n";
foreach (['users', 'otp_verifications', 'password_resets'] as $name) {
    $stmt = $conn->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    echo "$name: " . ($found ? 'FOUND' : 'MISSING') . "\n";
}
