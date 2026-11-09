<?php
declare(strict_types=1);

/*
 * Shared notifications for the NEXSUS Owner and Employee dashboards.
 * Include this file after authorization.php.
 *
 * On the next page load, create missing alerts for:
 * - overdue tasks: owner and assigned employee;
 * - new contact requests: owner only.
 *
 * NOT EXISTS checks all earlier alerts, including those already read,
 * so refreshing a page does not normally create the same alert again.
 */

function nexsus_sync_notifications(mysqli $conn, int $userId): void {
    // Both the owner and the assigned employee need to know about overdue tasks.
    $sql = "
        INSERT INTO notifications
            (user_id, notification_type, title, message, reference_type, reference_id)
        SELECT u.user_id,
               'task_overdue',
               'Task overdue',
               LEFT(CONCAT(t.task_title, ' — due ', t.due_date), 500),
               'task',
               t.task_id
        FROM users u
        JOIN tasks t ON (
            u.role = 'owner'
            OR (u.role = 'employee' AND t.assigned_to = u.user_id)
        )
        WHERE u.user_id = ?
          AND u.account_status = 'active'
          AND t.status IN ('pending', 'in_progress')
          AND t.due_date < CURDATE()
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.user_id = u.user_id
                AND n.notification_type = 'task_overdue'
                AND n.reference_type = 'task'
                AND n.reference_id = t.task_id
          )";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
    $stmt->close();

    // Contact enquiries are private to the owner: employees never receive them.
    $sql = "
        INSERT INTO notifications
            (user_id, notification_type, title, message, reference_type, reference_id)
        SELECT u.user_id,
               'contact_request',
               'New contact request',
               LEFT(CONCAT('New enquiry from ', cr.name), 500),
               'contact_request',
               cr.id
        FROM users u
        JOIN contact_requests cr ON cr.status = 'new'
        WHERE u.user_id = ?
          AND u.role = 'owner'
          AND u.account_status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.user_id = u.user_id
                AND n.notification_type = 'contact_request'
                AND n.reference_type = 'contact_request'
                AND n.reference_id = cr.id
          )";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
    $stmt->close();
}

function nexsus_notification_bell(mysqli $conn, int $userId, ?string $role = null): void {
    // This runs only for the authenticated account shown in the shared header.
    try {
        nexsus_sync_notifications($conn, $userId);
    } catch (mysqli_sql_exception $e) {
        error_log('NEXSUS notification sync: ' . $e->getMessage());
        // Keep the dashboard usable if an alert could not be generated.
    }

    // Use the current login's role. Do not hard-code employee IDs.
    if ($role !== 'owner' && $role !== 'employee') {
        $roleStmt = $conn->prepare('SELECT role FROM users WHERE user_id=? LIMIT 1');
        $roleStmt->execute([$userId]);
        $role = (string)($roleStmt->get_result()->fetch_assoc()['role'] ?? '');
        $roleStmt->close();
    }

    // An old notification for a task reassigned to someone else must not
    // remain visible to the previous employee.
    $employeeScope = $role === 'employee'
        ? " AND n.notification_type <> 'contact_request'
            AND (COALESCE(n.reference_type, '') <> 'task'
                 OR EXISTS (SELECT 1 FROM tasks assigned_task
                            WHERE assigned_task.task_id=n.reference_id
                              AND assigned_task.assigned_to=n.user_id))"
        : '';

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM notifications n
         WHERE n.user_id=? AND n.read_at IS NULL'.$employeeScope
    );
    $stmt->execute([$userId]);
    $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    $stmt = $conn->prepare(
        'SELECT n.title, n.message, n.created_at
         FROM notifications n
         WHERE n.user_id=? AND n.read_at IS NULL'.$employeeScope.'
         ORDER BY n.created_at DESC, n.notification_id DESC
         LIMIT 4'
    );
    $stmt->execute([$userId]);
    $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    ?>
    <details class="dropdown notification-dropdown nexsus-bell-dropdown">
        <summary class="icon-button nexsus-bell-button"
                 aria-label="Notifications<?= $count ? ': '.$count.' unread' : '' ?>"
                 title="Notifications">
            <i class="bi bi-bell" aria-hidden="true"></i>
            <?php if ($count): ?>
                <span class="nxs-bell-count"><?= $count > 99 ? '99+' : $count ?></span>
            <?php endif; ?>
        </summary>
        <div class="dropdown-panel nexsus-bell-panel">
            <div class="nxs-bell-top">
                <strong>Notifications</strong>
                <span><?= $count ?> unread</span>
            </div>
            <?php if (!$recent): ?>
                <p class="nxs-bell-empty">You're all caught up.</p>
            <?php else: ?>
                <div class="nxs-bell-items">
                    <?php foreach ($recent as $notice): ?>
                        <div class="nxs-bell-item">
                            <strong><?= h((string)$notice['title']) ?></strong>
                            <p><?= h((string)$notice['message']) ?></p>
                            <small><?= h((string)$notice['created_at']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <a class="nxs-bell-all" href="notifications.php">
                View unread notifications
                <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </details>
    <?php
}
