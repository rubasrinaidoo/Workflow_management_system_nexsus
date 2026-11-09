<?php
declare(strict_types=1);
require_once __DIR__ . '/nexsus_modules.php';
require_once __DIR__ . '/nexsus_shell.php';

$user = require_logged_in(); // Owner AND Employee; no OTP.
$isOwner = $user['role'] === 'owner';
$actor = (int)$user['user_id'];
$csrf = csrf_token();
$today = date('Y-m-d');
$categories = nexCategories();
$statuses = nexStatuses();
$required = [
    'service_requests'=>['service_request_id','client_id','service_id','request_title','description',
        'request_status','start_date','due_date','created_by','assigned_to','priority'],
    'services'=>['service_id','service_name','category'],
    'clients'=>['client_id'],
    'users'=>['user_id','full_name','role','account_status'],
];
foreach ($required as $table=>$cols) {
    $missing = array_diff($cols,nexColumns($conn,$table));
    if ($missing) { http_response_code(503); exit('Service Requests needs database field(s): '.h($table.'.'.implode(', '.$table.'.',$missing)).'. Follow 00_INSPECT and 01_ADD_FIELDS_IF_MISSING SQL; never drop existing tables.'); }
}
$statusCol = nexServiceStatusColumn($conn);
$clientNameExpr = nexClientLabelExpression($conn);
$clientStatusExpr = nexClientStatusExpression($conn);
$taskCols = nexColumns($conn,'tasks');
$tasksReady = count(array_diff(['task_id','service_request_id','task_title','assigned_to','status','due_date'],$taskCols))===0;
$historyReady = nexHistoryReady($conn);
$auditReady = nexAuditReady($conn);
$tasksCountSql = $tasksReady ? '(SELECT COUNT(*) FROM tasks t WHERE t.service_request_id=sr.service_request_id)' : '0';

$clients = nexRows($conn,"SELECT c.client_id,$clientNameExpr AS client_label,$clientStatusExpr AS record_status FROM clients c ORDER BY client_label");
$services = nexRows($conn,"SELECT service_id,service_name,category,`$statusCol` AS record_status FROM services ORDER BY service_name");
$employees = nexRows($conn,"SELECT user_id,full_name,account_status FROM users WHERE role='employee' ORDER BY full_name");
$clientMap = array_column($clients,null,'client_id');
$serviceMap = array_column($services,null,'service_id');
$employeeMap = array_column($employees,null,'user_id');
$errors=[];
$mode='';
$editingId=0;
$form=['client_id'=>'','category'=>'','service_id'=>'','assigned_to'=>'','due_date'=>'','priority'=>'medium'];
$selected=null;

function nexRequestAccessible(mysqli $conn, int $id, int $actor, bool $owner, bool $tasksReady): ?array {
    $condition = $owner ? '' : ($tasksReady ?
        ' AND (sr.assigned_to=? OR EXISTS(SELECT 1 FROM tasks t WHERE t.service_request_id=sr.service_request_id AND t.assigned_to=?))' :
        ' AND sr.assigned_to=?');
    $params = [$id];
    if (!$owner) { $params[]=$actor; if ($tasksReady) $params[]=$actor; }
    return nexOne($conn,'SELECT sr.* FROM service_requests sr WHERE sr.service_request_id=?'.$condition.' LIMIT 1',$params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Reload the page and try again.'); }
    $action=nexText('action');
    $requestId=(int)(filter_var($_POST['service_request_id'] ?? null,FILTER_VALIDATE_INT) ?: 0);
    $started=false;
    try {
        if (in_array($action,['create','update'],true)) {
            if (!$isOwner) { http_response_code(403); exit('Only the Owner can create or assign service requests.'); }
            $mode='form';
            $editingId=$action==='update'?$requestId:0;
            $form=[
                'client_id'=>nexText('client_id'), 'category'=>strtolower(nexText('category')),
                'service_id'=>nexText('service_id'),'assigned_to'=>nexText('assigned_to'),
                'due_date'=>nexText('due_date'),'priority'=>nexText('priority'),
            ];
            $clientId=(int)(filter_var($form['client_id'],FILTER_VALIDATE_INT) ?: 0);
            $serviceId=(int)(filter_var($form['service_id'],FILTER_VALIDATE_INT) ?: 0);
            $employeeId=(int)(filter_var($form['assigned_to'],FILTER_VALIDATE_INT) ?: 0);
            if (!isset($clientMap[$clientId]) || ($action==='create' && $clientMap[$clientId]['record_status']!=='active')) $errors[]='Choose an active client.';
            if (!isset($categories[$form['category']])) $errors[]='Select Accounting, Taxation or Payroll.';
            if (!isset($serviceMap[$serviceId]) || ($action==='create' && $serviceMap[$serviceId]['record_status']!=='active')) $errors[]='Choose an active service.';
            elseif (strtolower(trim((string)$serviceMap[$serviceId]['category']))!==$form['category']) $errors[]='Selected service does not belong to the chosen category.';
            if (!isset($employeeMap[$employeeId]) || $employeeMap[$employeeId]['account_status']!=='active') $errors[]='Choose an active employee to lead the request.';
            if (!in_array($form['priority'],['low','medium','high'],true)) $errors[]='Select Low, Medium or High priority.';
            if (!nexDate($form['due_date'])) $errors[]='Enter a valid due date.';
            elseif ($action==='create' && $form['due_date']<$today) $errors[]='Due date cannot be in the past.';
            if ($action==='update' && !$editingId) $errors[]='Choose a valid request to edit.';
            // Prevent accidental second submissions for the same work and deadline.
            // The same client may legitimately request the service again on a different date.
            if ($action==='create' && !$errors) {
                $duplicate = nexOne($conn,
                    "SELECT service_request_id FROM service_requests
                     WHERE client_id=? AND service_id=? AND due_date=?
                       AND request_status IN ('pending','in_progress') LIMIT 1",
                    [$clientId,$serviceId,$form['due_date']]);
                if ($duplicate) {
                    $errors[]='A request for this client, service and due date already exists (#'
                        .(int)$duplicate['service_request_id'].'). View or edit that request instead.';
                }
            }
            if (!$errors) {
                $conn->begin_transaction(); $started=true;
                $old=$action==='update'?nexRequestAccessible($conn,$editingId,$actor,true,$tasksReady):null;
                if ($action==='update' && !$old) $errors[]='Service request no longer exists.';
                if ($old && in_array($old['request_status'],['completed','cancelled'],true)) $errors[]='Closed requests cannot be edited.';
                if ($old && $tasksReady) {
                    $taskInfo=nexOne($conn,'SELECT COUNT(*) AS total,MAX(due_date) AS latest_due FROM tasks WHERE service_request_id=?',[$editingId]);
                    if ((int)$taskInfo['total']>0 && ((int)$old['client_id']!==$clientId || (int)$old['service_id']!==$serviceId))
                        $errors[]='This request already has tasks. Its client and service cannot be changed.';
                    if ($taskInfo['latest_due'] && $form['due_date']<$taskInfo['latest_due'])
                        $errors[]='Request deadline cannot be earlier than a linked task deadline.';
                }
                if (!$errors) {
                    if ($action==='create') {
                        // Title comes from chosen service; no redundant title field in the form.
                        $title=(string)$serviceMap[$serviceId]['service_name'];
                        $title=function_exists('mb_substr')?mb_substr($title,0,180,'UTF-8'):substr($title,0,180);
                        nexWrite($conn,"INSERT INTO service_requests (client_id,service_id,request_title,description,request_status,start_date,due_date,created_by,assigned_to,priority)
                            VALUES (?,?,?,NULL,'pending',?,?,?,?,?)",
                            [$clientId,$serviceId,$title,$today,$form['due_date'],$actor,$employeeId,$form['priority']]);
                        $saved=(int)$conn->insert_id;
                        nexHistory($conn,$historyReady,$saved,null,'pending','Request created and assigned by Owner.',$actor);
                        nexAudit($conn,$auditReady,$actor,'service_request.created','service_requests',$saved,'Created client request and assigned lead employee.');
                    } else {
                        $saved=$editingId;
                        $title=(string)$serviceMap[$serviceId]['service_name'];
                        $title=function_exists('mb_substr')?mb_substr($title,0,180,'UTF-8'):substr($title,0,180);
                        nexWrite($conn,'UPDATE service_requests SET client_id=?,service_id=?,request_title=?,assigned_to=?,priority=?,due_date=? WHERE service_request_id=?',
                            [$clientId,$serviceId,$title,$employeeId,$form['priority'],$form['due_date'],$saved]);
                        nexHistory($conn,$historyReady,$saved,(string)$old['request_status'],(string)$old['request_status'],'Owner updated assignment, priority or deadline.',$actor);
                        nexAudit($conn,$auditReady,$actor,'service_request.updated','service_requests',$saved,'Owner edited request details.');
                    }
                    $conn->commit(); $started=false;
                    flash('success',$action==='create'?'Service request created and assigned.':'Service request updated.');
                    nexRedirect('serviceRequests.php?view='.$saved.'#requestDetails');
                }
                $conn->rollback(); $started=false;
            }
        } 
            $next=nexText('request_status');
            $note=nexText('progress_note');
            if (!$requestId) $errors[]='Choose a request.';
            if (!isset($statuses[$next])) $errors[]='Choose a valid status.';
            if (nexLen($note)>500) $errors[]='Progress note must be at most 500 characters.';
            if (!$errors) {
                $conn->begin_transaction(); $started=true;
                $old=nexRequestAccessible($conn,$requestId,$actor,$isOwner,$tasksReady);
                if (!$old) $errors[]='This request is unavailable or not assigned to you.';
                elseif (!$isOwner && (int)$old['assigned_to']!==$actor) $errors[]='Only the responsible employee or Owner can update the overall request status.';
                elseif (in_array($old['request_status'],['completed','cancelled'],true)) $errors[]='Closed requests cannot be reopened.';
                elseif ($next==='cancelled' && !$isOwner) $errors[]='Only the Owner can cancel a service request.';
                elseif ($next!==$old['request_status'] && !(
                    ($old['request_status']==='pending' && $next==='in_progress') ||
                    ($old['request_status']==='in_progress' && $next==='completed') ||
                    ($isOwner && $next==='cancelled')
                )) $errors[]='Move a request from Pending to In Progress, then Completed.';
                if (!$errors && in_array($next,['completed','cancelled'],true) && $tasksReady) {
                    $open=nexOne($conn,"SELECT COUNT(*) AS n FROM tasks WHERE service_request_id=? AND status<>'completed'",[$requestId]);
                    if ((int)$open['n']>0) $errors[]='Resolve all linked tasks before closing the service request.';
                }
                if (!$errors) {
                    nexWrite($conn,'UPDATE service_requests SET request_status=? WHERE service_request_id=?',[$next,$requestId]);
                    nexHistory($conn,$historyReady,$requestId,(string)$old['request_status'],$next,
                        $note!==''?$note:'Status updated by '.($isOwner?'Owner':'responsible employee').'.',$actor);
                    nexAudit($conn,$auditReady,$actor,'service_request.status.'.$next,'service_requests',$requestId,'Status changed by authenticated user.');
                    $conn->commit(); $started=false;
                    flash('success','Request progress updated.');
                    nexRedirect('serviceRequests.php?view='.$requestId.'#requestDetails');
                }
                $conn->rollback(); $started=false;
            
        } else $errors[]='Unknown action.';
    } catch (Throwable $e) {
        if ($started) $conn->rollback();
        error_log('NEXSUS service request save: '.$e->getMessage());
        $errors[]='Could not save this request. Verify your database constraints and check the Apache error log.';
    }
}

// Filtered list. Employees see only requests assigned directly to them or through their tasks.
$search=trim(is_string($_GET['q'] ?? null)?$_GET['q']:'');
$search=substr($search,0,90);
$filter=is_string($_GET['status'] ?? null)?$_GET['status']:'all';
if (!in_array($filter,['all','pending','in_progress','overdue','completed','cancelled'],true)) $filter='all';
$filterCategory=strtolower(is_string($_GET['category'] ?? null)?$_GET['category']:'all');
if ($filterCategory!=='all' && !isset($categories[$filterCategory])) $filterCategory='all';
$visibleSql=$isOwner?'1=1':($tasksReady?
    '(sr.assigned_to=? OR EXISTS(SELECT 1 FROM tasks own_task WHERE own_task.service_request_id=sr.service_request_id AND own_task.assigned_to=?))':
    'sr.assigned_to=?');
$visibleArgs=$isOwner?[]:($tasksReady?[$actor,$actor]:[$actor]);
$where=[$visibleSql]; $params=$visibleArgs;
if ($filter==='all') $where[] = "sr.request_status<>'cancelled'";
if ($search!=='') {
    $where[]="(s.service_name LIKE ? OR $clientNameExpr LIKE ? OR u.full_name LIKE ?)";
    array_push($params,'%'.$search.'%','%'.$search.'%','%'.$search.'%');
}
if ($filterCategory!=='all') { $where[]='LOWER(TRIM(s.category))=?'; $params[]=$filterCategory; }
if ($filter==='overdue') $where[]="sr.due_date<CURDATE() AND sr.request_status IN ('pending','in_progress')";
elseif ($filter!=='all') { $where[]='sr.request_status=?'; $params[]=$filter; }
$base=" FROM service_requests sr JOIN services s ON s.service_id=sr.service_id JOIN clients c ON c.client_id=sr.client_id LEFT JOIN users u ON u.user_id=sr.assigned_to";
$fromWhere=$base.' WHERE '.implode(' AND ',array_map(static fn($x)=>'('.$x.')',$where));
$requests=nexRows($conn,"SELECT sr.service_request_id,sr.request_title,sr.request_status,sr.priority,sr.due_date,sr.assigned_to,
    s.service_name,s.category,$clientNameExpr AS client_label,u.full_name AS employee_name,$tasksCountSql AS task_count"
    .$fromWhere.' ORDER BY sr.due_date ASC,sr.service_request_id DESC LIMIT 300',$params);
$counts=nexOne($conn,"SELECT COUNT(*)-COALESCE(SUM(sr.request_status='cancelled'),0) AS total,
    COALESCE(SUM(sr.request_status IN ('pending','in_progress')),0) AS open_count,
    COALESCE(SUM(sr.request_status IN ('pending','in_progress') AND sr.due_date<CURDATE()),0) AS overdue_count,
    COALESCE(SUM(sr.request_status='completed'),0) AS completed_count
    FROM service_requests sr WHERE $visibleSql",$visibleArgs);

$viewId=(int)(filter_var($_GET['view'] ?? null,FILTER_VALIDATE_INT) ?: 0);
if (!$viewId && $_SERVER['REQUEST_METHOD']==='POST') $viewId=(int)(filter_var($_POST['service_request_id'] ?? null,FILTER_VALIDATE_INT) ?: 0);
if ($viewId) {
    $access=nexRequestAccessible($conn,$viewId,$actor,$isOwner,$tasksReady);
    if ($access) {
        $selected=nexOne($conn,"SELECT sr.*,s.service_name,s.category,$clientNameExpr AS client_label,u.full_name AS employee_name
            $base WHERE sr.service_request_id=?",[$viewId]);
    }
    if (!$selected && !$errors) $errors[]='That service request is unavailable.';
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $isOwner) {
    $editId=(int)(filter_var($_GET['edit'] ?? null,FILTER_VALIDATE_INT) ?: 0);
    if ($editId) {
        $item=nexRequestAccessible($conn,$editId,$actor,true,$tasksReady);
        if ($item && !in_array($item['request_status'],['completed','cancelled'],true)) {
            $editingId=$editId; $mode='form';
            $form=['client_id'=>(string)$item['client_id'],'service_id'=>(string)$item['service_id'],
                'category'=>strtolower((string)($serviceMap[$item['service_id']]['category'] ?? '')),
                'assigned_to'=>(string)($item['assigned_to'] ?? ''),'due_date'=>(string)$item['due_date'],
                'priority'=>(string)$item['priority']];
        } else $errors[]='Closed or missing requests cannot be edited.';
    } elseif (($_GET['mode'] ?? '') === 'add') {
        $mode = 'form';
        // When opened from a Client Profile, preselect that client only if active.
        $prefillId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT);
        if ($prefillId
            && isset($clientMap[(int)$prefillId])
            && $clientMap[(int)$prefillId]['record_status'] === 'active') {
            $form['client_id'] = (string)$prefillId;
        }
    }
}
$taskList=[]; $timeline=[];
if ($selected) {
    $id=(int)$selected['service_request_id'];
    if ($tasksReady) {
        $taskList=nexRows($conn,"SELECT t.task_id,t.task_title,t.status,t.due_date,u.full_name AS employee_name
            FROM tasks t LEFT JOIN users u ON u.user_id=t.assigned_to
            WHERE t.service_request_id=? ORDER BY t.due_date,t.task_id LIMIT 30",[$id]);
    }
    if ($historyReady) {
        $requestHistory=nexRows($conn,"SELECT h.changed_at,h.previous_status,h.new_status,h.progress_note,u.full_name AS actor
          FROM workflow_history h LEFT JOIN users u ON u.user_id=h.changed_by
          WHERE h.entity_type='service_request' AND h.entity_id=? ORDER BY h.changed_at DESC LIMIT 50",[$id]);
        foreach ($requestHistory as $event) { $event['source']='Service Request'; $timeline[]=$event; }
        if ($tasksReady) {
            $taskHistory=nexRows($conn,"SELECT h.changed_at,h.previous_status,h.new_status,h.progress_note,u.full_name AS actor,t.task_title
                FROM workflow_history h JOIN tasks t ON t.task_id=h.entity_id
                LEFT JOIN users u ON u.user_id=h.changed_by
                WHERE h.entity_type='task' AND t.service_request_id=? ORDER BY h.changed_at DESC LIMIT 60",[$id]);
            foreach ($taskHistory as $event) { $event['source']='Task: '.(string)$event['task_title']; $timeline[]=$event; }
        }
        usort($timeline,static fn($a,$b)=>strcmp((string)$b['changed_at'],(string)$a['changed_at']));
        $timeline=array_slice($timeline,0,50);
    }
}
$flash=get_flash();
nexShellStart($user,'Service Requests','serviceRequests.php',$csrf);
?>
<section class="nex-heading"><div><p class="nex-eyebrow">DAILY OPERATIONS / CLIENT WORK</p><h2>Service Request Management</h2>
  <p>Track client work, responsible employees, deadlines and task progress.</p></div>
  <?php if ($isOwner): ?><a class="nex-btn nex-btn-primary" href="serviceRequests.php?mode=add" data-open-request-dialog><i class="bi bi-plus-lg" aria-hidden="true"></i> New Request</a><?php endif; ?>
</section>
<?php if ($flash): ?><div class="nex-notice nex-notice-ok" role="status"><?= h((string)$flash['message']) ?></div><?php endif; ?>
<?php if ($errors && $mode !== 'form'): ?><div class="nex-notice nex-notice-error" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $e):?><li><?= h((string)$e) ?></li><?php endforeach;?></ul></div><?php endif; ?>
<section class="nex-metrics" aria-label="Request totals">
  <div class="nex-metric"><span>Total Requests</span><strong><?= (int)$counts['total'] ?></strong></div>
  <div class="nex-metric"><span>Open</span><strong><?= (int)$counts['open_count'] ?></strong></div>
  <div class="nex-metric"><span>Overdue</span><strong><?= (int)$counts['overdue_count'] ?></strong></div>
  <div class="nex-metric"><span>Completed</span><strong><?= (int)$counts['completed_count'] ?></strong></div>
</section>
<?php if ($isOwner): ?>
<dialog id="requestModal" class="nex-request-dialog" aria-labelledby="requestModalTitle" aria-describedby="requestModalDescription" data-autoopen="<?= $mode==='form'?'1':'0' ?>" data-editing="<?= $editingId?'1':'0' ?>">
  <div class="modal-heading">
    <div><p class="nex-eyebrow">CLIENT OPERATIONS / SERVICE REQUESTS</p><h2 id="requestModalTitle"><?= $editingId?'Edit Service Request':'New Service Request' ?></h2><p id="requestModalDescription">Choose the client, service and responsible employee.</p></div>
    <button class="modal-close" type="button" data-close-request-dialog aria-label="Close service request form"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <form method="post" action="serviceRequests.php" id="nexRequestForm" class="nex-form nex-request-form">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="<?= $editingId?'update':'create' ?>">
    <?php if ($editingId): ?><input type="hidden" name="service_request_id" value="<?= $editingId ?>"><?php endif; ?>
    <div class="nex-modal-body">
    <?php if ($errors && $mode === 'form'): ?><div class="nex-notice nex-notice-error" role="alert"><strong>Please correct:</strong><ul><?php foreach($errors as $e):?><li><?= h((string)$e) ?></li><?php endforeach;?></ul></div><?php endif; ?>
    <fieldset class="nex-fieldset"><legend><span class="nex-step">1</span> Client information</legend>
      <div class="nex-form-grid"><label class="nex-field"><span>Existing client <em>*</em></span>
          <select name="client_id" required data-summary-input><option value="">Select a client</option>
            <?php foreach($clients as $c): if($c['record_status']!=='active' && (string)$c['client_id']!==$form['client_id']) continue; ?>
              <option value="<?= (int)$c['client_id'] ?>" <?= $form['client_id']===(string)$c['client_id']?'selected':'' ?>><?= h((string)$c['client_label']) ?></option>
            <?php endforeach; ?>
          </select></label></div>
    </fieldset>
    <fieldset class="nex-fieldset"><legend><span class="nex-step">2</span> Requested service</legend>
      <div class="nex-form-grid">
        <label class="nex-field"><span>Service category <em>*</em></span><select name="category" required data-nex-category data-summary-input>
            <option value="">Select category</option>
            <?php foreach($categories as $key=>$val): ?><option value="<?= h($key) ?>" <?= $form['category']===$key?'selected':'' ?>><?= h($val) ?></option><?php endforeach; ?>
          </select></label>
        <label class="nex-field"><span>Specific service <em>*</em></span><select name="service_id" required data-nex-service data-summary-input>
            <option value="">Select a service</option>
            <?php foreach($services as $s): if($s['record_status']!=='active' && (string)$s['service_id']!==$form['service_id']) continue; $cat=strtolower(trim((string)$s['category'])); if (!isset($categories[$cat])) continue; ?>
              <option value="<?= (int)$s['service_id'] ?>" data-category="<?= h($cat) ?>" <?= $form['service_id']===(string)$s['service_id']?'selected':'' ?>><?= h((string)$s['service_name']) ?></option>
            <?php endforeach; ?>
          </select><small>Services shown depend on the selected category.</small></label>
      </div>
    </fieldset>
    <fieldset class="nex-fieldset"><legend><span class="nex-step">3</span> Assignment and deadline</legend>
      <div class="nex-form-grid">
        <label class="nex-field"><span>Responsible employee <em>*</em></span><select name="assigned_to" required data-summary-input><option value="">Select employee</option>
          <?php foreach($employees as $emp): if($emp['account_status']!=='active' && (string)$emp['user_id']!==$form['assigned_to']) continue; ?>
            <option value="<?= (int)$emp['user_id'] ?>" <?= $form['assigned_to']===(string)$emp['user_id']?'selected':'' ?>><?= h((string)$emp['full_name']) ?></option>
          <?php endforeach; ?></select></label>
        <label class="nex-field"><span>Due date <em>*</em></span><input name="due_date" type="date" min="<?= $editingId?'':h($today) ?>" required value="<?= h($form['due_date']) ?>" data-summary-input><small>Use the calendar to set the client deadline.</small></label>
        <label class="nex-field"><span>Priority <em>*</em></span><select name="priority" required data-summary-input>
          <?php foreach(['low'=>'Low','medium'=>'Medium','high'=>'High'] as $k=>$v): ?><option value="<?= $k ?>" <?= $form['priority']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
        </select></label>
      </div>
    </fieldset>
    <section class="nex-request-summary" aria-labelledby="nexSummaryTitle"><h4 id="nexSummaryTitle"><i class="bi bi-clipboard-check"></i> Request summary</h4>
      <dl><div><dt>Client</dt><dd data-summary-value="client_id">Not selected</dd></div>
        <div><dt>Category</dt><dd data-summary-value="category">Not selected</dd></div>
        <div><dt>Service</dt><dd data-summary-value="service_id">Not selected</dd></div>
        <div><dt>Assigned to</dt><dd data-summary-value="assigned_to">Not selected</dd></div>
        <div><dt>Deadline</dt><dd data-summary-value="due_date">Not selected</dd></div>
        <div><dt>Priority</dt><dd data-summary-value="priority">Medium</dd></div></dl>
    </section>
    </div>
    <div class="nex-modal-actions"><button type="button" class="nex-btn nex-btn-outline" data-close-request-dialog>Cancel</button><button type="submit" class="nex-btn nex-btn-primary"><?= $editingId?'Save Changes':'Create Request' ?></button></div>
  </form>
</dialog>
<?php endif; ?>
<section class="nex-panel"><div class="nex-panel-heading"><div><h3>Client Service Requests</h3><p><?= $isOwner?'All client requests':'Requests assigned to you or linked to your tasks' ?></p></div><span class="nex-muted"><?= count($requests) ?> shown</span></div>
  <nav class="nex-tabs" aria-label="Request status filters">
    <?php foreach (['all'=>'All','pending'=>'Pending','in_progress'=>'In Progress','overdue'=>'Overdue','completed'=>'Completed'] + ($isOwner ? ['cancelled'=>'Cancelled'] : []) as $key=>$label): ?>
      <a href="serviceRequests.php?status=<?= h($key) ?>&amp;q=<?= h(rawurlencode($search)) ?>&amp;category=<?= h(rawurlencode($filterCategory)) ?>" class="<?= $filter===$key?'is-current':'' ?>" <?= $filter===$key?'aria-current="page"':'' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <form class="nex-filters" method="get" action="serviceRequests.php" role="search">
    <input type="hidden" name="status" value="<?= h($filter) ?>">
    <label class="nex-field nex-search"><span>Search</span><input type="search" name="q" maxlength="90" value="<?= h($search) ?>" placeholder="Client, service or employee"></label>
    <label class="nex-field"><span>Category</span><select name="category"><option value="all">All categories</option>
      <?php foreach($categories as $key=>$label): ?><option value="<?= h($key) ?>" <?= $filterCategory===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
    <button class="nex-btn nex-btn-outline" type="submit"><i class="bi bi-funnel"></i> Filter</button><a class="nex-text-link" href="serviceRequests.php">Reset</a>
  </form>
  <div class="nex-table-wrap"><table class="nex-table"><thead><tr><th>Client</th><th>Service</th><th>Assigned employee</th><th>Priority</th><th>Due date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($requests as $row):
    $isLate=in_array($row['request_status'],['pending','in_progress'],true) && (string)$row['due_date']<$today;
    $cat=strtolower(trim((string)$row['category']));
    $label=$categories[$cat]??'Uncategorised';
  ?>
    <tr><td><strong><?= h((string)$row['client_label']) ?></strong><small>#<?= (int)$row['service_request_id'] ?></small></td>
      <td><strong><?= h((string)$row['service_name']) ?></strong><small><?= h($label) ?> · <?= (int)$row['task_count'] ?> tasks</small></td>
      <td><?= h((string)($row['employee_name']??'Unassigned — owner to update')) ?></td>
      <td><span class="nex-priority nex-priority-<?= h((string)$row['priority']) ?>"><?= h(ucfirst((string)$row['priority'])) ?></span></td>
      <td><?= h((string)$row['due_date']) ?><?php if($isLate): ?><small class="nex-late">Overdue</small><?php endif; ?></td>
      <td><span class="nex-pill nex-pill-<?= h((string)$row['request_status']) ?>"><?= h($statuses[$row['request_status']]??(string)$row['request_status']) ?></span></td>
      <td class="nex-actions"><a class="nex-table-link" href="serviceRequests.php?view=<?= (int)$row['service_request_id'] ?>#requestDetails">View</a>
        <?php if($isOwner && in_array($row['request_status'],['pending','in_progress'],true)): ?>
          <a class="nex-table-link" href="serviceRequests.php?edit=<?= (int)$row['service_request_id'] ?>">Edit</a>
        <?php endif; ?>
       
  <?php endforeach; ?>
  <?php if(!$requests): ?><tr><td class="nex-empty" colspan="7">No matching service requests found.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php if($selected):
  $id=(int)$selected['service_request_id'];
  $canChange=$isOwner || ((int)($selected['assigned_to'] ?? 0)===$actor);
  $closed=in_array($selected['request_status'],['completed','cancelled'],true);
?>
<section class="nex-panel nex-detail" id="requestDetails">
  <div class="nex-panel-heading"><div><p class="nex-eyebrow">SERVICE REQUEST #<?= $id ?></p><h3><?= h((string)$selected['client_label']) ?> — <?= h((string)$selected['service_name']) ?></h3></div><a href="serviceRequests.php" class="nex-text-link">Close</a></div>
  <dl class="nex-detail-grid">
    <div><dt>Category</dt><dd><?= h($categories[strtolower(trim((string)$selected['category']))]??'Uncategorised') ?></dd></div>
    <div><dt>Responsible employee</dt><dd><?= h((string)($selected['employee_name']??'Not yet assigned')) ?></dd></div>
    <div><dt>Due date</dt><dd><?= h((string)$selected['due_date']) ?></dd></div>
    <div><dt>Priority</dt><dd><?= h(ucfirst((string)$selected['priority'])) ?></dd></div>
    <div><dt>Status</dt><dd><?= h($statuses[$selected['request_status']]??(string)$selected['request_status']) ?></dd></div>
    <div><dt>Created</dt><dd><?= h((string)$selected['created_at']) ?></dd></div>
  </dl>
  <?php if($canChange && !$closed): ?>
    <form class="nex-status-form" method="post" action="serviceRequests.php?view=<?= $id ?>#requestDetails">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="change_status"><input type="hidden" name="service_request_id" value="<?= $id ?>">
      <label class="nex-field"><span>Update request status</span><select name="request_status">
        <?php if($selected['request_status']==='pending'): ?><option value="pending">Pending</option><option value="in_progress">In Progress</option>
        <?php elseif($selected['request_status']==='in_progress'): ?><option value="in_progress">In Progress</option><option value="completed">Completed</option><?php endif; ?>
        <?php if($isOwner): ?><option value="cancelled">Cancelled</option><?php endif; ?>
      </select></label>
      <label class="nex-field"><span>Progress note (optional)</span><input name="progress_note" maxlength="500" placeholder="What changed or was completed?"></label>
      <button class="nex-btn nex-btn-primary" type="submit">Save Progress</button>
    </form>
  <?php endif; ?>
  <div class="nex-detail-columns">
    <section><div class="nex-section-title"><h4>Related Tasks</h4><?php if($isOwner): ?><a href="tasks.php" class="nex-text-link">Manage Tasks</a><?php endif; ?></div>
      <?php if($taskList): ?><div class="nex-list">
        <?php foreach($taskList as $task): ?><div class="nex-list-item"><div><strong><?= h((string)$task['task_title']) ?></strong><small><?= h((string)($task['employee_name']??'Not assigned')) ?> · Due <?= h((string)$task['due_date']) ?></small></div><span class="nex-pill nex-pill-<?= h((string)$task['status']) ?>"><?= h($statuses[$task['status']]??(string)$task['status']) ?></span></div><?php endforeach; ?>
      </div><?php else: ?><p class="nex-muted">No tasks recorded for this request yet. Individual tasks are added in Task Management.</p><?php endif; ?>
    </section>
    <section><div class="nex-section-title"><h4>Workflow History</h4></div>
      <?php if(!$historyReady): ?><p class="nex-muted">Workflow History table or service_request event type is missing. Check its schema before logging request events.</p>
      <?php elseif(!$timeline): ?><p class="nex-muted">No status changes recorded yet.</p>
      <?php else: ?><ol class="nex-timeline">
        <?php foreach($timeline as $event): ?><li><strong><?= h((string)$event['source']) ?></strong><span><?= h((string)($event['previous_status']??'Created')) ?> → <?= h((string)$event['new_status']) ?></span>
          <?php if(!empty($event['progress_note'])): ?><p><?= h((string)$event['progress_note']) ?></p><?php endif; ?>
          <small><?= h((string)$event['changed_at']) ?> · <?= h((string)($event['actor']??'System')) ?></small></li><?php endforeach; ?></ol><?php endif; ?>
    </section>
  </div>
</section>
<?php endif; ?>
<?php nexShellEnd(); ?>
