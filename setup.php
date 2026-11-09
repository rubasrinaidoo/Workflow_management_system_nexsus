<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';

// Allow initial owner setup only from localhost.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Initial setup is available only from localhost.');
}

// Check whether an owner already exists.
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role = 'owner'");
$stmt->execute();
$exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
$stmt->close();

if ($exists) {
    http_response_code(403);
    exit('Initial owner setup is permanently closed. Sign in normally.');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = trim(post_string('full_name'));
    $username = trim(post_string('username'));
    $email = trim(post_string('email'));
    $password = post_string('password');
    $confirm = post_string('confirm_password');
    $key = post_string('setup_key');

    $nameLength = function_exists('mb_strlen')
        ? mb_strlen($fullName)
        : strlen($fullName);

    // Server-side form validation
    if (!csrf_valid()) {
        $error = 'Request expired. Refresh and try again.';

    } elseif (preg_match('/\A[a-f0-9]{64}\z/i', SETUP_KEY) !== 1
        || !hash_equals(SETUP_KEY, $key)) {
        $error = 'Incorrect setup key. Configure it in config.php first.';

    } elseif ($nameLength < 2 || $nameLength > 150) {
        $error = 'Full name must be 2–150 characters.';

    } elseif (preg_match('/\A[A-Za-z0-9_.@-]{3,50}\z/D', $username) !== 1) {
        $error = 'Username must be 3–50 characters using letters, numbers, _, . or - or @.';

    } elseif (strlen($email) > 254
        || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';

    } elseif (!valid_password($password)) {
        $error = 'Password must be between 8 and 12 characters.';

    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';

    } else {
        $locked = false;

        try {
            // Prevent two first-owner requests at the same time.
            $result = $conn->query(
                "SELECT GET_LOCK('nexsus_first_owner', 5) AS acquired"
            );

            $locked = (int)$result->fetch_assoc()['acquired'] === 1;

            if (!$locked) {
                throw new RuntimeException('Setup is busy. Try again.');
            }

            $conn->begin_transaction();

            // Confirm there is still no owner.
            $result = $conn->query(
                "SELECT COUNT(*) AS total FROM users WHERE role = 'owner'"
            );

            if ((int)$result->fetch_assoc()['total'] !== 0) {
                throw new RuntimeException(
                    'An owner already exists. Setup has closed.'
                );
            }

            // Hash and automatically salt the password.
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Safely insert the owner using a prepared statement.
            $stmt = $conn->prepare(
                "INSERT INTO users
                (full_name, username, email, password_hash, role)
                VALUES (?, ?, ?, ?, 'owner')"
            );

            $stmt->bind_param(
                'ssss',
                $fullName,
                $username,
                $email,
                $hash
            );

            $stmt->execute();
            $stmt->close();

            $conn->commit();
            $success = true;

        } catch (mysqli_sql_exception $e) {
            $conn->rollback();

            error_log('Owner setup: ' . $e->getMessage());

            $error = (int)$e->getCode() === 1062
                ? 'Username or email is already in use.'
                : 'Could not create owner. Check your database setup.';

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();

        } finally {
            if ($locked) {
                $conn->query(
                    "SELECT RELEASE_LOCK('nexsus_first_owner')"
                );
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Initial owner setup | NEXSUS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">

    <link rel="stylesheet" href="style.css">
</head>

<body>
<main class="auth-page">
    <div class="auth-layout-single auth-layout">
        <section class="auth-card">

            <h1>Initial owner setup</h1>

            <p class="intro">
                Create the first NEXSUS owner account.
                This page locks automatically after setup.
            </p>

            <?php if ($error !== ''): ?>
                <p role="alert" class="text-danger">
                    <?= h($error) ?>
                </p>
            <?php endif; ?>

            <?php if ($success): ?>

                <p role="status" class="text-success">
                    Owner created successfully. Setup is now closed.
                </p>

                <a class="btn auth-submit" href="login.php">
                    Continue to login
                </a>

            <?php else: ?>

                <form method="post" action="setup.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h(csrf_token()) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="full_name">
                            Full name
                        </label>

                        <input
                            class="form-control"
                            id="full_name"
                            name="full_name"
                            maxlength="150"
                            autocomplete="name"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="username">
                            Username
                        </label>

                        <input
                            class="form-control"
                            id="username"
                            name="username"
                            minlength="3"
                            maxlength="50"
                            autocomplete="username"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">
                            Owner email (for password resets)
                        </label>

                        <input
                            class="form-control"
                            type="email"
                            id="email"
                            name="email"
                            maxlength="254"
                            autocomplete="email"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">
                            Password
                        </label>

                        <input
                            class="form-control"
                            type="password"
                            id="password"
                            name="password"
                            minlength="12"
                            maxlength="72"
                            autocomplete="new-password"
                            required>

                        <div class="form-text">
                           <?= h(PASSWORD_HINT) ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">
                            Confirm password
                        </label>

                        <input
                            class="form-control"
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            minlength="12"
                            maxlength="72"
                            autocomplete="new-password"
                            required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="setup_key">
                            Private setup key (from config.php)
                        </label>

                        <input
                            class="form-control"
                            type="password"
                            id="setup_key"
                            name="setup_key"
                            autocomplete="off"
                            required>
                    </div>

                    <button class="btn auth-submit" type="submit">
                        Create owner account
                    </button>

                </form>

            <?php endif; ?>

        </section>
    </div>
</main>

<script src="script.js" defer></script>
</body>
</html>
