<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';

$user = require_password_session();
$userId = (int)$user['user_id'];

if (!empty($_SESSION['privacy_ok'])) {
    continue_after_privacy();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_valid()) {
        $error = 'Invalid request. Refresh and try again.';

    } elseif (($_POST['privacy_accept'] ?? null) !== '1') {
        $error = 'Tick the acknowledgement checkbox to proceed.';

    } else {
        try {
            $version = PRIVACY_VERSION;

            $stmt = $conn->prepare(
                'UPDATE users
                 SET privacy_accepted_at = NOW(),
                     privacy_policy_version = ?
                 WHERE user_id = ?'
            );

            $stmt->bind_param('si', $version, $userId);
            $stmt->execute();
            $stmt->close();

            $_SESSION['privacy_ok'] = true;

            continue_after_privacy();

        } catch (Throwable $e) {
            error_log('Privacy acknowledgement failed: ' . $e->getMessage());
            $error = 'Could not save your acknowledgement. Try again.';
        }
    }
}
?>

<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Privacy acknowledgement | NEXSUS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="style.css"></head>
<body class="dialog-page"><main class="auth-page container-fluid"><dialog open class="auth-card auth-dialog acknowledgement-card" aria-labelledby="page-title">
<h1 id="page-title">Privacy acknowledgement</h1><p class="intro">As an authorised NEXSUS user, I acknowledge these responsibilities:</p>
<div class="responsibilities"><ul><li>Handle client information lawfully, truthfully and accurately.</li><li>Keep financial, payroll and personal records confidential.</li><li>Use information only for authorised business purposes.</li><li>Protect login details and report suspected breaches.</li></ul></div>
<?php if ($error !== ''): ?><p role="alert" class="text-danger"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="privacyAcknowledgement.php"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<div class="form-check acknowledgement-check"><input class="form-check-input" id="privacyAccept" name="privacy_accept" type="checkbox" value="1" required><label class="form-check-label" for="privacyAccept">I have read and understand these responsibilities.</label></div>
<button class="btn btn-lg auth-submit" type="submit">Acknowledge &amp; Continue</button></form>
</dialog></main><script src="script.js" defer></script></body></html>
