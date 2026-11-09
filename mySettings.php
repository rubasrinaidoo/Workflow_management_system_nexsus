<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/nexsus_shell.php';

// Only an employee may view this page, and only their own information.
$employee = require_employee();
$employeeId = (int)$employee['user_id'];
$csrf = csrf_token();

// Fetch current profile values from MySQL instead of trusting browser input.
$stmt = $conn->prepare(
    "SELECT full_name, username, email, phone_number,
            alternative_phone_number, employment_date,
            account_status, created_at, last_login_at
     FROM users
     WHERE user_id = ? AND role = 'employee' LIMIT 1"
);
$stmt->execute([$employeeId]);
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    http_response_code(404);
    exit('Employee profile not found.');
}

// An employee can read actions they performed, never another user's audit records.
$stmt = $conn->prepare(
    'SELECT action_key, module_name, details, created_at
     FROM audit_logs
     WHERE actor_user_id = ?
     ORDER BY created_at DESC, audit_id DESC
     LIMIT 30'
);
$stmt->execute([$employeeId]);
$audit = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$initial = strtoupper(substr(trim((string)$profile['full_name']), 0, 1)) ?: 'E';

nexShellStart($employee, 'My Settings', 'mySettings.php', $csrf, [
    'mainClass' => 'employee-page my-settings-page'
]);
?>

<div class="my-settings-heading">
    <div>
        <p class="eyebrow">MY ACCOUNT / SETTINGS</p>
        <h2>My Settings</h2>
        <p>View your account information and recent activity.</p>
    </div>
</div>

<div class="my-settings-grid">
    <section class="my-settings-card" aria-labelledby="myProfileTitle">
        <div class="my-settings-profile">
            <span class="my-settings-avatar" aria-hidden="true"><?= h($initial) ?></span>
            <div>
                <h3 id="myProfileTitle"><?= h((string)$profile['full_name']) ?></h3>
                <p>Employee account</p>
            </div>
        </div>

        <dl class="my-settings-details">
            <div><dt>Full name</dt><dd><?= h((string)$profile['full_name']) ?></dd></div>
            <div><dt>Username</dt><dd><?= h((string)$profile['username']) ?></dd></div>
            <div><dt>Email address</dt><dd><?= h((string)$profile['email']) ?></dd></div>
            <div><dt>Phone number</dt><dd><?= h((string)($profile['phone_number'] ?: 'Not provided')) ?></dd></div>
            <div><dt>Alternative phone</dt><dd><?= h((string)($profile['alternative_phone_number'] ?: 'Not provided')) ?></dd></div>
            <div><dt>Employment date</dt><dd><?= h((string)($profile['employment_date'] ?: 'Not recorded')) ?></dd></div>
            <div><dt>Account status</dt><dd><span class="my-settings-status"><?= h(ucfirst((string)$profile['account_status'])) ?></span></dd></div>
            <div><dt>Last login</dt><dd><?= h((string)($profile['last_login_at'] ?: 'Not recorded')) ?></dd></div>
        </dl>

        <form method="post" action="logout.php" class="my-settings-signout">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <button type="submit" class="my-settings-logout-button">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Log Out
            </button>
        </form>
    </section>

    <section class="my-settings-card" aria-labelledby="myAuditTitle">
        <div class="my-settings-section-title">
            <h3 id="myAuditTitle"><i class="bi bi-clock-history" aria-hidden="true"></i> My Audit Log</h3>
            <p>Your 30 most recent recorded actions.</p>
        </div>
        <?php if (!$audit): ?>
            <div class="my-settings-empty">
                <i class="bi bi-journal-check" aria-hidden="true"></i>
                <strong>No recorded actions yet</strong>
                <p>Your activity will appear here when a system action is recorded.</p>
            </div>
        <?php else: ?>
            <div class="my-settings-audit-scroll">
                <table class="my-settings-audit-table">
                    <thead>
                        <tr><th scope="col">Date &amp; time</th><th scope="col">Module</th><th scope="col">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit as $entry): ?>
                            <tr>
                                <td><?= h((string)$entry['created_at']) ?></td>
                                <td><?= h(ucwords(str_replace('_', ' ', (string)$entry['module_name']))) ?></td>
                                <td>
                                    <strong><?= h(ucwords(str_replace(['.', '_'], ' ', (string)$entry['action_key']))) ?></strong>
                                    <?php if (!empty($entry['details'])): ?>
                                        <small><?= h((string)$entry['details']) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php nexShellEnd(); ?>
