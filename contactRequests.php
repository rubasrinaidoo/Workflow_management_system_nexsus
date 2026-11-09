<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/nexsus_shell.php';

$owner = require_owner();
$csrf = csrf_token();
$ownerId = (int) $owner['user_id'];

function contact_e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_status_name(string $status): string {
    return match ($status) {
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        default => 'New'
    };
}

$allowedStatuses = ['new', 'in_progress', 'completed'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Your security token is invalid. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_status') {
            $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            $newStatus = $_POST['status'] ?? '';

            if (!$requestId || !is_string($newStatus) ||
                !in_array($newStatus, $allowedStatuses, true)) {
                $errors[] = 'Please select a valid request and status.';
            } else {
                try {
                    $stmt = $conn->prepare('SELECT id FROM contact_requests WHERE id = ? LIMIT 1');
                    $stmt->bind_param('i', $requestId);
                    $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$existing) {
                        $errors[] = 'Contact request not found.';
                    } else {
                        $stmt = $conn->prepare('UPDATE contact_requests SET status = ? WHERE id = ?');
                        $stmt->bind_param('si', $newStatus, $requestId);
                        $stmt->execute();
                        $stmt->close();

                        // Changing request status does not create or mark notifications.
                        header('Location: contactRequests.php?view=' . $requestId . '&updated=1#requestDetails', true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('NEXSUS contact status update: ' . $exception->getMessage());
                    $errors[] = 'Could not update this request. Please try again.';
                }
            }
        } elseif ($action === 'read_notification') {
            $notificationId = filter_var($_POST['notification_id'] ?? null,
                FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (!$notificationId) {
                $errors[] = 'Invalid notification.';
            } else {
                try {
                    $stmt = $conn->prepare(
                        'SELECT contact_request_id FROM notifications WHERE id = ? AND user_id = ? LIMIT 1'
                    );
                    $stmt->bind_param('ii', $notificationId, $ownerId);
                    $stmt->execute();
                    $notification = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (!$notification) {
                        $errors[] = 'Notification not found.';
                    } else {
                        $stmt = $conn->prepare(
                            "UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ?"
                        );
                        $stmt->bind_param('ii', $notificationId, $ownerId);
                        $stmt->execute();
                        $stmt->close();

                        $linkedId = (int) ($notification['contact_request_id'] ?? 0);
                        $target = $linkedId > 0
                            ? '?view=' . $linkedId . '#requestDetails'
                            : '';
                        header('Location: contactRequests.php' . $target, true, 303);
                        exit;
                    }
                } catch (mysqli_sql_exception $exception) {
                    error_log('NEXSUS notification update: ' . $exception->getMessage());
                    $errors[] = 'Could not open the notification.';
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    }
}

// Search and filter are processed by PHP using prepared statements.
$searchInput = $_GET['q'] ?? '';
$search = is_string($searchInput) ? substr(trim($searchInput), 0, 100) : '';
$filterInput = $_GET['status'] ?? 'all';
$filter = is_string($filterInput) ? $filterInput : 'all';
if (!in_array($filter, ['all', ...$allowedStatuses], true)) {
    $filter = 'all';
}
$sortInput = $_GET['sort'] ?? 'newest';
$sort = is_string($sortInput) && $sortInput === 'oldest' ? 'oldest' : 'newest';

$countResult = $conn->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'new'), 0) AS new_count,
            COALESCE(SUM(status = 'in_progress'), 0) AS progress_count,
            COALESCE(SUM(status = 'completed'), 0) AS completed_count
     FROM contact_requests"
);
$counts = $countResult->fetch_assoc();
$countResult->free();

$sql = 'SELECT id, name, email, phone, preferred_contact, message, status, handled_by, created_at
        FROM contact_requests WHERE 1=1';
if ($filter !== 'all') {
    $sql .= ' AND status = ?';
}
if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR message LIKE ?)';
}
$sql .= $sort === 'oldest'
    ? ' ORDER BY created_at ASC, id ASC LIMIT 50'
    : ' ORDER BY created_at DESC, id DESC LIMIT 50';

$stmt = $conn->prepare($sql);
$pattern = '%' . $search . '%';
if ($filter !== 'all' && $search !== '') {
    $stmt->bind_param('sssss', $filter, $pattern, $pattern, $pattern, $pattern);
} elseif ($filter !== 'all') {
    $stmt->bind_param('s', $filter);
} elseif ($search !== '') {
    $stmt->bind_param('ssss', $pattern, $pattern, $pattern, $pattern);
}
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Show selected enquiry even when outside search results.
$selected = null;
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]);
if ($viewId) {
    $stmt = $conn->prepare('SELECT * FROM contact_requests WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $viewId);
    $stmt->execute();
    $selected = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

// The notification bell is now drawn by the shared shell (nexsus_notification_bell),
// so the page no longer needs its own bell queries.

// Shared shell: sidebar, top bar, bell, theme switch, profile menu.
$user = $owner + ['role' => 'owner'];
nexShellStart($user, 'Contact Requests', 'contactRequests.php', $csrf, ['mainClass' => 'contact-page']);
?>
<div class="contact-page-heading">
    <div>
        <p class="contact-eyebrow">CUSTOMER COMMUNICATION</p>
        <h2>Contact Request Management</h2>
        <p>View enquiries from the public website, see customer details and track every response.</p>
    </div>
    <span class="contact-summary-note"><i class="bi bi-shield-check" aria-hidden="true"></i> Owner access</span>
</div>

<?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
    <p class="contact-feedback good" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i> Request status updated successfully.</p>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="contact-feedback bad" role="alert"><?= contact_e(implode(' ', $errors)) ?></div>
<?php endif; ?>

<section class="contact-stat-grid" aria-label="Contact request overview">
    <div class="contact-stat"><span class="contact-stat-icon"><i class="bi bi-inboxes" aria-hidden="true"></i></span><span>Total Requests</span><strong><?= (int)$counts['total'] ?></strong></div>
    <div class="contact-stat"><span class="contact-stat-icon blue"><i class="bi bi-envelope-exclamation" aria-hidden="true"></i></span><span>New Requests</span><strong><?= (int)$counts['new_count'] ?></strong></div>
    <div class="contact-stat"><span class="contact-stat-icon gold"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span><span>In Progress</span><strong><?= (int)$counts['progress_count'] ?></strong></div>
    <div class="contact-stat"><span class="contact-stat-icon green"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><span>Completed</span><strong><?= (int)$counts['completed_count'] ?></strong></div>
</section>

<section class="contact-panel" aria-labelledby="requests-heading">
    <div class="contact-panel-heading"><div><h3 id="requests-heading">Customer Enquiries</h3><p>Showing up to 50 matching enquiries. Select a request to view its full details.</p></div><span class="contact-count"><?= count($requests) ?> shown</span></div>

    <!-- HTML GET form provides search, status filtering and sorting with no new JavaScript. -->
    <form class="contact-filters" method="get" action="contactRequests.php">
        <label><span>Search enquiries</span><input type="search" name="q" value="<?= contact_e($search) ?>" placeholder="Name, email, phone or message" maxlength="100"></label>
        <label><span>Request status</span><select name="status"><option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All statuses</option><option value="new" <?= $filter === 'new' ? 'selected' : '' ?>>New</option><option value="in_progress" <?= $filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option><option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Completed</option></select></label>
        <label><span>Sort by date</span><select name="sort"><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option></select></label>
        <button type="submit" class="primary-button"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        <a class="secondary-button" href="contactRequests.php">Clear</a>
    </form>

    <div class="contact-quick-filters" aria-label="Quick status filters">
        <?php foreach (['all' => 'All', 'new' => 'New', 'in_progress' => 'In Progress', 'completed' => 'Completed'] as $key => $label): ?>
            <a class="contact-filter-pill <?= $filter === $key ? 'selected' : '' ?>" href="contactRequests.php?status=<?= contact_e($key) ?>&amp;q=<?= rawurlencode($search) ?>&amp;sort=<?= contact_e($sort) ?>"><?= contact_e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$requests): ?>
        <div class="contact-empty"><i class="bi bi-inbox" aria-hidden="true"></i><h4>No requests found</h4><p>Try clearing the filters or wait for a new public enquiry.</p></div>
    <?php else: ?>
        <div class="contact-table-wrap">
            <table class="contact-table">
                <thead><tr><th scope="col">Customer</th><th scope="col">Preferred Contact</th><th scope="col">Message Preview</th><th scope="col">Received</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
                <tbody>
                <?php foreach ($requests as $request): ?>
                    <tr>
                        <td><strong><?= contact_e($request['name']) ?></strong><small><?= contact_e($request['email']) ?></small><small><?= contact_e($request['phone']) ?></small></td>
                        <td><span class="contact-method"><i class="bi <?= $request['preferred_contact'] === 'phone' ? 'bi-telephone' : 'bi-envelope' ?>" aria-hidden="true"></i><?= ucfirst(contact_e($request['preferred_contact'])) ?></span></td>
                        <td><span class="contact-preview"><?= contact_e($request['message']) ?></span></td>
                        <td class="contact-date"><?= contact_e(date('d M Y', strtotime((string)$request['created_at']))) ?><small><?= contact_e(date('H:i', strtotime((string)$request['created_at']))) ?></small></td>
                        <td><span class="contact-status <?= contact_e($request['status']) ?>"><?= contact_e(request_status_name((string)$request['status'])) ?></span></td>
                        <td><a class="contact-view" href="contactRequests.php?view=<?= (int)$request['id'] ?>#requestDetails">View Details <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($selected): ?>
    <section id="requestDetails" class="contact-panel contact-detail" aria-labelledby="detailHeading">
        <div class="contact-panel-heading"><div><p class="contact-eyebrow">REQUEST #<?= (int)$selected['id'] ?></p><h3 id="detailHeading">Enquiry from <?= contact_e($selected['name']) ?></h3><p>Complete customer details and management controls.</p></div><a class="secondary-button" href="contactRequests.php"><i class="bi bi-x-lg" aria-hidden="true"></i> Close</a></div>
        <div class="contact-detail-grid">
            <div class="contact-customer-card">
                <h4><i class="bi bi-person-lines-fill" aria-hidden="true"></i> Customer Details</h4>
                <dl>
                    <div><dt>Full Name</dt><dd><?= contact_e($selected['name']) ?></dd></div>
                    <div><dt>Email Address</dt><dd><a href="mailto:<?= contact_e($selected['email']) ?>"><?= contact_e($selected['email']) ?></a></dd></div>
                    <div><dt>Phone Number</dt><dd><a href="tel:<?= contact_e($selected['phone']) ?>"><?= contact_e($selected['phone']) ?></a></dd></div>
                    <div><dt>Preferred Method</dt><dd><?= ucfirst(contact_e($selected['preferred_contact'])) ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= contact_e(date('d M Y, H:i', strtotime((string)$selected['created_at']))) ?></dd></div>
                    <div><dt>Current Status</dt><dd><span class="contact-status <?= contact_e($selected['status']) ?>"><?= contact_e(request_status_name((string)$selected['status'])) ?></span></dd></div>
                </dl>
                <div class="contact-message"><h4><i class="bi bi-chat-left-text" aria-hidden="true"></i> Customer Message</h4><p><?= nl2br(contact_e($selected['message'])) ?></p></div>
            </div>
            <div class="contact-manage-card">
                <h4><i class="bi bi-sliders" aria-hidden="true"></i> Manage Request</h4>
                <p>Update the enquiry as your team responds to the customer.</p>
                <form method="post" action="contactRequests.php">
                    <input type="hidden" name="csrf_token" value="<?= contact_e($csrf) ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="request_id" value="<?= (int)$selected['id'] ?>">
                    <label for="nextStatus">Request Status <span class="contact-required">*</span></label>
                    <select id="nextStatus" name="status" required>
                        <option value="new" <?= $selected['status'] === 'new' ? 'selected' : '' ?>>New — Not handled yet</option>
                        <option value="in_progress" <?= $selected['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress — Being handled</option>
                        <option value="completed" <?= $selected['status'] === 'completed' ? 'selected' : '' ?>>Completed — Customer contacted</option>
                    </select>
                    <button class="primary-button contact-save" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Save Status</button>
                </form>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php nexShellEnd(); ?>