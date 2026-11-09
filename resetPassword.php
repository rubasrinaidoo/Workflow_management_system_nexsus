<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';

header('Referrer-Policy: no-referrer');

$resetError = '';

// Check the token received from the reset email.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {

    unset(
        $_SESSION['pending_reset_id'],
        $_SESSION['user_id'],
        $_SESSION['privacy_ok'],
        $_SESSION['session_version'],
        $_SESSION['last_activity']
    );

    $token = $_GET['token'];

    if (is_string($token) &&
        preg_match('/\A[0-9a-f]{64}\z/D', $token) === 1) {

        $hash = hash('sha256', $token);

        $stmt = $conn->prepare(
            'SELECT reset_id FROM password_resets
             WHERE token_hash = ?
             AND used_at IS NULL
             AND expires_at > NOW()
             LIMIT 1'
        );

        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            session_regenerate_id(true);

            $_SESSION['pending_reset_id'] = (int)$row['reset_id'];

            redirect_to('resetPassword.php');
        }
    }

    $_SESSION['reset_error'] = 'This reset link is invalid or expired.';
    redirect_to('resetPassword.php');
}

$resetError = $_SESSION['reset_error'] ?? '';
unset($_SESSION['reset_error']);


// Process the new password form.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $password = $_POST['newPassword'] ?? null;
    $confirmation = $_POST['confirmPassword'] ?? null;

    if (!csrf_valid() || empty($_SESSION['pending_reset_id'])) {

        $resetError = 'Invalid request. Request a fresh reset link.';
        unset($_SESSION['pending_reset_id']);

    } elseif (
        !is_string($password) ||
        !is_string($confirmation) ||
        !valid_password($password)
    ) {

$resetError = PASSWORD_HINT;
    } elseif ($password !== $confirmation) {

        $resetError = 'Passwords do not match.';

    } else {

        try {
            $conn->begin_transaction();

            $resetId = (int)$_SESSION['pending_reset_id'];

            $stmt = $conn->prepare(
                'SELECT user_id FROM password_resets
                 WHERE reset_id = ?
                 AND used_at IS NULL
                 AND expires_at > NOW()
                 LIMIT 1 FOR UPDATE'
            );

            $stmt->bind_param('i', $resetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {

                $conn->rollback();
                unset($_SESSION['pending_reset_id']);

                $resetError = 'This reset link has expired or been used.';

            } else {

                $id = (int)$row['user_id'];

                // Hash and automatically salt the new password.
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    'UPDATE users
                     SET password_hash = ?,
                         failed_attempts = 0,
                         locked_until = NULL,
                         session_version = session_version + 1
                     WHERE user_id = ?'
                );

                $stmt->bind_param('si', $hash, $id);
                $stmt->execute();
                $stmt->close();

                // Prevent previous reset links from being reused.
                $stmt = $conn->prepare(
                    'UPDATE password_resets
                     SET used_at = NOW()
                     WHERE user_id = ?
                     AND used_at IS NULL'
                );

                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                unset(
                    $_SESSION['pending_reset_id'],
                    $_SESSION['user_id'],
                    $_SESSION['privacy_ok'],
                    $_SESSION['session_version'],
                    $_SESSION['last_activity']
                );

                session_regenerate_id(true);

                flash('success', 'Your password was reset. Please log in.');

                redirect_to('login.php');
            }

        } catch (Throwable $e) {

            $conn->rollback();

            error_log('Password update failed: ' . $e->getMessage());

            $resetError = 'Password could not be updated. Please try again.';
        }
    }
}

$validReset = !empty($_SESSION['pending_reset_id']);
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">

    <title>Reset password | NEXSUS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link rel="stylesheet" href="style.css">
</head>

<body>

<main class="auth-page container-fluid">
    <div class="auth-layout auth-layout-single">

        <section class="auth-card">

            <h1>Reset your password</h1>

            <p class="intro">
                Enter and confirm your new password.
            </p>

            <?php if ($resetError !== ''): ?>
                <p role="alert" class="text-danger">
                    <?= h($resetError) ?>
                </p>
            <?php endif; ?>

            <?php if ($validReset): ?>

            <form id="newPasswordForm"
                  method="post"
                  action="resetPassword.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h(csrf_token()) ?>">

                <p id="password-rules" class="password-rules">
                   <?= h(PASSWORD_HINT) ?>
                </p>

                <!-- NEW PASSWORD -->

                <div class="mb-3">

                    <label for="newPassword" class="form-label">
                        New password
                    </label>

                    <div class="field-control password-control">

                        <span class="field-icon">
                            <svg class="icon" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor"
                                 stroke-width="1.8" aria-hidden="true">
                                <rect x="5" y="10" width="14"
                                      height="11" rx="2"/>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                            </svg>
                        </span>

                        <input
                            class="form-control form-control-lg"
                            id="newPassword"
                            name="newPassword"
                            type="password"
                            minlength="12"
                            maxlength="72"
                            aria-describedby="password-rules"
                            autocomplete="new-password"
                            required>

                        <button
                            type="button"
                            class="password-toggle"
                            data-toggle-password="newPassword"
                            aria-controls="newPassword"
                            aria-label="Show new password"
                            aria-pressed="false"
                            hidden>

                            <svg class="icon eye-open"
                                 viewBox="0 0 24 24"
                                 fill="none"
                                 stroke="currentColor"
                                 stroke-width="1.8"
                                 stroke-linecap="round"
                                 stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>

                            <svg class="icon eye-closed"
                                 viewBox="0 0 24 24"
                                 fill="none"
                                 stroke="currentColor"
                                 stroke-width="1.8"
                                 stroke-linecap="round"
                                 stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.3 6.3A22 22 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.7-1.7M10 10a3 3 0 0 0 4 4"/>
                            </svg>

                        </button>
                    </div>
                </div>

                <!-- CONFIRM PASSWORD -->

                <div class="mb-3">

                    <label for="confirmPassword" class="form-label">
                        Confirm new password
                    </label>

                    <div class="field-control password-control">

                        <span class="field-icon">
                            <svg class="icon" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor"
                                 stroke-width="1.8" aria-hidden="true">
                                <rect x="5" y="10" width="14"
                                      height="11" rx="2"/>
                                <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                            </svg>
                        </span>

                        <input
                            class="form-control form-control-lg"
                            id="confirmPassword"
                            name="confirmPassword"
                            type="password"
                            minlength="12"
                            maxlength="72"
                            aria-describedby="password-rules"
                            autocomplete="new-password"
                            required>

                        <button
                            type="button"
                            class="password-toggle"
                            data-toggle-password="confirmPassword"
                            aria-controls="confirmPassword"
                            aria-label="Show confirm new password"
                            aria-pressed="false"
                            hidden>

                            <svg class="icon eye-open"
                                 viewBox="0 0 24 24"
                                 fill="none"
                                 stroke="currentColor"
                                 stroke-width="1.8"
                                 stroke-linecap="round"
                                 stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>

                            <svg class="icon eye-closed"
                                 viewBox="0 0 24 24"
                                 fill="none"
                                 stroke="currentColor"
                                 stroke-width="1.8"
                                 stroke-linecap="round"
                                 stroke-linejoin="round"
                                 aria-hidden="true">
                                <path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.3 6.3A22 22 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.7-1.7M10 10a3 3 0 0 0 4 4"/>
                            </svg>

                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-lg auth-submit">
                    Save new password
                </button>

            </form>

            <?php else: ?>

                <p>
                    <a href="forgotPassword.php">
                        Request a new reset link
                    </a>
                </p>

            <?php endif; ?>

            <p class="form-link">
                <a href="login.php">Return to login</a>
            </p>

        </section>
    </div>
</main>

<script src="script.js" defer></script>

</body>
</html>
