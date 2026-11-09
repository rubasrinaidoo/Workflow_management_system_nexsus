<?php
declare(strict_types=1);

/** NEXSUS notifications: one private inbox per logged-in user; no new SQL tables. */
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/notification_bell.php';
require_once __DIR__ . '/nexsus_shell.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$user = require_logged_in();
$userId = (int)$user['user_id'];
$isOwner = $user['role'] === 'owner';
$csrf = csrf_token();

// The database already declares these ENUM values. Unknown types are never accepted as filters.
$types = [
    'task_assigned' => 'Task assigned',
    'task_completed' => 'Task completed',
    'deadline_upcoming' => 'Upcoming deadline',
    'task_overdue' => 'Overdue task',
    'contact_request' => 'Contact request',
    'document_uploaded' => 'Document uploaded',
    'other' => 'Other',
];
function nxs_notice_icon(string $type): string {
    return match ($type) {
        'task_assigned' => 'bi-clipboard-check',
        'task_completed' => 'bi-check-circle',
        'deadline_upcoming' => 'bi-calendar-event',
        'task_overdue' => 'bi-exclamation-triangle',
        'contact_request' => 'bi-envelope',
        'document_uploaded' => 'bi-file-earmark-arrow-up',
        default => 'bi-bell',
    };
}

/** Use only known local routes, never database-supplied URLs or foreign keys. */
function nxs_notice_link(array $item, bool $isOwner): ?string {
    $id = (int)($item['reference_id'] ?? 0);
    $type = (string)($item['reference_type'] ?? '');
    if ($id < 1) return null;
    return match ($type) {
        'task' => $isOwner ? 'tasks.php?view='.$id.'#taskDetails' : 'myTasks.php?view='.$id.'#myTaskDetails',
        'service_request' => $isOwner ? 'serviceRequests.php?view='.$id.'#requestDetails' : 'myServiceRequests.php?view='.$id.'#requestDetails',
        'document' => $isOwner ? 'documents.php' : 'myDocuments.php',
        'contact_request' => $isOwner ? 'contactRequests.php?view='.$id.'#requestDetails' : null,
        default => null,
    };
}
function nxs_notice_rows(mysqli $conn, string $sql, array $values = []): array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * An employee may see only task alerts for tasks presently assigned to them.
 * Other employee alerts must still be addressed directly to their user_id.
 * Contact enquiries are always owner-only, including old incorrect deliveries.
 * Uses n.user_id from each notification row, never a hard-coded employee ID.
 */
function nxs_notice_scope(bool $isOwner): string {
    if ($isOwner) return '';
    return " AND n.notification_type <> 'contact_request'
        AND (COALESCE(n.reference_type, '') <> 'task'
             OR EXISTS (
                 SELECT 1 FROM tasks assigned_task
                 WHERE assigned_task.task_id = n.reference_id
                   AND assigned_task.assigned_to = n.user_id
             ))";
}
$scope = nxs_notice_scope($isOwner);

// Writes: only POST; CSRF + ownership in SQL. Never deletes notification history.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        http_response_code(403);
        exit('Invalid security token. Refresh this page and try again.');
    }
    $action = post_string('action');
    if ($action === 'mark_one' || $action === 'open_one') {
        $noticeId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]);
        if (!$noticeId) {
            http_response_code(400);
            exit('Invalid notification.');
        }

        // Ownership and current assignment are checked BEFORE marking or opening.
        $row = nxs_notice_rows($conn,
            'SELECT n.notification_id,n.reference_type,n.reference_id
             FROM notifications n WHERE n.notification_id=? AND n.user_id=?'
             .$scope.' LIMIT 1', [(int)$noticeId, $userId])[0] ?? null;
        if (!$row) {
            flash('error', 'Notification unavailable for your account.');
            redirect_to('notifications.php');
        }

        $stmt = $conn->prepare(
            'UPDATE notifications n SET read_at=NOW()
             WHERE n.notification_id=? AND n.user_id=? AND n.read_at IS NULL'.$scope
        );
        $stmt->execute([(int)$noticeId, $userId]);
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($action === 'open_one') {
            // The destination is constructed from known page names and record IDs.
            // Task/employee pages enforce their own authorization too.
            $target = nxs_notice_link($row, $isOwner);
            redirect_to($target ?: 'notifications.php');
        }
        flash('success', $affected
            ? 'Notification marked as read.'
            : 'Notification was already read.');
    } elseif ($action === 'mark_all') {
        $stmt = $conn->prepare(
            'UPDATE notifications n SET read_at=NOW()
             WHERE n.user_id=? AND n.read_at IS NULL'.$scope
        );
        $stmt->execute([$userId]);
        $affected = $stmt->affected_rows;
        $stmt->close();
        flash('success', $affected
            ? $affected.' notification(s) marked as read.'
            : 'No unread notifications.');
    } else {
        http_response_code(400);
        exit('Unknown action.');
    }
    redirect_to('notifications.php');
}

// Refresh this authenticated account's automatic alerts before calculating the totals.
// Existing unread or read alerts are not re-created.
if (function_exists('nexsus_sync_notifications')) {
    try {
        nexsus_sync_notifications($conn, $userId);
    } catch (mysqli_sql_exception $e) {
        error_log('NEXSUS notification refresh: ' . $e->getMessage());
    }
}

$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'unread';
if (!in_array($status, ['all','unread','read'], true)) $status = 'unread';
$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : 'all';
if ($type !== 'all' && !isset($types[$type])) $type = 'all';

$summary = nxs_notice_rows($conn, 'SELECT COUNT(*) AS total,
    COALESCE(SUM(read_at IS NULL),0) AS unread,
    COALESCE(SUM(read_at IS NOT NULL),0) AS `read_count`,
    COALESCE(SUM(DATE(created_at)=CURDATE()),0) AS today
    FROM notifications n WHERE n.user_id=?'.$scope, [$userId])[0];

$where = ['n.user_id=?'.$scope];
$values = [$userId];
if ($status === 'unread') $where[] = 'n.read_at IS NULL';
if ($status === 'read') $where[] = 'n.read_at IS NOT NULL';
if ($type !== 'all') { $where[] = 'n.notification_type=?'; $values[] = $type; }
$whereSql = implode(' AND ', $where);
$totalFiltered = (int)nxs_notice_rows($conn, 'SELECT COUNT(*) AS total FROM notifications n WHERE '.$whereSql, $values)[0]['total'];
$pageSize = 15;
$pages = max(1, (int)ceil($totalFiltered / $pageSize));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 1;
$page = min((int)$page, $pages);
$offset = ($page - 1) * $pageSize;
$notices = nxs_notice_rows($conn,
    'SELECT n.notification_id, n.notification_type, n.title, n.message, n.reference_type, n.reference_id, n.read_at, n.created_at
     FROM notifications n WHERE '.$whereSql.' ORDER BY n.created_at DESC, n.notification_id DESC
     LIMIT '.$pageSize.' OFFSET '.$offset, $values);
$message = get_flash();

$listLink = static function (string $newStatus, string $newType, int $newPage = 1): string {
    $args = [];
    if ($newStatus !== 'unread') $args['status'] = $newStatus;
    if ($newType !== 'all') $args['type'] = $newType;
    if ($newPage > 1) $args['page'] = $newPage;
    return 'notifications.php'.($args ? '?'.http_build_query($args) : '');
};
// The shared shell renders the complete, role-appropriate sidebar, topbar,
// notification bell, profile menu and opening <main> element.
nexShellStart($user, 'Notifications', 'notifications.php', $csrf, [
    'mainClass' => 'employee-page nxs-notifications-page'
]);
?>
      <div class="employee-heading">
        <div><p class="eyebrow"><?= $isOwner ? 'MONITORING' : 'MY WORKSPACE' ?> / NOTIFICATIONS</p><h2>Notification Centre</h2><p>Stay on top of task updates and important work activity.</p></div>
        <?php if ((int)$summary['unread'] > 0): ?>
          <form method="post" action="notifications.php"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="mark_all"><button class="secondary-button" type="submit"><i class="bi bi-check2-all" aria-hidden="true"></i> Mark all as read</button></form>
        <?php endif; ?>
      </div>
      <?php if ($message): ?><p class="nxs-notice-flash" role="status"><?= h((string)$message['message']) ?></p><?php endif; ?>
      <section class="nxs-notice-stats" aria-label="Notification overview">
        <article class="nxs-notice-stat"><i class="bi bi-inboxes" aria-hidden="true"></i><small>Total</small><strong><?= (int)$summary['total'] ?></strong></article>
        <article class="nxs-notice-stat"><i class="bi bi-bell" aria-hidden="true"></i><small>Unread</small><strong><?= (int)$summary['unread'] ?></strong></article>
        <article class="nxs-notice-stat"><i class="bi bi-check-circle" aria-hidden="true"></i><small>Read</small><strong><?= (int)$summary['read_count'] ?></strong></article>
        <article class="nxs-notice-stat"><i class="bi bi-calendar-day" aria-hidden="true"></i><small>Today</small><strong><?= (int)$summary['today'] ?></strong></article>
      </section>

      <section class="employee-directory nxs-notice-directory" aria-labelledby="nxsInboxTitle">
        <div class="section-title"><div><h3 id="nxsInboxTitle">Your Notifications</h3><p>Unread notifications are shown first. Use the Read filter to review earlier alerts.<?= !$isOwner ? ' Task alerts follow your current task assignments.' : '' ?></p></div><span class="employee-result-count"><?= $totalFiltered ?> result<?= $totalFiltered === 1 ? '' : 's' ?></span></div>
        <form class="nxs-notice-filters" action="notifications.php" method="get">
          <label for="nxsStatus">Status
            <select id="nxsStatus" name="status">
              <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
              <option value="unread" <?= $status === 'unread' ? 'selected' : '' ?>>Unread</option>
              <option value="read" <?= $status === 'read' ? 'selected' : '' ?>>Read</option>
            </select>
          </label>
          <label for="nxsType">Type
            <select id="nxsType" name="type">
              <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All types</option>
              <?php foreach($types as $key => $label): ?><option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <button class="secondary-button" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
          <?php if ($status !== 'unread' || $type !== 'all'): ?><a class="secondary-button" href="notifications.php">Reset filters</a><?php endif; ?>
        </form>

        <?php if (!$notices): ?>
          <div class="nxs-notice-empty"><i class="bi bi-bell-slash" aria-hidden="true"></i><h4>No notifications found</h4><p>Notifications will appear here when there is activity relevant to your account.</p></div>
        <?php else: ?>
          <div class="nxs-notice-list">
          <?php foreach($notices as $notice): ?>
            <?php $unread = $notice['read_at'] === null; $target = nxs_notice_link($notice, $isOwner); ?>
            <article class="nxs-notice-item <?= $unread ? 'nxs-unread' : '' ?>">
              <span class="nxs-notice-symbol"><i class="bi <?= h(nxs_notice_icon((string)$notice['notification_type'])) ?>" aria-hidden="true"></i></span>
              <div class="nxs-notice-main">
                <div class="nxs-notice-title"><strong><?= h((string)$notice['title']) ?></strong><?php if ($unread): ?><span class="nxs-unread-tag">New</span><?php endif; ?></div>
                <p><?= h((string)$notice['message']) ?></p>
                <small><?= h($types[(string)$notice['notification_type']] ?? 'Notification') ?> · <?= h((string)$notice['created_at']) ?></small>
              </div>
              <div class="nxs-notice-actions">
                <?php if ($target): ?>
                  <form method="post" action="notifications.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="notification_id" value="<?= (int)$notice['notification_id'] ?>">
                    <input type="hidden" name="action" value="open_one">
                    <button class="secondary-button" type="submit">View</button>
                  </form>
                <?php endif; ?>
                <?php if ($unread): ?>
                  <form method="post" action="notifications.php"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="notification_id" value="<?= (int)$notice['notification_id'] ?>"><input type="hidden" name="action" value="mark_one"><button class="secondary-button" type="submit">Mark as read</button></form>
                <?php else: ?><span class="nxs-read-label"><i class="bi bi-check2" aria-hidden="true"></i> Read</span><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($pages > 1): ?>
          <nav class="nxs-notice-pagination" aria-label="Notification pages">
            <?php if ($page > 1): ?><a class="secondary-button" href="<?= h($listLink($status,$type,$page-1)) ?>">Previous</a><?php endif; ?>
            <span>Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="secondary-button" href="<?= h($listLink($status,$type,$page+1)) ?>">Next</a><?php endif; ?>
          </nav>
        <?php endif; ?>
      </section>
<?php nexShellEnd(); ?>
