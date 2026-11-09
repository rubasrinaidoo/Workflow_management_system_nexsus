<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';

$loginError = $_SESSION['login_error'] ?? '';
$notice = get_flash();
unset($_SESSION['login_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? null;
    $password = $_POST['password'] ?? null;
    if (!csrf_valid() || !is_string($username) || !is_string($password)) {
        $_SESSION['login_error'] = 'Invalid request. Refresh and try again.';
        redirect_to('login.php');
    }
    // Clear any previous login state before checking the new credentials.
    unset($_SESSION['user_id'], $_SESSION['privacy_ok'], $_SESSION['session_version'], $_SESSION['last_activity']);

    $user = null;
    if (preg_match('/\A[A-Za-z0-9_.@-]{3,50}\z/D', $username) === 1 && $password !== '' && strlen($password) <= 128) {
        $stmt = $conn->prepare('SELECT user_id, password_hash, account_status, session_version FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$user || $user['account_status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_error'] = 'Invalid username or password.';
        redirect_to('login.php');
    }

    $id = (int)$user['user_id'];
    $stmt = $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    session_regenerate_id(true);

    $_SESSION['user_id'] = $id;
    $_SESSION['session_version'] = (int)$user['session_version'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    redirect_to('privacyAcknowledgement.php');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">

  <link rel="stylesheet" href="style.css">
</head>
<body>
  <main class="auth-page container-fluid">
    <div class="auth-layout">
      <section class="auth-card" aria-labelledby="page-title">
        <h1 class="visually-hidden" id="page-title">Log in</h1>
        <div class="login-card-brand">
          <?php if (is_file(__DIR__ . '/nexsus_logo.png')): ?>
            <img src="nexsus_logo.png" alt="NEXSUS Business Solutions" class="login-card-logo">
          <?php else: ?>
            <strong class="brand-text-logo" aria-label="NEXSUS Business Solutions">NEXSUS</strong>
          <?php endif; ?>
        </div>

        <?php if ($notice): ?><p role="status" class="text-success"><?= h($notice['message']) ?></p><?php endif; ?>
        <?php if ($loginError !== ''): ?>
          <p role="alert" class="text-danger fw-semibold"><?= h($loginError) ?></p>
        <?php endif; ?>
        <form id="loginForm" method="post" action="login.php">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="mb-3">
        <label for="username" class="form-label">Username</label>
    <div class="field-control">
        <span class="field-icon">
			<svg class="icon " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg></span>
            <input class="form-control form-control-lg" id="username" name="username" type="text" autocomplete="username" placeholder="Enter your username" maxlength="50" required>
            </div>
            </div>
    <div class="mb-3">
        <label for="password" class="form-label">Password</label>
    <div class="field-control password-control">
        <span class="field-icon">
			<svg class="icon " viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg></span>
              <input class="form-control form-control-lg" id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
              <button type="button" class="password-toggle" data-toggle-password="password" aria-controls="password" aria-label="Show password" aria-pressed="false" hidden>
                <svg class="icon eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                  <svg class="icon eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m3 3 18 18M10.6 5.1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8M6.3 6.3A22 22 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.7-1.7M10 10a3 3 0 0 0 4 4"/></svg>
                </button>
               </div>
			</div>
          <button class="btn btn-lg auth-submit" type="submit">Log in</button>
      </form>
        <p class="form-link"><a href="forgotPassword.php">Forgot your password?</a></p>
      </section>
      <aside class="brand-panel" aria-label="NEXSUS">
    <div class="brand-content">
          <?php if (is_file(__DIR__ . '/nexsus_logo.png')): ?>
            <img src="nexsus_logo.png" alt="" class="brand-logo-motion">
          <?php else: ?>
            <div class="brand-monogram" aria-hidden="true">N</div>
          <?php endif; ?>
          <p class="brand-slogan">Work smarter. Track better.</p>
        </div>
      </aside>
    </div>
</main>
   <script src="script.js" defer></script>
  </body>
</html>