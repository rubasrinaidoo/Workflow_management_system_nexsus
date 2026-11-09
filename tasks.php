<?php
declare(strict_types=1);

require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/task_lib.php';
require_once __DIR__ . '/nexsus_shell.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$owner = require_owner();
$ownerId = (int)$owner['user_id'];
$csrf = csrf_token();

$missing = taskSetupError($conn);
if ($missing !== null) { http_response_code(503); exit(h($missing)); }

$errors = [];
$old = ['service_request_id'=>'','task_title'=>'','description'=>'','assigned_to'=>'',
        'priority'=>'medium','start_date'=>date('Y-m-d'),'due_date'=>''];
$today = date('Y-m-d');
$openCreateModal = false; // Re-open the Create Task modal if the create form has errors.

// Every submitted action is checked again in PHP, never only in JavaScript.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload this page.'); }
    $action = post_string('action');
    $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
    $started = false;
    try {
        if (in_array($action, ['create','edit'], true)) {
            $requestId = filter_var($_POST['service_request_id'] ?? null, FILTER_VALIDATE_INT);
            $employeeId = filter_var($_POST['assigned_to'] ?? null, FILTER_VALIDATE_INT);
            $title = trim(post_string('task_title'));
            $description = trim(post_string('description'));
            $startDate = post_string('start_date');
            $dueDate = post_string('due_date');
            $priority = post_string('priority');
            if ($action === 'create') {
                $old = compact('description','priority');
                $old['service_request_id'] = (string)$requestId;
                $old['assigned_to'] = (string)$employeeId;
                $old['task_title'] = $title;
                $old['start_date'] = $startDate;
                $old['due_date'] = $dueDate;
            }
            if (!$requestId || !$employeeId) $errors[] = 'Choose a service request and an active employee.';
            if (taskTextLength($title) < 3 || taskTextLength($title) > 180) $errors[] = 'Task title must be 3–180 characters.';
            if (taskTextLength($description) > 500) $errors[] = 'Task description must be 500 characters or fewer.';
            if (!in_array($priority, ['low','medium','high'], true)) $errors[] = 'Select Low, Medium or High priority.';
          
			if (!taskValidDate($startDate) || !taskValidDate($dueDate)) {
				$errors[] = 'Enter valid start and due dates.';
			} elseif ($dueDate < $startDate) {
				$errors[] = 'Due date cannot be before the start date.';
			} elseif ($action === 'create' && $dueDate < $today) {
				$errors[] = 'New tasks must have a future or current due date.';
			}

            if ($action === 'edit' && !$taskId) $errors[] = 'Choose a task to edit.';

            if (!$errors) {
                $conn->begin_transaction(); $started = true;
                $request = taskOne($conn,
                    'SELECT service_request_id,due_date,request_status FROM service_requests WHERE service_request_id=? FOR UPDATE',
                    [$requestId]);
                $employee = taskOne($conn,
                    "SELECT user_id FROM users WHERE user_id=? AND role='employee' AND account_status='active' FOR UPDATE",
                    [$employeeId]);
                if (!$request || !in_array($request['request_status'], ['pending','in_progress'], true)) {
                    $errors[] = 'Select a pending or in-progress service request.';
                }
                if (!$employee) $errors[] = 'The employee is missing, inactive or suspended.';
                if ($request && $dueDate > $request['due_date']) {
                    $errors[] = 'Task due date exceeds the service request deadline. Update the service request deadline first.';
                }
			$existing = null;

if ($action === 'edit') {

    $existing = taskOne($conn,
        'SELECT task_id, assigned_to, status, service_request_id, due_date
         FROM tasks WHERE task_id = ? FOR UPDATE',
        [$taskId]
    );

    if (!$existing) {
        $errors[] = 'Task not found.';
    } elseif ($existing['status'] === 'completed') {
        $errors[] = 'Completed tasks cannot be edited.';
    } elseif ((int)$existing['service_request_id'] !== (int)$requestId) {
        $errors[] = 'A task cannot be moved to another service request.';
    } elseif ($dueDate !== $existing['due_date'] && $dueDate < $today) {
        $errors[] = 'A changed deadline must be today or later.';
    }
			}
                if (!$errors) {
                    if ($action === 'create') {
                        taskWrite($conn, "INSERT INTO tasks (service_request_id,task_title,description,assigned_to,created_by,start_date,due_date,priority,status)
                                VALUES (?,?,?,?,?,?,?,?,'pending')",
                            [$requestId,$title,$description !== '' ? $description : null,$employeeId,$ownerId,$startDate,$dueDate,$priority]);
                        $createdId = (int)$conn->insert_id;
                        taskHistory($conn,$createdId,null,'pending','Task created and assigned by Owner.',$ownerId);
                        taskLog($conn,$ownerId,'task.created',$createdId,'New task assigned to employee '.$employeeId);
                        taskNotify($conn,$employeeId,'task_assigned','New task assigned',$title.' — due '.$dueDate,$createdId);
                    } else {
                        taskWrite($conn, "UPDATE tasks SET task_title=?,description=?,assigned_to=?,start_date=?,due_date=?,priority=? WHERE task_id=?",
                            [$title,$description !== '' ? $description : null,$employeeId,$startDate,$dueDate,$priority,$taskId]);
                        taskLog($conn,$ownerId,'task.updated',(int)$taskId,'Owner edited task details.');
                        if ((int)$existing['assigned_to'] !== (int)$employeeId) {
                            taskNotify($conn,$employeeId,'task_assigned','Task assigned to you',$title.' — due '.$dueDate,(int)$taskId);
                        }
                    }
                    $conn->commit(); $started = false;
                    flash('success',$action === 'create' ? 'Task created and assigned successfully.' : 'Task updated successfully.');
                    taskRedirect($action === 'create' ? 'tasks.php?view='.$createdId.'#taskDetails' : 'tasks.php?view='.$taskId.'#taskDetails');
                }
                $conn->rollback(); $started = false;
            }
        } else {
            $errors[] = 'Unknown task action.';
        }
    } catch (Throwable $exception) {
        if ($started) $conn->rollback();
        error_log('NEXSUS task management: '.$exception->getMessage());
        $errors[] = 'Could not save the task. Check your database schema and server error log.';
    }
    $openCreateModal = ($errors && ($action ?? '') === 'create');
}

// Owner filters, task details and counts use live database queries (no report table).
$requests = taskRows($conn, "SELECT sr.service_request_id,sr.due_date,sr.request_status,s.service_name,
    CASE WHEN c.client_type='business' THEN c.business_name ELSE CONCAT_WS(' ',c.first_name,c.last_name) END client_name
    FROM service_requests sr JOIN clients c ON c.client_id=sr.client_id
    JOIN services s ON s.service_id=sr.service_id
    WHERE sr.request_status IN ('pending','in_progress') ORDER BY sr.due_date,sr.service_request_id");
// A short-staffed company can assign MANY tasks to one employee.
// Count open tasks for helpful display, but do NOT impose a limit.
$employees = taskRows($conn,"SELECT u.user_id,u.full_name, COUNT(t.task_id) AS open_tasks
    FROM users u LEFT JOIN tasks t ON t.assigned_to=u.user_id
        AND t.status IN ('pending','in_progress')
    WHERE u.role='employee' AND u.account_status='active'
    GROUP BY u.user_id,u.full_name ORDER BY u.full_name");
$counts = taskOne($conn,"SELECT COUNT(*) total_count,
    COALESCE(SUM(status IN ('pending','in_progress')),0) open_count,
    COALESCE(SUM(status <> 'completed' AND due_date < CURDATE()),0) overdue_count,
    COALESCE(SUM(status='completed'),0) completed_count FROM tasks");
$filter = (string)($_GET['status'] ?? 'all');
if (!in_array($filter,['all','pending','in_progress','completed','overdue'],true)) $filter='all';
$query = trim((string)($_GET['q'] ?? ''));
$query = mb_substr($query,0,80);
$tasksSql = "SELECT t.task_id,t.task_title,t.description,t.service_request_id,t.assigned_to,t.start_date,t.due_date,
 t.priority,t.status,t.completed_at,t.progress_notes,t.created_at,u.full_name employee_name,
  s.service_name,sr.due_date request_due_date,c.client_id,
 CASE WHEN c.client_type='business' THEN c.business_name ELSE CONCAT_WS(' ',c.first_name,c.last_name) END client_name
 FROM tasks t LEFT JOIN users u ON u.user_id=t.assigned_to
 JOIN service_requests sr ON sr.service_request_id=t.service_request_id
 JOIN clients c ON c.client_id=sr.client_id JOIN services s ON s.service_id=sr.service_id WHERE 1=1";
$params=[];
if ($filter==='overdue') $tasksSql.=" AND t.status<>'completed' AND t.due_date<CURDATE()";
elseif ($filter!=='all') { $tasksSql.=' AND t.status=?'; $params[]=$filter; }
if ($query!=='') { $tasksSql.=' AND (t.task_title LIKE ? OR u.full_name LIKE ? OR s.service_name LIKE ?)'; array_push($params,...array_fill(0,3,'%'.$query.'%')); }
$tasksSql.=' ORDER BY (t.status=\'completed\'),t.due_date,t.task_id DESC LIMIT 100';
$tasks=taskRows($conn,$tasksSql,$params);
$byClient = [];
foreach ($tasks as $t) {
    $cid = (int)$t['client_id'];
    if (!isset($byClient[$cid])) {
        $byClient[$cid] = ['client_id' => $cid, 'client_name' => (string)$t['client_name'], 'tasks' => []];
    }
    $byClient[$cid]['tasks'][] = $t;
}
$viewId=filter_var($_GET['view']??null,FILTER_VALIDATE_INT);
$selected=null;$history=[];
if ($viewId) {
    $selected=taskOne($conn,"SELECT t.*,u.full_name employee_name,s.service_name,sr.due_date request_due_date,
      CASE WHEN c.client_type='business' THEN c.business_name ELSE CONCAT_WS(' ',c.first_name,c.last_name) END client_name
      FROM tasks t LEFT JOIN users u ON u.user_id=t.assigned_to
      JOIN service_requests sr ON sr.service_request_id=t.service_request_id JOIN clients c ON c.client_id=sr.client_id
      JOIN services s ON s.service_id=sr.service_id WHERE t.task_id=?",[$viewId]);
    if ($selected) {
        $history=taskRows($conn,"SELECT h.previous_status,h.new_status,h.progress_note,h.changed_at,u.full_name changed_by_name
             FROM workflow_history h LEFT JOIN users u ON u.user_id=h.changed_by
             WHERE h.entity_type='task' AND h.entity_id=? ORDER BY h.changed_at DESC LIMIT 20",[$viewId]);
    }
}
$message=get_flash();
nexShellStart($owner, 'Task Management', 'tasks.php', $csrf, ['mainClass' => 'task-page']);
?>

<style>
.task-page .task-client-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.task-page .task-past {
    border-top: 1px solid var(--line);
}

.task-page .task-past > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    color: var(--text);
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    list-style: none;
}

.task-page .task-past > summary::-webkit-details-marker {
    display: none;
}

.task-page .task-past > summary:hover {
    background: var(--surface-soft);
}

.task-page .task-past > summary .bi-clock-history {
    color: var(--blue);
    margin-right: 7px;
}

.task-page .task-past-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
}

.task-page .task-past[open] .task-past-toggle .bi-chevron-down {
    transform: rotate(180deg);
}

.task-page .task-past-rows {
    border-top: 1px solid var(--line);
}

.task-page .task-row-overdue {
    border-left: 3px solid #bb3636;
}

@media (max-width: 650px) {
    .task-page .task-client-actions {
        width: 100%;
    }

    .task-page .task-past > summary {
        padding: 12px;
    }
}
</style>

      <div class="task-heading">
        <div>
          <p class="task-eyebrow">DAILY OPERATIONS / TASKS</p>
          <h2>Task Management</h2>
          <p>Create tasks, assign employees and monitor progress.</p>
        </div>
        <button class="task-btn primary" type="button" data-open-task-modal aria-haspopup="dialog" aria-controls="taskModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create Task</button>
      </div>

      <?php if ($message): ?><div class="task-alert success" role="status"><?= h((string)$message['message']) ?></div><?php endif; ?>
      <?php if ($errors && !$openCreateModal): ?><div class="task-alert error" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

      <section class="task-stats" aria-label="Task totals">
        <article class="task-stat"><span><i class="bi bi-list-task" aria-hidden="true"></i> Total Tasks</span><strong><?= (int)$counts['total_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-hourglass-split" aria-hidden="true"></i> Open</span><strong><?= (int)$counts['open_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Overdue</span><strong><?= (int)$counts['overdue_count'] ?></strong></article>
        <article class="task-stat"><span><i class="bi bi-check2-circle" aria-hidden="true"></i> Completed</span><strong><?= (int)$counts['completed_count'] ?></strong></article>
      </section>


<?php if (!$tasks): ?>
    <p class="task-hint" id="taskDirectory">
        No tasks match your filters yet.
    </p>
<?php else: ?>
    <div class="task-client-list" id="taskDirectory">

        <?php foreach ($byClient as $group): ?>
            <?php
                $openTasks = array_values(array_filter(
                    $group['tasks'],
                    static fn(array $t): bool =>
                        $t['status'] !== 'completed'
                ));

                $pastTasks = array_values(array_filter(
                    $group['tasks'],
                    static fn(array $t): bool =>
                        $t['status'] === 'completed'
                ));

                $hasOverdue = (bool)array_filter(
                    $openTasks,
                    static fn(array $t): bool =>
                        (string)$t['due_date'] < $today
                );

                $pastExpanded = $filter === 'completed' || (
                    $viewId && in_array(
                        (int)$viewId,
                        array_map(
                            static fn(array $t): int =>
                                (int)$t['task_id'],
                            $pastTasks
                        ),
                        true
                    )
                );
            ?>

            <article class="task-client">

                <header class="task-client-head">
                    <div>
                        <h4>
                            <a href="clientProfile.php?id=<?= (int)$group['client_id'] ?>">
                                <?= h($group['client_name']) ?>
                            </a>
                        </h4>

                        <small>
                            <?= count($openTasks) ?> open ·
                            <?= count($pastTasks) ?> completed
                        </small>
                    </div>

                    <div class="task-client-actions">
                        <?php if ($hasOverdue): ?>
                            <span class="task-badge overdue">
                                Overdue work
                            </span>
                        <?php endif; ?>

                        <a class="task-btn outline"
                           href="clientProfile.php?id=<?= (int)$group['client_id'] ?>#tasks">
                            Client profile
                        </a>
                    </div>
                </header>

                <?php foreach ($openTasks as $task): ?>
                    <?php
                        $overdue =
                            (string)$task['due_date'] < $today;
                    ?>

                    <div class="task-row <?= $overdue ? 'task-row-overdue' : '' ?>">
                        <div class="task-row-main">
                            <strong>
                                <?= h((string)$task['task_title']) ?>
                            </strong>

                            <small>
                                <?= h((string)$task['service_name']) ?>
                                ·
                                <?= h((string)($task['employee_name'] ?? 'Unassigned')) ?>
                                · Due <?= h((string)$task['due_date']) ?>
                                · <?= h(ucfirst((string)$task['priority'])) ?> priority
                            </small>
                        </div>

                        <div class="task-row-side">
                            <span class="task-badge <?= h((string)$task['status']) ?>">
                                <?= h(ucwords(str_replace('_', ' ', (string)$task['status']))) ?>
                            </span>

                            <?php if ($overdue): ?>
                                <span class="task-badge overdue">
                                    Overdue
                                </span>
                            <?php endif; ?>

                            <a class="task-btn outline"
                               href="tasks.php?view=<?= (int)$task['task_id'] ?>#taskDetails">
                                View / Edit
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($pastTasks): ?>
                    <details class="task-past"
                             <?= $pastExpanded ? 'open' : '' ?>>

                        <summary>
                            <span>
                                <i class="bi bi-clock-history" aria-hidden="true"></i>
                                Past tasks (<?= count($pastTasks) ?> completed)
                            </span>

                            <span class="task-past-toggle">
                                Show / Hide
                                <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </span>
                        </summary>

                        <div class="task-past-rows">
                            <?php foreach ($pastTasks as $task): ?>
                                <div class="task-row task-row-completed">

                                    <div class="task-row-main">
                                        <strong>
                                            <?= h((string)$task['task_title']) ?>
                                        </strong>

                                        <small>
                                            <?= h((string)$task['service_name']) ?>
                                            ·
                                            <?= h((string)($task['employee_name'] ?? 'Unassigned')) ?>
                                            · Due <?= h((string)$task['due_date']) ?>
                                        </small>
                                    </div>

                                    <div class="task-row-side">
                                        <span class="task-badge completed">
                                            Completed
                                        </span>

                                        <a class="task-btn outline"
                                           href="tasks.php?view=<?= (int)$task['task_id'] ?>#taskDetails">
                                            View History
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>

            </article>
        <?php endforeach; ?>

    </div>
<?php endif; ?>


      <?php if ($selected): ?>
      <section class="task-panel" id="taskDetails">
        <div class="task-panel-top"><div><h3>Task #<?= (int)$selected['task_id'] ?> — <?= h($selected['task_title']) ?></h3><p><?= h($selected['client_name'].' · '.$selected['service_name']) ?></p></div><a class="task-btn outline" href="tasks.php#taskDirectory">Close</a></div>
        <div class="task-facts">
          <div><small>Assigned employee</small><strong><?= h($selected['employee_name']??'Unassigned') ?></strong></div>
          <div><small>Status</small><strong><?= h(ucwords(str_replace('_',' ',$selected['status']))) ?></strong></div>
          <div><small>Deadline</small><strong><?= h($selected['due_date']) ?></strong></div>
          <div><small>Priority</small><strong><?= h(ucfirst($selected['priority'])) ?></strong></div>
        </div>
        <?php if ($selected['progress_notes']): ?>
          <div class="task-progress-note"><h4 class="task-subheading">Latest Progress</h4><p><?= nl2br(h((string)$selected['progress_notes'])) ?></p></div>
        <?php endif; ?>
        <?php if ($selected['status']!=='completed'): ?>
          <details class="task-edit" <?= ($errors && ($action ?? '')==='edit') ? 'open' : '' ?>><summary><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit Task</summary>

            <?php if ($errors && ($action ?? '')==='edit'): ?><div class="task-alert error" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" action="tasks.php?view=<?= (int)$selected['task_id'] ?>#taskDetails" class="task-form task-edit-form">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="task_id" value="<?= (int)$selected['task_id'] ?>"><input type="hidden" name="service_request_id" value="<?= (int)$selected['service_request_id'] ?>">
              <div class="task-field"><label for="editTitle">Task Title</label><input id="editTitle" name="task_title" required minlength="3" maxlength="180" value="<?= h($selected['task_title']) ?>"></div>
              <div class="task-field"><label for="editDescription">Description</label><textarea id="editDescription" name="description" maxlength="500" rows="3"><?= h((string)$selected['description']) ?></textarea></div>
              <div class="task-field"><label for="editEmployee">Assigned Employee</label><select id="editEmployee" name="assigned_to" required>
                <?php foreach($employees as $employee): ?><option value="<?= (int)$employee['user_id'] ?>" <?= (int)$selected['assigned_to']===(int)$employee['user_id']?'selected':'' ?>><?= h($employee['full_name']) ?> (<?= (int)$employee['open_tasks'] ?> open tasks)</option><?php endforeach; ?></select></div>
              <div class="task-field"><label for="editPriority">Priority</label><select id="editPriority" name="priority"><?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $key=>$label): ?><option value="<?= $key ?>" <?= $selected['priority']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
              <div class="task-grid-2">
                <div class="task-field"><label for="editStart">Start Date</label><input id="editStart" name="start_date" type="date" required value="<?= h($selected['start_date']) ?>"></div>
                <div class="task-field"><label for="editDue">Due Date</label><input id="editDue" name="due_date" type="date" required value="<?= h($selected['due_date']) ?>"></div>
              </div>
              <button class="task-btn primary" type="submit">Save Changes</button>
            </form>
          </details>
        <?php endif; ?>
        <h4 class="task-subheading">Workflow History</h4>
        <div class="task-timeline">
          <?php if (!$history): ?><p>No status changes recorded.</p>
          <?php else: foreach($history as $row): ?><p><strong><?= h($row['previous_status']??'New') ?> → <?= h($row['new_status']) ?></strong> · <?= h($row['progress_note']??'') ?><small><?= h($row['changed_at'].' — '.($row['changed_by_name']??'System')) ?></small></p><?php endforeach; endif; ?>
        </div>
      </section>
      <?php endif; ?>
    

<!-- Create Task modal: hidden until the "Create Task" button is clicked. Uses the shared .employee-modal styles. -->
<div class="employee-modal" id="taskModal" role="dialog" aria-modal="true" aria-labelledby="newTaskTitle" data-autoopen="<?= $openCreateModal ? '1' : '0' ?>" hidden>
  <div class="employee-modal-backdrop" data-close-task-modal></div>
  <div class="employee-modal-dialog">
    <div class="modal-heading">
      <div>
        <p class="eyebrow">DAILY OPERATIONS / TASKS</p>
        <h2 id="newTaskTitle">Create New Task</h2>
        <p>Assign and schedule client work</p>
      </div>
      <button class="modal-close" type="button" aria-label="Close" data-close-task-modal><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="task-modal-body">
      <?php if ($openCreateModal && $errors): ?><div class="task-alert error" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if (!$requests): ?><p class="task-hint">No pending or in-progress service requests found. Create a service request before adding a task.</p><?php endif; ?>
      <?php if (!$employees): ?><p class="task-hint">No active employees found. Add or reactivate an employee first.</p><?php endif; ?>
      <form method="post" action="tasks.php" id="createTaskForm" class="task-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="create">
        <fieldset><legend><i class="bi bi-briefcase" aria-hidden="true"></i> Section A — Task Information</legend>
          <div class="task-field"><label for="serviceRequest">Linked Service Request <em>*</em></label>
            <select id="serviceRequest" name="service_request_id" required>
              <option value="">Select a client service request</option>
              <?php foreach($requests as $request): ?>
                <option value="<?= (int)$request['service_request_id'] ?>" <?= (string)$request['service_request_id']===$old['service_request_id']?'selected':'' ?> data-request-due="<?= h($request['due_date']) ?>">
                  <?= h('#'.$request['service_request_id'].' — '.$request['client_name'].' — '.$request['service_name'].' (due '.$request['due_date'].')') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <small>Select the client work request this task belongs to.</small>
          </div>
          <div class="task-field"><label for="taskTitle">Task Title <em>*</em></label><input id="taskTitle" name="task_title" type="text" required minlength="3" maxlength="180" placeholder="e.g. Collect supporting VAT documents" value="<?= h($old['task_title']) ?>"></div>
          <div class="task-field"><label for="taskDescription">Task Description <small>(optional, up to 500 characters)</small></label><textarea id="taskDescription" name="description" maxlength="500" rows="3" placeholder="Describe the work..."><?= h($old['description']) ?></textarea></div>
        </fieldset>
        <fieldset><legend><i class="bi bi-person-check" aria-hidden="true"></i> Section B — Assignment</legend>
          <div class="task-field"><label for="assignEmployee">Assign Employee <em>*</em></label>
            <select id="assignEmployee" name="assigned_to" required>
              <option value="">Select an employee</option>
              <?php foreach($employees as $employee): ?>
                <option value="<?= (int)$employee['user_id'] ?>" <?= (string)$employee['user_id']===$old['assigned_to']?'selected':'' ?>><?= h($employee['full_name']) ?> (<?= (int)$employee['open_tasks'] ?> open tasks)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="task-field"><span class="task-label">Priority</span>
            <div class="task-priority-options">
              <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $key=>$value): ?>
                <label class="task-radio"><input type="radio" name="priority" value="<?= $key ?>" <?= $old['priority']===$key?'checked':'' ?>> <?= $value ?></label>
              <?php endforeach; ?>
            </div>
          </div>
        </fieldset>
        <fieldset><legend><i class="bi bi-calendar-check" aria-hidden="true"></i> Section C — Scheduling</legend>
          <div class="task-grid-2">
            <div class="task-field"><label for="startDate">Start Date <em>*</em></label><input type="date" id="startDate" name="start_date" value="<?= h($old['start_date']) ?>" required></div>
            <div class="task-field"><label for="dueDate">Due Date <em>*</em></label><input type="date" id="dueDate" name="due_date" value="<?= h($old['due_date']) ?>" min="<?= h($today) ?>" required></div>
          </div>
          <div class="task-field"><span class="task-label">Initial Task Status: <span class="task-badge pending">Pending</span></span></div>
        </fieldset>
        <div class="task-actions">
          <button type="button" class="task-btn outline" data-close-task-modal>Cancel</button>
          <button class="task-btn primary" type="submit" <?= (!$requests||!$employees)?'disabled':'' ?>><i class="bi bi-check2" aria-hidden="true"></i> Create Task</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('taskModal');
  if (!modal) return;
  var lastTrigger = null;

  function openModal(trigger) {
    lastTrigger = trigger || null;
    modal.hidden = false;
    document.body.classList.add('employee-modal-open');
    var first = modal.querySelector('select, input:not([type=hidden])');
    if (first) first.focus();
  }
  function closeModal() {
    modal.hidden = true;
    document.body.classList.remove('employee-modal-open');
    if (lastTrigger) lastTrigger.focus();
  }

  document.querySelectorAll('[data-open-task-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () { openModal(btn); });
  });
  modal.querySelectorAll('[data-close-task-modal]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });

  // Keep the due date inside the chosen service request's deadline.
  var request = document.getElementById('serviceRequest');
  var due = document.getElementById('dueDate');
  if (request && due) {
    request.addEventListener('change', function () {
      var max = request.options[request.selectedIndex].getAttribute('data-request-due');
      if (max) due.setAttribute('max', max); else due.removeAttribute('max');
    });
    request.dispatchEvent(new Event('change'));
  }

  // Re-open with the entered values if the server rejected the form.
  if (modal.dataset.autoopen === '1') openModal(null);
})();
</script>
<?php nexShellEnd(); ?>