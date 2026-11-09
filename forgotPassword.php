<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/mailer.php';

$message = '';
$error = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = $_POST['email'] ?? null;

    if (!csrf_valid() || !is_string($email)) {

        http_response_code(400);
        $message = 'Invalid request. Refresh and try again.';
        $error = true;

    } else {

        $email = trim($email);
        $now = time();

        recent_attempts('reset_requests');

        if (count($_SESSION['reset_requests']) >= 3) {

            http_response_code(429);
            $message = 'Too many requests. Please try again later.';
            $error = true;

        } elseif (
            strlen($email) > 254 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {

            $message = 'Enter a valid email address.';
            $error = true;

        } else {

            $_SESSION['reset_requests'][] = $now;

            try {

                // Find the registered account.
                $stmt = $conn->prepare(
                    "SELECT user_id FROM users
                     WHERE email = ?
                     AND account_status = 'active'
                     LIMIT 1"
                );

                $stmt->bind_param('s', $email);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($user) {

                    $id = (int)$user['user_id'];

                    // Restrict repeated reset requests.
                    $stmt = $conn->prepare(
                        'SELECT COUNT(*) AS total
                         FROM password_resets
                         WHERE user_id = ?
                         AND created_at >
                         DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
                    );

                    $stmt->bind_param('i', $id);
                    $stmt->execute();

                    $recent = (int)$stmt
                        ->get_result()
                        ->fetch_assoc()['total'];

                    $stmt->close();

                    if ($recent < 3) {

                        // Generate a secure reset token.
                        $token = bin2hex(random_bytes(32));
                        $hash = hash('sha256', $token);
                        $mins = RESET_EXPIRY_MINUTES;

                        $conn->begin_transaction();

                        try {

                            // Invalidate previous reset links.
                            $stmt = $conn->prepare(
                                'UPDATE password_resets
                                 SET used_at = NOW()
                                 WHERE user_id = ?
                                 AND used_at IS NULL'
                            );

                            $stmt->bind_param('i', $id);
                            $stmt->execute();
                            $stmt->close();

                            // Save the hash, not the original token.
                            $stmt = $conn->prepare(
                                'INSERT INTO password_resets
                                 (user_id, token_hash, expires_at)
                                 VALUES
                                 (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))'
                            );

                            $stmt->bind_param('isi', $id, $hash, $mins);
                            $stmt->execute();
                            $stmt->close();

                            // Create the link for the email.
                            $link = APP_URL
                                . '/resetPassword.php?token='
                                . urlencode($token);

                            // CHANGED: was "throw new RuntimeException('Reset email could not be sent.')".
                            // Demo fallback: if the email fails (no internet / SMTP not set up),
                            // keep the reset and write the link to reset_links.log instead.
                            if (!send_reset_email($email, $link)) {
                                file_put_contents(
                                    __DIR__ . '/reset_links.log',
                                    date('Y-m-d H:i:s') . " | $email | $link\n",
                                    FILE_APPEND
                                );
                            }

                            $conn->commit();

                        } catch (Throwable $emailError) {

                            $conn->rollback();

                            error_log(
                                'Reset email transaction failed: '
                                . $emailError->getMessage()
                            );
                        }
                    }
                }

            } catch (Throwable $e) {

                error_log(
                    'Reset request failed: ' . $e->getMessage()
                );
            }

            // Same response for registered and unregistered emails.
            // CHANGED: added "Check your inbox and spam folder."
            $message =
                'If that email is registered, a reset link has been sent. Check your inbox and spam folder.';

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Forgot password | NEXSUS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link rel="stylesheet" href="style.css">
</head>

<body>

<main class="auth-page container-fluid">
    <div class="auth-layout auth-layout-single">

        <section class="auth-card">

            <h1>Forgot your password?</h1>

            <p class="intro">
                Enter your account email and we'll send you
                a password reset link.
            </p>

            <?php if ($message !== ''): ?>
                <p
                    role="status"
                    class="<?= $error ? 'text-danger' : 'text-success' ?>">
                    <?= h($message) ?>
                </p>
            <?php endif; ?>

            <form method="post" action="forgotPassword.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h(csrf_token()) ?>">

                <div class="mb-3">

                    <label for="email" class="form-label">
                        Email address
                    </label>

                    <div class="field-control">

                        <span class="field-icon">
                            <svg class="icon"
                                 viewBox="0 0 24 24"
                                 fill="none"
                                 stroke="currentColor"
                                 stroke-width="1.8"
                                 aria-hidden="true">
                                <rect x="3" y="5"
                                      width="18" height="14" rx="2"/>
                                <path d="m3 6 9 7 9-7"/>
                            </svg>
                        </span>

                        <input
                            class="form-control form-control-lg"
                            type="email"
                            id="email"
                            name="email"
                            maxlength="254"
                            autocomplete="email"
                            required>

                    </div>
                </div>

                <button type="submit" class="btn btn-lg auth-submit">
                    Email reset link
                </button>

            </form>

            <p class="form-link">
                <a href="login.php">Return to login</a>
            </p>

        </section>
    </div>
</main>

<script src="script.js" defer></script>

</body>
</html>