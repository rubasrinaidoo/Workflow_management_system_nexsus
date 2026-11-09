<?php
declare(strict_types=1);

function taskRows(mysqli $conn, string $sql, array $values = []): array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function taskOne(mysqli $conn, string $sql, array $values = []): ?array {
    return taskRows($conn, $sql, $values)[0] ?? null;
}
function taskWrite(mysqli $conn, string $sql, array $values = []): int {
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}
function taskColumnsExist(mysqli $conn, string $table, array $required): bool {
    $rows = taskRows($conn, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
    return count(array_diff($required, array_column($rows, 'COLUMN_NAME'))) === 0;
}
function taskSetupError(mysqli $conn): ?string {
    $required = [
        'users' => ['user_id','full_name','role','account_status'],
        'clients' => ['client_id','client_type','first_name','last_name','business_name'],
        'services' => ['service_id','service_name'],
        'service_requests' => ['service_request_id','client_id','service_id','due_date','request_status'],
        'tasks' => ['task_id','service_request_id','task_title','description','assigned_to','created_by','start_date','due_date','priority','status','progress_notes','completed_at','created_at'],
        'workflow_history' => ['entity_type','entity_id','previous_status','new_status','progress_note','changed_by','changed_at'],
        'notifications' => ['user_id','notification_type','title','message','reference_type','reference_id','read_at','created_at'],
        'audit_logs' => ['actor_user_id','action_key','module_name','record_id','details']
    ];
    foreach ($required as $table => $columns) {
        if (!taskColumnsExist($conn, $table, $columns)) {
            return "Database setup incomplete: check the $table table and run 01_TASK_SETUP.sql (see README).";
        }
    }
    return null;
}
function taskTextLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
function taskValidDate(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}
function taskLog(mysqli $conn, int $actorId, string $action, int $taskId, string $details): void {
    taskWrite($conn, "INSERT INTO audit_logs (actor_user_id,action_key,module_name,record_id,details) VALUES (?,?,'tasks',?,?)",
        [$actorId,$action,$taskId,$details]);
}
function taskHistory(mysqli $conn, int $taskId, ?string $before, string $after, string $note, int $actorId): void {
    taskWrite($conn, "INSERT INTO workflow_history (entity_type,entity_id,previous_status,new_status,progress_note,changed_by) VALUES ('task',?,?,?,?,?)",
        [$taskId,$before,$after,$note,$actorId]);
}
function taskNotify(mysqli $conn, int $userId, string $type, string $title, string $message, int $taskId): void {
    taskWrite($conn, "INSERT INTO notifications (user_id,notification_type,title,message,reference_type,reference_id) VALUES (?,?,?,?,'task',?)",
        [$userId,$type,$title,$message,$taskId]);
}
function taskRedirect(string $path): never { redirect_to($path); }
function taskSafe(string $value): string { return h($value); }
