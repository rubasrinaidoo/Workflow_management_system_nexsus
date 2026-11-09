<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');
ini_set('session.use_strict_mode', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/conn_db.php';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $file): never {
    header('Location: ' . $file, true, 303);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(): bool {
    $posted = $_POST['csrf_token'] ?? null;
    return is_string($posted) && hash_equals(csrf_token(), $posted);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}

function post_string(string $field): string {
    $value = $_POST[$field] ?? '';
    return is_string($value) ? $value : '';
}

function recent_attempts(string $key): array {
    $cutoff = time() - 15 * 60;
    $recent = [];
    foreach ($_SESSION[$key] ?? [] as $time) {
        if (is_int($time) && $time > $cutoff) {
            $recent[] = $time;
        }
    }
    $_SESSION[$key] = $recent;
    return $recent;
}

// This policy is shared with Employee Management and Reset Password.
function valid_password(string $password): bool {
    $length = strlen($password); // Bytes, to agree with password_hash input limits.
    if ($length < 12 || $length > 72) {
        return false;
    }

    $score = 0;
    if ($length >= 8) $score++;
    if ($length >= 12) $score++;
    if (preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/[A-Z]/', $password)) $score++;
    if (preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;

    return $score >= 5;
}

// Shown on every page that sets a password.
const PASSWORD_HINT = '12–72 characters, including at least 3 of: lowercase letter, uppercase letter, number, symbol.';
// Each role has exactly one home page.
function dashboard_for_role(string $role): ?string {
    return match ($role) {
        'owner'    => 'ownerDashboard.php',
        'employee' => 'employeeDashboard.php',
        default    => null,
    };
}
function require_password_session(): array {
    global $conn;

    if (empty($_SESSION['user_id'])) {
        redirect_to('login.php');
    }

    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800) {
        unset($_SESSION['user_id'], $_SESSION['privacy_ok'], $_SESSION['session_version'], $_SESSION['last_activity']);
        redirect_to('login.php');
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT user_id, full_name, role, account_status, session_version
         FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || strtolower(trim((string)$user['account_status'])) !== 'active'
        || (int)$user['session_version'] !== (int)($_SESSION['session_version'] ?? -1)) {
        unset($_SESSION['user_id'], $_SESSION['privacy_ok'], $_SESSION['session_version'], $_SESSION['last_activity']);
        redirect_to('login.php');
    }

    // Normalise so 'Owner', 'EMPLOYEE' or a trailing space in the database cannot lock someone out.
    $user['role'] = strtolower(trim((string)$user['role']));

    $_SESSION['last_activity'] = time();
    return $user;
}

function require_logged_in(?string $requiredRole = null): array {
    $user = require_password_session();

    if (empty($_SESSION['privacy_ok'])) {
        redirect_to('privacyAcknowledgement.php');
    }

    if ($requiredRole !== null && $user['role'] !== $requiredRole) {
        // A valid user on the wrong page goes to their own dashboard, not a dead end.
        $home = dashboard_for_role($user['role']);
        if ($home !== null) {
            redirect_to($home);
        }
        // Unknown role value in the database: check the `users.role` column.
        http_response_code(403);
        exit('Access denied: your account role is not recognised.');
    }
    return $user;
}

function require_owner(): array {
    return require_logged_in('owner');
}

function require_employee(): array {
    return require_logged_in('employee');
}

function continue_after_privacy(): never {
    $user = require_logged_in();
    $home = dashboard_for_role($user['role']);
    if ($home !== null) {
        redirect_to($home);
    }
    http_response_code(403);
    exit('Access denied.');
}