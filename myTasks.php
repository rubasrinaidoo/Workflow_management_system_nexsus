<?php
declare(strict_types=1);
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/task_lib.php';
require_once __DIR__ . '/nexsus_shell.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$employee = require_logged_in('employee');
$employeeId = (int)$employee['user_id'];
$csrf = csrf_token();
$today = date('Y-m-d');

$missing = taskSetupError($conn);
if ($missing !== null) { http_response_code(503); exit(h($missing)); }

$errors = [];
$noteDraft = ''; // Keeps what the employee typed if validation fails.

// ---------- Save progress / mark completed (PHP re-checks everything) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Refresh the page.'); }
    $action = post_string('action');
    $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
    $note = trim(post_string('progress_note'));
    $noteDraft = $note;
    $started = false;

    if (!$taskId) $errors[] = 'Select a task.';
    if (taskTextLength($note) > 500) $errors[] = 'Progress note must be 500 characters or fewer.';
    if (taskTextLength($note) < 3) $errors[] = 'Enter a short progress note of at least 3 characters.';
    if (!in_array($action, ['progress', 'complete'], true)) $errors[] = 'Unknown action.';

    if (!$errors) {
        try {
            $conn->begin_transaction(); $started = true;
            $task = taskOne($conn,
                'SELECT task_id,task_title,status,assigned_to,created_by FROM tasks WHERE task_id=? FOR UPDATE',
                [$taskId]);
            if (!$task || (int)$task['assigned_to'] !== $employeeId) {
                $errors[] = 'This task is not assigned to your account.';
            } elseif ($task['status'] === 'completed') {
                $errors[] = 'This task is already completed.';
            }

            if (!$errors) {
                if ($action === 'complete') {
                    taskWrite($conn, "UPDATE tasks SET status='completed',completed_at=NOW(),progress_notes=? WHERE task_id=?", [$note, $taskId]);
                    taskHistory($conn, (int)$taskId, $task['status'], 'completed', $note, $employeeId);
                    taskNotify($conn, (int)$task['created_by'], 'task_completed', 'Task completed', $task['task_title'] . ' has been completed.', (int)$taskId);
                    taskLog($conn, $employeeId, 'task.completed', (int)$taskId, 'Employee marked assigned task completed.');
                } else {
                    if ($task['status'] === 'pending') {
                        taskWrite($conn, "UPDATE tasks SET status='in_progress',progress_notes=? WHERE task_id=?", [$note, $taskId]);
                        taskHistory($conn, (int)$taskId, 'pending', 'in_progress', $note, $employeeId);
                    } else {
                        taskWrite($conn, 'UPDATE tasks SET progress_notes=? WHERE task_id=?', [$note, $taskId]);
                        // Every progress update is recorded, so the client's Workflow History shows it.
                        taskHistory($conn, (int)$taskId, 'in_progress', 'in_progress', $note, $employeeId);
                    }
                    taskLog($conn, $employeeId, 'task.progress', (int)$taskId, 'Employee updated task progress.');
                }
                $conn->commit(); $started = false;
                flash('success', $action === 'complete' ? 'Task marked Completed.' : 'Progress updated.');
                taskRedirect('myTasks.php?view=' . $taskId . '#myTaskDetails');
            }
            $conn->rollback(); $started = false;
        } catch (Throwable $exception) {
            if ($started) $conn->rollback();
            error_log('NEXSUS employee task update: ' . $exception->getMessage());
            $errors[] = 'Could not update the task. Check the server error log.';
        }
    }
}

// ---------- Data for the page ----------
$counts = taskOne($conn, "SELECT COUNT(*) total_count,
    COALESCE(SUM(status IN ('pending','in_progress')),0) open_count,
    COALESCE(SUM(status='completed'),0) completed_count,
    COALESCE(SUM(status<>'completed' AND due_date<CURDATE()),0) overdue_count
    FROM tasks WHERE assigned_to=?", [$employeeId]);

$tasks = taskRows($conn, "SELECT t.task_id,t.task_title,t.description,t.start_date,t.due_date,t.priority,t.status,t.progress_notes,
    s.service_name,
    CASE WHEN c.client_type='business' THEN c.business_name ELSE CONCAT_WS(' ',c.first_name,c.last_name) END client_name
    FROM tasks t
    JOIN service_requests sr ON sr.service_request_id=t.service_request_id
    JOIN clients c ON c.client_id=sr.client_id
    JOIN services s ON s.service_id=sr.service_id
    WHERE t.assigned_to=? ORDER BY (t.status='completed'),t.due_date,t.task_id DESC", [$employeeId]);

$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$selected = null;
foreach ($tasks as $candidate) {
    if ((int)$candidate['task_id'] === $viewId) { $selected = $candidate; break; }
}

$recent = taskRows($conn, "SELECT notification_id,title,message,created_at FROM notifications
    WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT 5", [$employeeId]);

$history = $selected ? taskRows($conn, "SELECT h.previous_status,h.new_status,h.progress_note,h.changed_at,u.full_name changed_by_name
    FROM workflow_history h LEFT JOIN users u ON u.user_id=h.changed_by
    WHERE h.entity_type='task' AND h.entity_id=? ORDER BY h.changed_at DESC LIMIT 15", [$viewId]) : [];

$message = get_flash();
$initial = strtoupper(substr(trim((string)$employee['full_name']), 0, 1)) ?: 'E';
$statusLabel = static fn(?string $s): string => $s === null || $s === '' ? 'New' : ucwords(str_replace('_', ' ', $s));

nexShellStart($employee, 'My Tasks', 'myTasks.php', $csrf, ['mainClass' => 'task-page']);
?>
    <main id="pageContent" class="page-content task-page">
      <div class="task-heading">
        <div><p class="task-eyebrow">MY WORK / TASKS</p><h2>My Assigned Tasks</h2><p>Update progress and mark finished tasks as completed.</p></div>
      </div>

      <?php if ($message): ?><div class="task-alert success" role="status"><?= h((string)$message['message']) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="task-alert error" role="alert"><strong>Please correct:</strong><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <section class="task-stats" aria-label="My task totals">
        <article class="task-stat"><span><i class="bi bi-list-task" aria-hidden="true"></i> Total Tasks</span><strong><?= (int)$counts['total_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-hourglass-split" aria-hidden="true"></i> Open</span><strong><?= (int)$counts['open_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Overdue</span><strong><?= (int)$counts['overdue_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-check2-circle" aria-hidden="true"></i> Completed</span><strong><?= (int)$counts['completed_count'] ?></strong></article>
      </section>

      <section class="task-panel" id="taskDirectory">
        <div class="task-panel-top"><div><h3>My Work</h3><p>Only tasks assigned to your account are listed.</p></div><span class="task-count"><?= count($tasks) ?> task<?= count($tasks) === 1 ? '' : 's' ?></span></div>
        <?php if (!$tasks): ?>
          <p class="task-hint">No tasks assigned to you yet.</p>
        <?php else: ?>
          <div class="task-list">
          <?php foreach ($tasks as $task): ?>
            <article class="task-item">
              <div class="task-item-head">
                <div><h4><?= h((string)$task['task_title']) ?></h4><small><?= h($task['client_name'] . ' · ' . $task['service_name']) ?></small></div>
                <span class="task-badge <?= h((string)$task['status']) ?>"><?= h($statusLabel((string)$task['status'])) ?></span>
              </div>
              <div class="task-item-meta">
                <span><i class="bi bi-calendar" aria-hidden="true"></i> Due <?= h((string)$task['due_date']) ?></span>
                <span class="task-badge priority-<?= h((string)$task['priority']) ?>"><?= h(ucfirst((string)$task['priority'])) ?> priority</span>
                <?php if ($task['status'] !== 'completed' && $task['due_date'] < $today): ?><span class="task-badge overdue">Overdue</span><?php endif; ?>
              </div>
              <a class="task-btn outline" href="myTasks.php?view=<?= (int)$task['task_id'] ?>#myTaskDetails">View / Update</a>
            </article>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($selected): ?>
      <section class="task-panel" id="myTaskDetails">
        <div class="task-panel-top">
          <div><h3><?= h((string)$selected['task_title']) ?></h3><p><?= h($selected['client_name'] . ' · ' . $selected['service_name']) ?></p></div>
          <a href="myTasks.php#taskDirectory" class="task-btn outline">Close</a>
        </div>
        <div class="task-facts">
          <div><small>Status</small><strong><?= h($statusLabel((string)$selected['status'])) ?></strong></div>
          <div><small>Start date</small><strong><?= h((string)($selected['start_date'] ?: '—')) ?></strong></div>
          <div><small>Due date</small><strong><?= h((string)$selected['due_date']) ?></strong></div>
          <div><small>Priority</small><strong><?= h(ucfirst((string)$selected['priority'])) ?></strong></div>
        </div>
        <?php if ($selected['description']): ?><p class="task-description"><?= nl2br(h((string)$selected['description'])) ?></p><?php endif; ?>
        <?php if ($selected['progress_notes']): ?>
          <div class="task-progress-note"><h4 class="task-subheading">Latest progress note</h4><p><?= nl2br(h((string)$selected['progress_notes'])) ?></p></div>
        <?php endif; ?>

        <?php if ($selected['status'] === 'completed'): ?>
          <div class="task-alert success">This task is completed. Its history is preserved.</div>
        <?php else: ?>
          <form method="post" action="myTasks.php?view=<?= (int)$selected['task_id'] ?>#myTaskDetails" class="task-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="task_id" value="<?= (int)$selected['task_id'] ?>">
            <div class="task-field">
              <label for="progressNote">New progress note <em>*</em></label>
              <textarea name="progress_note" id="progressNote" required minlength="3" maxlength="500" rows="4" placeholder="What have you completed? What is still outstanding?"><?= h($noteDraft) ?></textarea>
              <small>Each note is saved to the task's history, so write a fresh update every time.</small>
            </div>
            <div class="task-actions">
              <button class="task-btn outline" type="submit" name="action" value="progress">Save Progress</button>
              <button class="task-btn primary" type="submit" name="action" value="complete" data-confirm="Mark this task as completed? This cannot be undone.">Mark Completed</button>
            </div>
          </form>
        <?php endif; ?>

        <h4 class="task-subheading">Status History</h4>
        <div class="task-timeline">
          <?php if (!$history): ?><p>No history yet.</p>
          <?php else: foreach ($history as $row): ?>
            <p><strong><?= h($statusLabel($row['previous_status'])) ?> → <?= h($statusLabel((string)$row['new_status'])) ?></strong><?php if (!empty($row['progress_note'])): ?> · <?= h((string)$row['progress_note']) ?><?php endif; ?>
              <small><?= h((string)$row['changed_at'] . ' — ' . (string)($row['changed_by_name'] ?? 'System')) ?></small></p>
          <?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>
     
<script>
// Ask before a task is marked completed, since a completed task becomes read-only.
document.querySelectorAll('[data-confirm]').forEach(function (button) {
  button.addEventListener('click', function (event) {
    if (!window.confirm(button.dataset.confirm)) event.preventDefault();
  });
});
</script>
<?php nexShellEnd(); ?>
