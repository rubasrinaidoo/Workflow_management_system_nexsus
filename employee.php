<?php
declare(strict_types=1);

/**
 * NEXSUS Employee Management 
 * Reuses your real authentication system and MySQLi connection.
 * authorization.php starts the session, loads conn_db.php ($conn),
 * and supplies require_owner(), csrf_token(), csrf_valid() and post_string().
 * Existing Forgot Password and emailed reset link handle all future password resets.
 * Employee Management only creates an initial temporary password.
 * Optional employee photos are stored privately and served through this owner-only page.
 * Unique employee identifiers come from the existing users.user_id primary key.
 */
require_once __DIR__ . '/authorization.php';
require_once __DIR__ . '/nexsus_shell.php';
$owner = require_owner(); // Password-authenticated, active Owner with privacy acknowledgement.
$ownerId = (int)$owner['user_id'];

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Execute a prepared statement and return its rows. PHP 8.1+ / mysqlnd. */
function fetchRows(mysqli $conn, string $sql, array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->execute($params);
    else $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Execute a prepared write with the existing MySQLi connection. */
function execWrite(mysqli $conn, string $sql, array $params = []): void {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $stmt->close();
}

function hasColumns(mysqli $conn, string $table, array $required): bool {
    $rows = fetchRows($conn,
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    );
    $present = array_column($rows, 'COLUMN_NAME');
    return count(array_diff($required, $present)) === 0;
}

function logEmployeeChange(mysqli $conn, bool $enabled, int $ownerId, string $action, int $employeeId): void {
    if (!$enabled) return;
    execWrite($conn,
        "INSERT INTO audit_logs (actor_user_id, action_key, module_name, record_id, details)
         VALUES (?, ?, 'employees', ?, ?)",
        [$ownerId, $action, $employeeId, 'Employee account management action']
    );
}

function revokeEmployeeResets(mysqli $conn, bool $hasResets, int $id): void {
    // Invalidate any password-reset links after an account security change.
    if ($hasResets) execWrite($conn, 'DELETE FROM password_resets WHERE user_id = ?', [$id]);
}

/** Human names are not usernames: allow letters, spaces, hyphens and apostrophes. */
function validEmployeeName(string $name, int $maximum = 75): bool {
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    return $length >= 2 && $length <= $maximum
        && preg_match('/\A\p{L}[\p{L}\p{M}]*(?:[ \'\x{2019}-]\p{L}[\p{L}\p{M}]*)*\z/u', $name) === 1;
}
function checkEmployeeName(string $value, string $label, array &$errors): void {
    if (!validEmployeeName($value)) {
        $errors[] = "$label must contain 2–75 letters; spaces, hyphens and apostrophes are allowed.";
    }
}
function checkEmployeeEmail(string $value, array &$errors): void {
    if (strlen($value) < 5 || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address (maximum 254 characters).';
    }
}
function checkEmployeePhone(string $value, array &$errors): void {
    if (preg_match('/\A0[678][0-9]{8}\z/D', $value) !== 1) {
        $errors[] = 'Contact number is required: enter 10 digits starting with 06, 07 or 08.';
    }
}
function checkAlternativePhone(string $value, string $primary, array &$errors): void {
    if ($value === '') return;  // Alternative number is optional.
    if (preg_match('/\A0[678][0-9]{8}\z/D', $value) !== 1) {
        $errors[] = 'Alternative contact must be a 10-digit SA cellphone number starting 06, 07 or 08.';
    } elseif ($value === $primary) {
        $errors[] = 'Alternative contact must be different from the primary number.';
    }
}
function checkEmployeeUsername(string $value, array &$errors): void {
    if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{7,49}\z/D', $value) !== 1) {
        $errors[] = 'Username must be 8–50 characters, begin with a letter, and use letters, numbers, dots, hyphens or underscores.';
    }
}

function checkEmployeePassword(string $password, string $confirmation, array &$errors): void {
    // Use the shared password policy from authorization.php.
    if (!valid_password($password)) {
        $errors[] = PASSWORD_HINT;
    }

    if ($password !== $confirmation) {
        $errors[] = 'Password confirmation does not match.';
    }
}

function checkEmploymentDate(string $value, array &$errors): void {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $today = new DateTimeImmutable('today');
    if ($value === '' || $date === false || $date->format('Y-m-d') !== $value || $date > $today) {
        $errors[] = 'Date employed is required and cannot be in the future.';
    }
}

/** Sensitive employee SA ID data is separate from users login records. */
function employeePrivateDir(): string {
    // C:\xampp\htdocs\nexsus => C:\xampp\nexsus_private (outside web root).
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'nexsus_private';
}
// Only optional employee photos use the private directory.
// The system does not collect or encrypt national ID numbers.

/** Validate and store one optional employee profile image outside htdocs.
 * Returns null when no file was chosen; retains the old image on edit. */
function saveEmployeePhoto(?array $upload): ?string {
    if ($upload === null || ($upload['error'] ?? null) === UPLOAD_ERR_NO_FILE) return null;
    if (!isset($upload['error'], $upload['tmp_name'], $upload['size']) ||
        !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK ||
        !is_string($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('Photo upload failed. Select a JPEG, PNG or WebP image and try again.');
    }
    $size = (int)$upload['size'];
    if ($size < 1 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be between 1 byte and 2 MB.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $dimensions = @getimagesize($upload['tmp_name']);
    if (!is_string($mime) || !isset($allowed[$mime]) || $dimensions === false ||
        $dimensions[0] < 1 || $dimensions[1] < 1 ||
        $dimensions[0] > 4000 || $dimensions[1] > 4000) {
        throw new RuntimeException('Choose a valid JPEG, PNG or WebP photo up to 4000 × 4000 pixels.');
    }
    $folder = employeePrivateDir() . DIRECTORY_SEPARATOR . 'employee_photos';
    if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
        throw new RuntimeException('Cannot create the private employee photo folder.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $folder . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Could not save the employee profile photo.');
    }
    return 'employee_photos/' . $filename;
}
function removePrivatePhoto(?string $relative): void {
    if ($relative === null || preg_match('~\\Aemployee_photos/[a-f0-9]{32}\\.(jpg|png|webp)\\z~', $relative) !== 1) return;
    $path = employeePrivateDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_file($path)) @unlink($path);
}

function duplicateEmployee(mysqli $conn, string $username, string $email, int $exceptId = 0): array {
    $rows = fetchRows($conn,
        'SELECT username, email FROM users WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)) AND user_id <> ? LIMIT 2',
        [$username, $email, $exceptId]
    );
    $errors = [];
    foreach ($rows as $row) {
        if (strcasecmp((string)$row['username'], $username) === 0) $errors[] = 'This username already exists.';
        if (strcasecmp((string)$row['email'], $email) === 0) $errors[] = 'This email address already exists.';
    }
    return array_values(array_unique($errors));
}
function getEmployee(mysqli $conn, int $id): ?array {
    $rows = fetchRows($conn,
        "SELECT u.user_id, u.full_name, u.username, u.email, u.phone_number,
                u.alternative_phone_number, u.employment_date, u.avatar_path, u.account_status, u.created_at, u.last_login_at, u.privacy_accepted_at
         FROM users u
         WHERE u.user_id = ? AND u.role = 'employee' LIMIT 1", [$id]
    );
    return $rows[0] ?? null;
}
function goEmployee(?int $viewId = null): never {
    $path = 'employee.php';
    if ($viewId !== null) $path .= '?view=' . $viewId . '#employeeDetails';
    redirect_to($path);
}

// Fail explicitly if your users migration has not been run.
if (!hasColumns($conn, 'users', ['phone_number', 'alternative_phone_number', 'employment_date', 'avatar_path'])) {
    http_response_code(503);
    exit('Employee page setup incomplete. Run the SQL migration from README_START_HERE.txt.');
}

// Optional private photo viewer; always requires the authenticated Owner session.
if (isset($_GET['photo'])) {
    $photoId = filter_var($_GET['photo'], FILTER_VALIDATE_INT);
    $photoEmployee = $photoId ? getEmployee($conn, (int)$photoId) : null;
    $relative = $photoEmployee['avatar_path'] ?? null;
    if (!is_string($relative) ||
        preg_match('~\Aemployee_photos/[a-f0-9]{32}\.(jpg|png|webp)\z~', $relative) !== 1) {
        http_response_code(404); exit('Photo not found.');
    }
    $photoFile = employeePrivateDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($photoFile)) { http_response_code(404); exit('Photo not found.'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($photoFile);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(404); exit('Photo not found.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline');
    header('Cache-Control: private, no-store');
    readfile($photoFile);
    exit;
}

$csrf = csrf_token();  // Shared session CSRF protection from authorization.php

// Optional live username availability check. Owner-only, POST-only and CSRF protected.
// Creation and editing STILL check the database when the form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_string('action') === 'check_username') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!csrf_valid()) {
        http_response_code(403);
        echo json_encode(['valid' => false, 'available' => false, 'message' => 'Refresh the page.']);
        exit;
    }
    $usernameCheck = trim(post_string('username'));
    $formatErrors = [];
    checkEmployeeUsername($usernameCheck, $formatErrors);
    if ($formatErrors) {
        echo json_encode(['valid' => false, 'available' => false, 'message' => $formatErrors[0]]);
        exit;
    }
    $exceptId = filter_var($_POST['employee_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    // LOWER also prevents near-duplicate names under a case-sensitive collation.
    $stmt = $conn->prepare('SELECT user_id FROM users WHERE LOWER(username) = LOWER(?) AND user_id <> ? LIMIT 1');
    $stmt->bind_param('si', $usernameCheck, $exceptId);
    $stmt->execute();
    $taken = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode([
        'valid' => true, 'available' => !$taken,
        'message' => $taken ? 'This username is already taken.' : 'This username is available.'
    ]);
    exit;
}
$tasksReady = hasColumns($conn, 'tasks', ['task_id', 'task_title', 'assigned_to', 'status', 'priority', 'due_date']);
$auditReady = hasColumns($conn, 'audit_logs', ['actor_user_id', 'action_key', 'module_name', 'record_id', 'details']);
$resetsReady = hasColumns($conn, 'password_resets', ['user_id']);
$historyReady = $tasksReady && hasColumns($conn, 'workflow_history', ['entity_type', 'entity_id', 'previous_status', 'new_status', 'changed_at']);
$errors = [];
$addOld = ['first_name'=>'', 'last_name'=>'', 'email'=>'', 'phone_number'=>'', 'alternative_phone_number'=>'', 'username'=>'', 'employment_date'=>''];
$openAdd = false;
$flash = $_SESSION['employee_flash'] ?? '';
unset($_SESSION['employee_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        http_response_code(403);
        exit('Invalid security token. Refresh and try again.');
    }
    $action = post_string('action');
    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
    $inTransaction = false;
    $newPhoto = null;
    $oldPhoto = null;
    try {
        if ($action === 'add') {
            $openAdd = true;
            $first = trim(post_string('first_name'));
            $last = trim(post_string('last_name'));
            $email = strtolower(trim(post_string('email')));
            $phone = trim(post_string('phone_number'));
            $alternativePhone = trim(post_string('alternative_phone_number'));
            $username = trim(post_string('username'));
            $date = trim(post_string('employment_date'));
            $password = post_string('temporary_password');
            $confirm = post_string('confirm_password');
            $addOld = ['first_name'=>$first, 'last_name'=>$last, 'email'=>$email,
                       'phone_number'=>$phone, 'alternative_phone_number'=>$alternativePhone, 'username'=>$username, 'employment_date'=>$date];
            checkEmployeeName($first, 'First name', $errors);
            checkEmployeeName($last, 'Last name', $errors);
            checkEmployeeEmail($email, $errors);
            checkEmployeePhone($phone, $errors);
            checkAlternativePhone($alternativePhone, $phone, $errors);
            checkEmployeeUsername($username, $errors);
            checkEmploymentDate($date, $errors);
            checkEmployeePassword($password, $confirm, $errors);
            if ((function_exists('mb_strlen') ? mb_strlen($first . ' ' . $last, 'UTF-8') : strlen($first . ' ' . $last)) > 150) $errors[] = 'Full name exceeds 150 characters.';
            if (!$errors) $errors = duplicateEmployee($conn, $username, $email);
            if (!$errors) {
                $hash = password_hash($password, PASSWORD_DEFAULT); // Secure salted hash, never plain text.
                $conn->begin_transaction();
                $inTransaction = true;
                $newPhoto = saveEmployeePhoto($_FILES['employee_photo'] ?? null);
                execWrite($conn,
                    "INSERT INTO users (full_name, username, email, phone_number, alternative_phone_number,
                            employment_date, avatar_path, password_hash, role, account_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'employee', 'active')",
                    [$first . ' ' . $last, $username, $email, $phone, $alternativePhone ?: null, $date, $newPhoto, $hash]
                );
                $createdId = (int)$conn->insert_id;
                logEmployeeChange($conn, $auditReady, $ownerId, 'employee.created', $createdId);
                $conn->commit();
                $inTransaction = false;
                $_SESSION['employee_flash'] = 'Employee created successfully. Share the initial password privately; the employee can change it using Forgot Password.';
                goEmployee($createdId);
            }
        } elseif (in_array($action, ['edit', 'status'], true)) {
            $employee = $employeeId ? getEmployee($conn, (int)$employeeId) : null;
            if (!$employee) {
                $errors[] = 'Employee not found.';
            } elseif ($action === 'edit') {
                $full = trim(post_string('full_name'));
                $username = trim(post_string('username'));
                $email = strtolower(trim(post_string('email')));
                $phone = trim(post_string('phone_number'));
                $alternativePhone = trim(post_string('alternative_phone_number'));
                $date = trim(post_string('employment_date'));
                if (!validEmployeeName($full, 150) || !str_contains($full, ' ')) {
                    $errors[] = 'Full name must contain first and last name (letters, spaces, hyphens or apostrophes).';
                }
                checkEmployeeUsername($username, $errors);
                checkEmployeeEmail($email, $errors);
                checkEmployeePhone($phone, $errors);
                checkAlternativePhone($alternativePhone, $phone, $errors);
                checkEmploymentDate($date, $errors);
                if (!$errors) $errors = duplicateEmployee($conn, $username, $email, (int)$employeeId);
                if (!$errors) {
                    $changedIdentity = strcasecmp((string)$employee['email'], $email) !== 0 ||
                        strcasecmp((string)$employee['username'], $username) !== 0;
                    $conn->begin_transaction();
                    $inTransaction = true;
                    $oldPhoto = $employee['avatar_path'] ?: null;
                    $newPhoto = saveEmployeePhoto($_FILES['employee_photo'] ?? null);
                    execWrite($conn,
                        "UPDATE users SET full_name=?, username=?, email=?, phone_number=?,
                                alternative_phone_number=?, employment_date=?,
                                avatar_path=COALESCE(?, avatar_path), session_version=session_version+?
                         WHERE user_id=? AND role='employee'",
                        [$full, $username, $email, $phone, $alternativePhone ?: null, $date, $newPhoto,
                         $changedIdentity ? 1 : 0, (int)$employeeId]
                    );
                    if ($changedIdentity) revokeEmployeeResets($conn, $resetsReady, (int)$employeeId);
                    logEmployeeChange($conn, $auditReady, $ownerId, 'employee.updated', (int)$employeeId);
                    $conn->commit();
                    $inTransaction = false;
                    if ($newPhoto !== null) removePrivatePhoto($oldPhoto);
                    $_SESSION['employee_flash'] = 'Employee information updated.';
                    goEmployee((int)$employeeId);
                }
            } elseif ($action === 'status') {
                $newStatus = post_string('new_status');
                if (!in_array($newStatus, ['active', 'deactivated'], true)) {
                    $errors[] = 'Unknown account status.';
                } else {
                    $conn->begin_transaction();
                    $inTransaction = true;
                    execWrite($conn,
                        "UPDATE users SET account_status=?, session_version=session_version+1,
                                failed_attempts=0, locked_until=NULL
                         WHERE user_id=? AND role='employee'",
                        [$newStatus, (int)$employeeId]
                    );
                    revokeEmployeeResets($conn, $resetsReady, (int)$employeeId);
                    logEmployeeChange($conn, $auditReady, $ownerId, 'employee.status.' . $newStatus, (int)$employeeId);
                    $conn->commit();
                    $inTransaction = false;
                    $_SESSION['employee_flash'] = $newStatus === 'active' ? 'Employee reactivated successfully.' : 'Employee deactivated. Their task history has been kept.';
                    goEmployee((int)$employeeId);
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    } catch (Throwable $ex) {
        if ($inTransaction) $conn->rollback();
        if ($newPhoto !== null) removePrivatePhoto($newPhoto);
        if ($ex instanceof mysqli_sql_exception && (int)$ex->getCode() === 1062) {
            $errors[] = 'An employee with this email address or username already exists.';
        } elseif ($ex instanceof RuntimeException) {
            $errors[] = $ex->getMessage();
        } else {
            error_log('NEXSUS employee action failed: ' . $ex->getMessage());
            $errors[] = 'Employee action failed. Check the server error log.';
        }
    }
}

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$q = substr($q, 0, 80);
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'all';
if (!in_array($status, ['all', 'active', 'inactive', 'suspended', 'deactivated'], true)) $status = 'all';
$counts = $conn->query("SELECT COUNT(*) total,
                         COALESCE(SUM(account_status='active'),0) active_count,
                         COALESCE(SUM(account_status <> 'active'),0) inactive_count
                         FROM users WHERE role='employee'")->fetch_assoc();

$taskStats = $tasksReady
    ? ", COALESCE(t.assigned,0) assigned_tasks,
          COALESCE(t.completed,0) completed_tasks,
          COALESCE(t.overdue,0) overdue_tasks"
    : ', 0 assigned_tasks, 0 completed_tasks, 0 overdue_tasks';
$taskJoin = $tasksReady ? " LEFT JOIN (
    SELECT assigned_to, SUM(status <> 'completed') assigned,
           SUM(status='completed') completed,
           SUM(status <> 'completed' AND due_date < CURDATE()) overdue
    FROM tasks GROUP BY assigned_to
) t ON t.assigned_to=u.user_id" : '';
$sql = 'SELECT u.user_id, u.full_name, u.username, u.email, u.phone_number, u.account_status,
                u.employment_date, u.avatar_path, u.created_at, u.last_login_at' . $taskStats .
       " FROM users u" . $taskJoin . " WHERE u.role='employee'";
$params = [];
if ($status === 'inactive') $sql .= " AND u.account_status <> 'active'";
elseif ($status !== 'all') { $sql .= ' AND u.account_status=?'; $params[] = $status; }
if ($q !== '') {
    $sql .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$sql .= ' ORDER BY u.user_id DESC';
$employees = fetchRows($conn, $sql, $params);
$viewId = filter_var($_GET['view'] ?? ($_POST['employee_id'] ?? null), FILTER_VALIDATE_INT);
$selected = $viewId ? getEmployee($conn, (int)$viewId) : null;
$assignedTasks = [];
$history = [];
if ($selected && $tasksReady) {
    $assignedTasks = fetchRows($conn,
        'SELECT task_id, task_title, status, priority, due_date FROM tasks WHERE assigned_to=? ORDER BY due_date ASC LIMIT 12',
        [(int)$viewId]
    );
    if ($historyReady) {
        $history = fetchRows($conn,
            "SELECT h.previous_status, h.new_status, h.changed_at, t.task_title
             FROM workflow_history h JOIN tasks t ON t.task_id=h.entity_id
             WHERE h.entity_type='task' AND t.assigned_to=?
             ORDER BY h.changed_at DESC LIMIT 8",
            [(int)$viewId]
        );
    }
}
?>
<?php
// Shared sidebar, top bar, notification bell, theme switch, and footer.
// Keep Bootstrap Toast JS for the existing employee success message.
$employeeHead = '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" '
    . 'integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" '
    . 'crossorigin="anonymous" defer></script>';
nexShellStart($owner, 'Employee Management', 'employee.php', $csrf, [
    'mainClass' => 'employee-page',
    'head' => $employeeHead,
]);
?>
        <div class="employee-heading">
          <div><p class="eyebrow">MANAGEMENT / EMPLOYEES</p><h2>Employee Management</h2><p>Create employee accounts, edit details and control active/inactive access.</p></div>
          <button class="primary-button" type="button" id="openEmployeeModal"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Employee</button>
        </div>
        <?php if ($errors): ?><div class="notice error" role="alert"><strong>Please correct:</strong><ul><?php foreach ($errors as $error): ?><li><?= e((string)$error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

        <section class="employee-stats" aria-label="Employee totals">
          <article class="stat-card"><span class="stat-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span>Total Employees</span><strong><?= (int)$counts['total'] ?></strong></article>
          <article class="stat-card"><span class="stat-icon green"><i class="bi bi-person-check" aria-hidden="true"></i></span><span>Active</span><strong><?= (int)$counts['active_count'] ?></strong></article>
          <article class="stat-card"><span class="stat-icon gold"><i class="bi bi-person-dash" aria-hidden="true"></i></span><span>Inactive</span><strong><?= (int)$counts['inactive_count'] ?></strong></article>
        </section>

        <div class="employee-layout">
          <section class="employee-directory" aria-labelledby="directoryTitle">
            <div class="section-title"><div><h3 id="directoryTitle">Employee Directory</h3><p>Search employees and review their workload.</p></div><span class="employee-result-count"><?= count($employees) ?> shown</span></div>
                     <form method="get" action="employee.php" class="employee-filters" role="search">
              <label class="directory-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" name="q" maxlength="80" value="<?= e($q) ?>" placeholder="Search name, username or email" aria-label="Search employees"></label>
              <input type="hidden" name="status" value="<?= e($status) ?>">
              <button class="secondary-button" type="submit">Search</button>
              <?php if ($q !== ''): ?><a class="text-button" href="employee.php?status=<?= e($status) ?>">Clear</a><?php endif; ?>
            </form>
            <nav class="employee-status-tabs" aria-label="Filter employees by status">
              <?php foreach (['all'=>'All', 'active'=>'Active', 'inactive'=>'Inactive'] as $filter=>$label): ?>
                <a class="employee-status-tab <?= $status === $filter ? 'is-current' : '' ?>"
                   href="employee.php?status=<?= e($filter) ?>&amp;q=<?= e(rawurlencode($q)) ?>"
                   <?= $status === $filter ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
              <?php endforeach; ?>
            </nav>
            <?php if (!$tasksReady): ?><p class="small-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Task workload will appear after the Tasks table is created and connected.</p><?php endif; ?>
            <div class="employee-card-grid">
              <?php foreach ($employees as $employee): ?>
                <?php $parts = explode(' ', trim((string)$employee['full_name'])); $initials = strtoupper(substr($parts[0] ?? 'E',0,1) . substr($parts[count($parts)-1] ?? '',0,1)); ?>
                <article class="employee-card <?= $employee['account_status'] === 'active' ? 'is-active' : 'is-inactive' ?>">
                  <div class="employee-card-top"><span class="employee-tile" aria-hidden="true"><?php if (!empty($employee['avatar_path'])): ?><img src="employee.php?photo=<?= (int)$employee['user_id'] ?>" alt="" loading="lazy"><?php else: ?><?= e($initials) ?><?php endif; ?></span><span class="status-badge status-<?= $employee['account_status'] === 'active' ? 'active' : 'inactive' ?>"><?= $employee['account_status'] === 'active' ? 'Active' : 'Inactive' ?></span></div>
                  <h4><?= e((string)$employee['full_name']) ?></h4>
                  <p class="employee-email"><?= e((string)$employee['email']) ?></p>
                  <p class="employee-username">@<?= e((string)$employee['username']) ?></p>
                  <p class="small-note">Employee ID: #<?= (int)$employee['user_id'] ?></p>
                  <?php if (empty($employee['phone_number'])): ?>
                    <p class="small-note"><i class="bi bi-exclamation-circle"></i> Employee details incomplete — open Edit</p>
                  <?php endif; ?>
                  <div class="workload-strip"><span><strong><?= (int)$employee['assigned_tasks'] ?></strong> Open</span><span><strong><?= (int)$employee['completed_tasks'] ?></strong> Completed</span><span class="<?= (int)$employee['overdue_tasks']>0?'urgent':'' ?>"><strong><?= (int)$employee['overdue_tasks'] ?></strong> Overdue</span></div>
                  <div class="employee-card-actions">
                    <a class="secondary-button" href="employee.php?view=<?= (int)$employee['user_id'] ?>#employeeDetails"><i class="bi bi-eye" aria-hidden="true"></i> View</a>
                    <a class="secondary-button" href="employee.php?view=<?= (int)$employee['user_id'] ?>&amp;edit=1#employeeDetails"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit</a>
                  </div>
                </article>
              <?php endforeach; ?>
              <?php if (!$employees): ?><div class="empty-state"><i class="bi bi-people" aria-hidden="true"></i><h4>No employees found</h4><p>Try another search or add your first employee.</p></div><?php endif; ?>
            </div>
          </section>

          <?php if ($selected): ?>
          <aside class="employee-detail-panel" id="employeeDetails" aria-labelledby="detailTitle">
            <div class="detail-heading"><div><p class="eyebrow">EMPLOYEE PROFILE</p><h3 id="detailTitle"><?= e((string)$selected['full_name']) ?></h3></div><a href="employee.php" class="close-detail" aria-label="Close employee details"><i class="bi bi-x-lg"></i></a></div>
            <p class="detail-email"><?= e((string)$selected['email']) ?></p>
            <p class="small-note">Employee ID: #<?= (int)$selected['user_id'] ?></p>
            <div class="detail-summary"><span class="status-badge status-<?= $selected['account_status'] === 'active' ? 'active' : 'inactive' ?>"><?= $selected['account_status'] === 'active' ? 'Active' : 'Inactive' ?></span><span class="detail-role">Employee · @<?= e((string)$selected['username']) ?></span></div>
            <dl class="profile-facts">
              <div><dt>Contact number</dt><dd><?= e($selected['phone_number'] ?: 'Not provided') ?></dd></div>
              <div><dt>Alternative contact</dt><dd><?= e($selected['alternative_phone_number'] ?: 'Not provided') ?></dd></div>
              <div><dt>Date employed</dt><dd><?= e((string)($selected['employment_date'] ?: 'Not recorded')) ?></dd></div>
              <div><dt>Account created</dt><dd><?= e((string)$selected['created_at']) ?></dd></div>
              <div><dt>Last login</dt><dd><?= e($selected['last_login_at'] ?: 'Not yet') ?></dd></div>
            </dl>

            <details class="detail-disclosure" <?= ((isset($_GET['edit']) && $_GET['edit'] === '1') || ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit' && $errors)) ? 'open' : '' ?>>
              <summary><i class="bi bi-pencil-square"></i> Edit Employee</summary>
              <form method="post" class="detail-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="employee_id" value="<?= (int)$selected['user_id'] ?>">
                <label>Full Name <input name="full_name" required maxlength="150" value="<?= e((string)$selected['full_name']) ?>"></label>
                <label>Username <input name="username" data-username-check required minlength="8" maxlength="50" pattern="[A-Za-z][A-Za-z0-9._-]{7,49}" value="<?= e((string)$selected['username']) ?>"><small class="username-feedback" aria-live="polite"></small></label>
                <label>Email <input name="email" type="email" required maxlength="254" value="<?= e((string)$selected['email']) ?>"></label>
                <label>Contact Number * <input name="phone_number" type="tel" inputmode="numeric" required maxlength="10" pattern="0[678][0-9]{8}" value="<?= e((string)($selected['phone_number']??'')) ?>"></label>
                <label>Alternative Contact Number (optional) <input name="alternative_phone_number" type="tel" inputmode="numeric" maxlength="10" pattern="0[678][0-9]{8}" value="<?= e((string)($selected['alternative_phone_number']??'')) ?>"></label>
                <label>Date Employed <input name="employment_date" type="date" required max="<?= date('Y-m-d') ?>" value="<?= e((string)($selected['employment_date']??'')) ?>"></label>
                <h4>Profile Photo (optional)</h4>
                <p class="small-note">JPG, PNG or WebP · up to 2 MB. Leave the upload blank to keep the existing photo.</p>
                <div class="employee-photo-picker" data-photo-picker>
                  <div class="employee-photo-preview" aria-hidden="true">
                    <img data-photo-preview alt="" <?= empty($selected['avatar_path']) ? 'hidden' : '' ?>
                         <?= !empty($selected['avatar_path']) ? 'src="employee.php?photo=' . (int)$selected['user_id'] . '"' : '' ?>>
                    <span data-photo-placeholder <?= !empty($selected['avatar_path']) ? 'hidden' : '' ?>><i class="bi bi-person-circle"></i></span>
                  </div>
                  <label>Choose profile picture
                    <input name="employee_photo" type="file" accept="image/jpeg,image/png,image/webp">
                  </label>
                  <small class="employee-photo-note" data-photo-note aria-live="polite">Optional — no new picture selected</small>
                </div>
                <button class="primary-button" type="submit">Save Changes</button>
              </form>
            </details>

            <div class="account-controls">
              <h4>Account Status</h4>
              <p class="small-note">Inactive employees cannot sign in. Reactivation keeps their previous records and tasks.</p>
              <form method="post" class="status-controls">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="employee_id" value="<?= (int)$selected['user_id'] ?>">
                <input type="hidden" name="new_status" value="<?= $selected['account_status'] === 'active' ? 'deactivated' : 'active' ?>">
                <?php if ($selected['account_status'] === 'active'): ?>
                  <button class="danger-button" type="submit" data-confirm-status="deactivate"><i class="bi bi-person-dash" aria-hidden="true"></i> Deactivate Employee</button>
                <?php else: ?>
                  <button class="success-button" type="submit" data-confirm-status="reactivate"><i class="bi bi-person-check" aria-hidden="true"></i> Reactivate Employee</button>
                <?php endif; ?>
              </form>
              <p class="small-note"><i class="bi bi-key" aria-hidden="true"></i> <strong>Forgot password?</strong> The employee uses the existing Forgot Password link on the login page. The reset link is sent to their registered email; no password reset is performed here.</p>
            </div>

            <div class="detail-tasks"><h4>Assigned Tasks</h4>
              <?php if (!$tasksReady): ?><p class="small-note">Task module is not connected yet.</p>
              <?php elseif (!$assignedTasks): ?><p class="small-note">No tasks assigned to this employee.</p>
              <?php else: ?><div class="mini-tasks">
                <?php foreach ($assignedTasks as $task): ?>
                  <div class="mini-task"><strong><?= e((string)$task['task_title']) ?></strong><small><?= e((string)$task['due_date']) ?> · <?= e(str_replace('_',' ',(string)$task['status'])) ?></small></div>
                <?php endforeach; ?></div>
              <?php endif; ?>
            </div>
            <div class="detail-tasks"><h4>Task History</h4>
              <?php if (!$historyReady): ?><p class="small-note">Workflow history will appear once that table is implemented.</p>
              <?php elseif (!$history): ?><p class="small-note">No task status changes recorded yet.</p>
              <?php else: ?><div class="mini-tasks">
                <?php foreach ($history as $entry): ?><div class="mini-task"><strong><?= e((string)$entry['task_title']) ?></strong><small><?= e((string)($entry['previous_status']??'New')) ?> → <?= e((string)$entry['new_status']) ?> · <?= e((string)$entry['changed_at']) ?></small></div><?php endforeach; ?>
              </div><?php endif; ?>
            </div>
          </aside>
          <?php endif; ?>
        </div>
  <?php if ($flash): ?>
    <div class="employee-toast-region" aria-live="polite" aria-atomic="true">
      <div class="toast nexsus-toast" id="employeeToast" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="6500">
        <div class="toast-header">
          <span class="toast-success-icon" aria-hidden="true"><i class="bi bi-check-circle-fill"></i></span>
          <strong class="me-auto">NEXSUS · Success!</strong>
          <small>just now</small>
          <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close notification"></button>
        </div>
        <div class="toast-body"><?= e((string)$flash) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Add Employee modal: same file, not a separate page. -->
  <div class="employee-modal" id="employeeModal" data-autoopen="<?= $openAdd ? 'yes' : 'no' ?>" hidden>
    <div class="employee-modal-backdrop" data-close-employee></div>
    <section class="employee-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="addEmployeeTitle" tabindex="-1">
      <div class="modal-heading">
        <h2 id="addEmployeeTitle">Add Employee</h2>
        <button type="button" class="modal-close" data-close-employee aria-label="Close"><i class="bi bi-x-lg"></i></button>
      </div>
      <form method="post" action="employee.php" class="add-employee-form password-form" data-employee-add data-password-form enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add">

        <fieldset>
          <legend><span>A</span> Personal Information</legend>
          <div class="form-grid">
            <label>
              <span class="field-label">First Name <em aria-hidden="true">*</em></span>
              <input class="form-control" name="first_name" required minlength="2" maxlength="75" autocomplete="given-name" value="<?= e($addOld['first_name']) ?>" data-error="Use 2–75 letters; spaces, hyphens and apostrophes are allowed.">
            </label>
            <label>
              <span class="field-label">Last Name <em aria-hidden="true">*</em></span>
              <input class="form-control" name="last_name" required minlength="2" maxlength="75" autocomplete="family-name" value="<?= e($addOld['last_name']) ?>" data-error="Use 2–75 letters; spaces, hyphens and apostrophes are allowed.">
            </label>
            <label>
              <span class="field-label">Email Address <em aria-hidden="true">*</em></span>
              <input class="form-control" name="email" type="email" required maxlength="254" autocomplete="off" value="<?= e($addOld['email']) ?>" data-error="Enter a valid email address.">
            </label>
            <label>
              <span class="field-label">Contact Number <em aria-hidden="true">*</em></span>
              <input class="form-control" name="phone_number" type="tel" inputmode="numeric" required maxlength="10" pattern="0[678][0-9]{8}" autocomplete="off" value="<?= e($addOld['phone_number']) ?>" data-error="Enter 10 digits starting with 06, 07 or 08.">
            </label>
            <label>
              <span class="field-label">Alternative Contact Number (optional)</span>
              <input class="form-control" name="alternative_phone_number" type="tel" inputmode="numeric" maxlength="10" pattern="0[678][0-9]{8}" autocomplete="off" value="<?= e($addOld['alternative_phone_number']) ?>" ">
            </label>
            <div class="employee-photo-field" data-photo-picker>
              <span class="field-label">Profile Photo (optional)</span>
              <div class="employee-photo-picker">
                <div class="employee-photo-preview" aria-hidden="true">
                  <img data-photo-preview alt="" hidden>
                  <span data-photo-placeholder><i class="bi bi-person-circle"></i></span>
                </div>
                <div class="employee-photo-input">
                  <label for="newEmployeePhoto">Choose profile picture</label>
                  <input class="form-control" id="newEmployeePhoto" name="employee_photo" type="file" accept="image/jpeg,image/png,image/webp">
                  <small class="employee-photo-note" data-photo-note aria-live="polite">Optional — JPG, PNG or WebP · maximum 2 MB</small>
                </div>
              </div>
            </div>
          </div>
        </fieldset>

        <fieldset>
          <legend><span>B</span> Account Information</legend>
          <div class="form-grid">
            <label>
              <span class="field-label">Username <em aria-hidden="true">*</em></span>
              <input class="form-control" name="username" data-username-check required minlength="8" maxlength="50" pattern="[A-Za-z][A-Za-z0-9._-]{7,49}" value="<?= e($addOld['username']) ?>" autocomplete="off" data-error="8–50 characters; begin with a letter. Letters, numbers, dots, underscores and hyphens only.">
              <small class="username-feedback" aria-live="polite">Availability checked while typing.</small>
            </label>
            <label>
              <span class="field-label">Date Employed <em aria-hidden="true">*</em></span>
              <input class="form-control" name="employment_date" type="date" required max="<?= date('Y-m-d') ?>" value="<?= e($addOld['employment_date']) ?>" data-error="Choose today's date or an earlier date.">
            </label>
          </div>
          <div class="account-summary" aria-label="Automatic account settings">
            <span><i class="bi bi-person" aria-hidden="true"></i> Employee</span>
            <span class="account-active"><i class="bi bi-check-circle" aria-hidden="true"></i> Active</span>
          </div>
        </fieldset>

        <fieldset>
          <legend><span>C</span> Login Security</legend>
          <div class="form-grid">
            <label>
              <span class="field-label">Temporary Password <em aria-hidden="true">*</em></span>
              <span class="password-input-wrap"><input class="form-control" name="temporary_password" type="password" required minlength="12" maxlength="64" autocomplete="new-password" data-error="Use 12–64 characters, including lowercase, uppercase, number and symbol."><button type="button" class="password-eye" data-show-password aria-label="Show temporary password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></span>
            </label>
            <label>
              <span class="field-label">Confirm Password <em aria-hidden="true">*</em></span>
              <span class="password-input-wrap"><input class="form-control" name="confirm_password" type="password" required minlength="12" maxlength="64" autocomplete="new-password" data-error="Passwords must match."><button type="button" class="password-eye" data-show-password aria-label="Show confirm password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></span>
            </label>
          </div>
          <div class="password-strength" data-password-strength>
    <div class="strength-top">
        <strong>Password strength</strong>
        <span data-password-label>Not entered</span>
    </div>

    <div class="strength-track">
        <div class="strength-fill" data-strength-bar></div>
    </div>
    <ul class="password-checklist" aria-label="Required password rules">
      <li data-password-rule="length">12–72 characters (no spaces)</li>
      <li data-password-rule="lowercase">One lowercase letter</li>
      <li data-password-rule="uppercase">One uppercase letter</li>
      <li data-password-rule="number">One number</li>
      <li data-password-rule="symbol">One symbol, such as ! or @</li>
    </ul>
</div>
        </fieldset>
        <div class="modal-actions"><button type="button" class="secondary-button" data-close-employee>Cancel</button><button type="submit" class="primary-button"><i class="bi bi-person-plus"></i> Create Employee</button></div>
      </form>
    </section>
  </div>
<?php nexShellEnd(); ?>
