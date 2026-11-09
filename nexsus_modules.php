<?php
declare(strict_types=1);

// Shared small helpers for NEXSUS Service Catalogue and Service Requests.
// authorization.php supplies $conn (MySQLi), h(), csrf_token(), require_logged_in(), etc.
require_once __DIR__ . '/authorization.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function nexRows(mysqli $conn, string $sql, array $values = []): array {
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
function nexOne(mysqli $conn, string $sql, array $values = []): ?array {
    return nexRows($conn, $sql, $values)[0] ?? null;
}
function nexWrite(mysqli $conn, string $sql, array $values = []): int {
    $stmt = $conn->prepare($sql);
    $stmt->execute($values);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}
function nexColumns(mysqli $conn, string $table): array {
    // Only use this with hardcoded table names, never user input.
    return array_column(nexRows($conn,
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
        [$table]), 'COLUMN_NAME');
}
function nexHasColumns(mysqli $conn, string $table, array $required): bool {
    return count(array_diff($required, nexColumns($conn, $table))) === 0;
}
function nexText(string $name): string {
    $v = $_POST[$name] ?? '';
    return is_string($v) ? trim($v) : '';
}
function nexLen(string $s): int {
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}
function nexDate(string $s): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}
function nexRedirect(string $path): never { redirect_to($path); }
function nexCategories(): array { return ['accounting'=>'Accounting','taxation'=>'Taxation','payroll'=>'Payroll']; }
function nexStatuses(): array {
    return ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
}
function nexServiceStatusColumn(mysqli $conn): string {
    $cols = nexColumns($conn, 'services');
    if (in_array('status', $cols, true)) return 'status';
    if (in_array('service_status', $cols, true)) return 'service_status';
    http_response_code(503);
    exit('Service catalogue setup: services needs a status or service_status column.');
}
function nexClientLabelExpression(mysqli $conn): string {
    $cols = nexColumns($conn, 'clients');
    if (count(array_intersect(['client_type','business_name','first_name','last_name'], $cols)) === 4) {
        return "CASE WHEN c.client_type='business' THEN COALESCE(NULLIF(TRIM(c.business_name),''),CONCAT('Client #',c.client_id)) ELSE COALESCE(NULLIF(TRIM(CONCAT_WS(' ',c.first_name,c.last_name)),''),CONCAT('Client #',c.client_id)) END";
    }
    if (in_array('client_name', $cols, true) && in_array('business_name', $cols, true)) {
        return "COALESCE(NULLIF(TRIM(c.business_name),''),NULLIF(TRIM(c.client_name),''),CONCAT('Client #',c.client_id))";
    }
    if (in_array('client_name', $cols, true)) return "COALESCE(NULLIF(TRIM(c.client_name),''),CONCAT('Client #',c.client_id))";
    if (in_array('business_name', $cols, true)) return "COALESCE(NULLIF(TRIM(c.business_name),''),CONCAT('Client #',c.client_id))";
    return "CONCAT('Client #',c.client_id)";
}
function nexClientStatusExpression(mysqli $conn): string {
    $cols = nexColumns($conn, 'clients');
    return in_array('status', $cols, true) ? 'c.status' :
        (in_array('client_status', $cols, true) ? 'c.client_status' : "'active'");
}
function nexAudit(mysqli $conn, bool $ready, int $actorId, string $action, string $module, int $id, string $details): void {
    if (!$ready) return;
    nexWrite($conn, 'INSERT INTO audit_logs (actor_user_id,action_key,module_name,record_id,details) VALUES (?,?,?,?,?)',
        [$actorId,$action,$module,$id,$details]);
}
function nexHistory(mysqli $conn, bool $ready, int $requestId, ?string $old, string $new, string $note, int $actorId): void {
    if (!$ready) return;
    nexWrite($conn,
        "INSERT INTO workflow_history (entity_type,entity_id,previous_status,new_status,progress_note,changed_by) VALUES ('service_request',?,?,?,?,?)",
        [$requestId,$old,$new,$note,$actorId]);
}
function nexHistoryReady(mysqli $conn): bool {
    if (!nexHasColumns($conn,'workflow_history',['entity_type','entity_id','previous_status','new_status','progress_note','changed_by','changed_at'])) return false;
    $row = nexOne($conn,"SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_history' AND COLUMN_NAME='entity_type'");
    $type = (string)($row['COLUMN_TYPE'] ?? '');
    return !str_starts_with(strtolower($type),'enum(') || str_contains(strtolower($type),"'service_request'");
}
function nexAuditReady(mysqli $conn): bool {
    return nexHasColumns($conn,'audit_logs',['actor_user_id','action_key','module_name','record_id','details']);
}
