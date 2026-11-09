<?php
declare(strict_types=1);
require_once __DIR__ . '/nexsus_urgency.php';

/**
 * Small, shared reporting functions for the NEXSUS MySQLi database.
 * Included after authorization.php. All figures are computed from live records.
 */
function nrRows(mysqli $conn, string $sql, array $params = []): array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->get_result();
    $out = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $out;
}
function nrOne(mysqli $conn, string $sql, array $params = []): array {
    return nrRows($conn, $sql, $params)[0] ?? [];
}
function nrTypes(): array {
    return [
        'task_progress' => 'Task Progress Report',
        'employee_workload' => 'Employee Workload Report',
        'service_performance' => 'Service Performance Report',
        'deadline_monitoring' => 'Deadline Monitoring Report',
    ];
}
function nrDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function nrFilters(array $input): array {
    $get = static fn(string $k): string => isset($input[$k]) && is_string($input[$k]) ? trim($input[$k]) : '';
    $type = $get('report_type') ?: 'task_progress';
    if (!array_key_exists($type, nrTypes())) throw new InvalidArgumentException('Choose a valid report type.');
    $from = $get('date_from');
    $to = $get('date_to');
    if (($from !== '' && !nrDate($from)) || ($to !== '' && !nrDate($to))) throw new InvalidArgumentException('Choose valid dates.');
    if ($from !== '' && $to !== '' && $from > $to) throw new InvalidArgumentException('Due From cannot be after Due To.');
    $employee = $get('employee_id');
    $service = $get('service_id');
    if ($employee === '0') $employee = '';
    if ($service === '0') $service = '';
    if ($employee !== '' && (!ctype_digit($employee) || (int)$employee < 1)) throw new InvalidArgumentException('Invalid employee filter.');
    if ($service !== '' && (!ctype_digit($service) || (int)$service < 1)) throw new InvalidArgumentException('Invalid service filter.');
    return ['report_type'=>$type, 'date_from'=>$from,'date_to'=>$to,
            'employee_id'=>$employee === '' ? 0 : (int)$employee,
            'service_id'=>$service === '' ? 0 : (int)$service];
}
function nrTaskData(mysqli $conn, array $f): array {
    $where = ['1=1']; $params = [];
    if ($f['employee_id']) { $where[] = 't.assigned_to=?'; $params[]=$f['employee_id']; }
    if ($f['service_id']) { $where[] = 'sr.service_id=?'; $params[]=$f['service_id']; }
    if ($f['date_from']) { $where[] = 't.due_date>=?'; $params[]=$f['date_from']; }
    if ($f['date_to']) { $where[] = 't.due_date<=?'; $params[]=$f['date_to']; }
    return nrRows($conn, 'SELECT t.task_id,t.task_title,t.status,t.priority,t.start_date,t.due_date,t.completed_at,
       t.assigned_to, COALESCE(u.full_name,\'Unassigned\') employee_name,
       s.service_id,s.service_name,s.category,sr.service_request_id,
       CASE WHEN c.client_type=\'business\' THEN COALESCE(NULLIF(c.business_name,\'\'),\'Unnamed business\')
            ELSE TRIM(CONCAT_WS(\' \',c.first_name,c.last_name)) END client_name
       FROM tasks t JOIN service_requests sr ON sr.service_request_id=t.service_request_id
       JOIN services s ON s.service_id=sr.service_id
       JOIN clients c ON c.client_id=sr.client_id
       LEFT JOIN users u ON u.user_id=t.assigned_to
       WHERE '.implode(' AND ',$where).' ORDER BY t.due_date,t.task_id', $params);
}
function nrRequestData(mysqli $conn, array $f): array {
    $where = ['1=1']; $params = [];
    if ($f['employee_id']) { $where[] = 'sr.assigned_to=?'; $params[]=$f['employee_id']; }
    if ($f['service_id']) { $where[] = 'sr.service_id=?'; $params[]=$f['service_id']; }
    if ($f['date_from']) { $where[] = 'sr.due_date>=?'; $params[]=$f['date_from']; }
    if ($f['date_to']) { $where[] = 'sr.due_date<=?'; $params[]=$f['date_to']; }
    return nrRows($conn, 'SELECT sr.service_request_id,sr.request_status,sr.start_date,sr.due_date,sr.priority,sr.assigned_to,
        COALESCE(u.full_name,\'Unassigned\') employee_name,s.service_id,s.service_name,s.category,
        CASE WHEN c.client_type=\'business\' THEN COALESCE(NULLIF(c.business_name,\'\'),\'Unnamed business\')
        ELSE TRIM(CONCAT_WS(\' \',c.first_name,c.last_name)) END client_name
        FROM service_requests sr JOIN services s ON s.service_id=sr.service_id
        JOIN clients c ON c.client_id=sr.client_id
        LEFT JOIN users u ON u.user_id=sr.assigned_to
        WHERE '.implode(' AND ',$where).' ORDER BY sr.due_date,sr.service_request_id', $params);
}
function nrTaskBucket(array $t, string $today): string {
    if ($t['status'] === 'completed') return 'completed';
    if ((string)$t['due_date'] < $today) return 'overdue';
    if ($t['status'] === 'in_progress') return 'in_progress';
    return 'pending';
}
function nrRequestBucket(array $r, string $today): string {
    if ($r['request_status'] === 'completed') return 'completed';
    if ($r['request_status'] === 'cancelled') return 'cancelled';
    if ((string)$r['due_date'] < $today) return 'overdue';
    if ($r['request_status'] === 'in_progress') return 'in_progress';
    return 'pending';
}
function nrDeadlineLabel(string $status, string $due, string $today, ?string $start = null): string {
    return nexUrgency($status, $due, $start, $today)['label'];
}
function nrBuild(mysqli $conn, array $f): array {
    $today = date('Y-m-d');
    $tasks = nrTaskData($conn,$f);
    $requests = nrRequestData($conn,$f);
    $summary = ['total'=>count($tasks),'completed'=>0,'in_progress'=>0,'overdue'=>0,'pending'=>0];
    $requestSummary = ['total'=>count($requests),'pending'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0,'overdue'=>0];
    $attention = ['task_overdue'=>0,'task_due_today'=>0,'task_due_soon'=>0,'task_ageing'=>0,
                  'request_overdue'=>0,'request_due_today'=>0,'request_due_soon'=>0,'request_ageing'=>0];
    foreach ($tasks as $t) {
        $key = nexUrgency((string)$t['status'], (string)$t['due_date'], (string)$t['start_date'], $today)['key'];
        if (isset($attention['task_'.$key])) $attention['task_'.$key]++;
    }
    foreach ($requests as $r) {
        $key = nexUrgency((string)$r['request_status'], (string)$r['due_date'], (string)$r['start_date'], $today)['key'];
        if (isset($attention['request_'.$key])) $attention['request_'.$key]++;
        $state = (string)$r['request_status'];
        if (array_key_exists($state, $requestSummary)) $requestSummary[$state]++;
        if (in_array($state, ['pending','in_progress'], true) && (string)$r['due_date'] < $today) $requestSummary['overdue']++;
    }
    $buckets = ['Pending'=>0,'In Progress'=>0,'Completed'=>0,'Overdue'=>0];
    foreach ($tasks as $t) {
        if ($t['status']==='completed') $summary['completed']++;
        elseif ($t['status']==='in_progress') $summary['in_progress']++;
        elseif ($t['status']==='pending') $summary['pending']++;
        if ($t['status']!=='completed' && $t['due_date']<$today) $summary['overdue']++;
        $bucket = nrTaskBucket($t,$today);
        $key = ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue'][$bucket];
        $buckets[$key]++;
    }
    $type = $f['report_type'];
    $columns=[]; $rows=[]; $chartLabels=[]; $chartValues=[];
    if ($type==='task_progress') {
        $columns=['Task ID','Task','Client','Service','Employee','Priority','Status','Due Date','Completed At','Attention Flag'];
        foreach ($tasks as $t) $rows[] = [$t['task_id'],$t['task_title'],$t['client_name'],$t['service_name'],
            $t['employee_name'],ucfirst((string)$t['priority']),ucwords(str_replace('_',' ',$t['status'])),
            $t['due_date'],$t['completed_at']??'',nrDeadlineLabel($t['status'],$t['due_date'],$today,$t['start_date'])];
        $chartLabels=array_keys($buckets); $chartValues=array_values($buckets);
    } elseif ($type==='employee_workload') {
        $workers = nrRows($conn,"SELECT user_id,full_name,account_status FROM users WHERE role='employee'".
            ($f['employee_id']?' AND user_id=?':'').' ORDER BY full_name',
            $f['employee_id']?[$f['employee_id']]:[]);
        $stats=[];
        foreach ($workers as $u) $stats[(int)$u['user_id']]=['name'=>$u['full_name'],'assigned'=>0,'open'=>0,'completed'=>0,'overdue'=>0];
        foreach ($tasks as $t) {
            $id=(int)$t['assigned_to'];
            if (!isset($stats[$id])) continue;
            $stats[$id]['assigned']++;
            if ($t['status']==='completed') $stats[$id]['completed']++;
            else { $stats[$id]['open']++; if ($t['due_date']<$today) $stats[$id]['overdue']++; }
        }
        $columns=['Employee','Assigned Tasks','Open Tasks','Completed','Overdue'];
        foreach ($stats as $s) {
            $rows[]=[$s['name'],$s['assigned'],$s['open'],$s['completed'],$s['overdue']];
            $chartLabels[]=$s['name']; $chartValues[]=$s['open'];
        }
    } elseif ($type==='service_performance') {
        $serviceList=nrRows($conn,'SELECT service_id,service_name,category FROM services'.
            ($f['service_id']?' WHERE service_id=?':'').' ORDER BY category,service_name',
            $f['service_id']?[$f['service_id']]:[]);
        $stats=[];
        foreach ($serviceList as $s) $stats[(int)$s['service_id']]=['name'=>$s['service_name'],'category'=>$s['category'],
            'requests'=>0,'pending'=>0,'in_progress'=>0,'completed'=>0,'overdue'=>0,'cancelled'=>0];
        foreach ($requests as $r) {
            $id=(int)$r['service_id']; if(!isset($stats[$id]))continue;
            $stats[$id]['requests']++;
            $state = (string)$r['request_status'];
            if (isset($stats[$id][$state])) $stats[$id][$state]++;
            if (in_array($state, ['pending','in_progress'], true) && (string)$r['due_date'] < $today) $stats[$id]['overdue']++;
        }
        $columns=['Category','Service','Requests','Pending','In Progress','Completed','Overdue','Cancelled'];
        foreach ($stats as $s) {
            $rows[]=[$s['category'],$s['name'],$s['requests'],$s['pending'],$s['in_progress'],$s['completed'],$s['overdue'],$s['cancelled']];
            $chartLabels[]=$s['name']; $chartValues[]=$s['requests'];
        }
    } else { // deadline_monitoring: tasks and requests are separate named entries, not duplicate tasks.
        $columns=['Record Type','ID','Client','Work','Responsible','Due Date','Status','Attention Flag'];
        foreach ($tasks as $t) $rows[]=['Task',$t['task_id'],$t['client_name'],$t['task_title'],$t['employee_name'],$t['due_date'],
            ucwords(str_replace('_',' ',$t['status'])),nrDeadlineLabel($t['status'],$t['due_date'],$today,$t['start_date'])];
        foreach ($requests as $r) $rows[]=['Service Request',$r['service_request_id'],$r['client_name'],$r['service_name'],$r['employee_name'],$r['due_date'],
            ucwords(str_replace('_',' ',$r['request_status'])),nrDeadlineLabel($r['request_status'],$r['due_date'],$today,$r['start_date'])];
        usort($rows,static fn($a,$b)=>strcmp((string)$a[5],(string)$b[5]));
        $flags=[];
        foreach($rows as $r) $flags[$r[7]]=($flags[$r[7]]??0)+1;
        $chartLabels=array_keys($flags); $chartValues=array_values($flags);
    }
    return ['summary'=>$summary,'columns'=>$columns,'rows'=>$rows,'chartLabels'=>$chartLabels,
            'chartValues'=>$chartValues,'taskBuckets'=>$buckets,'attention'=>$attention,'taskCount'=>count($tasks),'requestCount'=>count($requests),'requestSummary'=>$requestSummary];
}
/** Excel/LibreOffice formula injection prevention for exported text. */
function nrCsvSafe(mixed $cell): string {
    $v=(string)($cell??'');
    if (preg_match('/^[\s\x00-\x1F]*[=+@-]/u',$v)) return "'".$v;
    return $v;
}
function nrAuditExport(mysqli $conn, int $ownerId,string $format, array $f): void {
    $reportId=0;
    // The optional report_exports table is an export history, not a saved CSV file.
    $check = nrOne($conn,"SELECT COUNT(*) AS n FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_exports'
         AND COLUMN_NAME IN ('report_id','report_type','generated_by','date_from','date_to','file_path','output_format')");
    if ((int)($check['n']??0)===7) {
        $stmt=$conn->prepare('INSERT INTO report_exports (report_type,generated_by,date_from,date_to,file_path,output_format)
                             VALUES (?,?,?,?,NULL,?)');
        $from=$f['date_from']?:null; $to=$f['date_to']?:null;
        $stmt->execute([$f['report_type'],$ownerId,$from,$to,$format]);
        $reportId=(int)$conn->insert_id; $stmt->close();
    }
    $stmt=$conn->prepare('INSERT INTO audit_logs (actor_user_id,action_key,module_name,record_id,details)
                          VALUES (?,?,?,?,?)');
    $details='Owner requested '.strtoupper($format).' for '.$f['report_type'].' (due '.($f['date_from']?:'any').
       ' to '.($f['date_to']?:'any').'; employee '.($f['employee_id']?:'all').'; service '.($f['service_id']?:'all').')';
    $stmt->execute([$ownerId,'report.'.$format,'reports',$reportId,$details]);
    $stmt->close();
}
