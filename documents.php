<?php
declare(strict_types=1);

/**
 * NEXSUS Documents — upload, list, download, archive / restore.
 * Owner page: documents.php
 * Employee page: myDocuments.php  ->  just contains:  require __DIR__ . '/documents.php';
 */
require_once __DIR__ . '/nexus_helpers.php'; // your helper file (it loads authorization.php)

const DOC_MAX_BYTES = 10 * 1024 * 1024; // 10 MB
const DOC_MIME = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

$user    = require_logged_in();
$csrf    = csrf_token();
$isOwner = $user['role'] === 'owner';
$userId  = (int)$user['user_id'];
$self    = basename($_SERVER['SCRIPT_NAME']); // documents.php or myDocuments.php

/* ------------------------------------------------------------------ helpers */

function doc_size(int $bytes): string {
    if ($bytes < 1024) return number_format($bytes) . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1048576, 2) . ' MB';
}

/** Private folder OUTSIDE the website folder: C:\xampp\nexsus_private_documents */
function doc_storage_dir(): string {
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'nexsus_private_documents';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the private document folder. Check folder permissions.');
    }
    $norm    = fn(string $p): string => rtrim(strtolower(str_replace('\\', '/', $p)), '/');
    $docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $root    = $docRoot !== '' ? realpath($docRoot) : false;
    $real    = realpath($dir);
    if ($root !== false && $real !== false && str_starts_with($norm($real) . '/', $norm($root) . '/')) {
        throw new RuntimeException('Unsafe storage path: documents must stay outside the website folder.');
    }
    return $dir;
}

/** Default title = file name without extension, max 180 characters (documents.title is NOT NULL varchar(180)). */
function doc_title(string $original): string {
    $title = trim(pathinfo($original, PATHINFO_FILENAME));
    if ($title === '') $title = 'Document';
    return function_exists('mb_substr') ? mb_substr($title, 0, 180, 'UTF-8') : substr($title, 0, 180);
}

/** Checks real file contents, never the browser-supplied type. */
function doc_valid_file(string $path, string $ext): bool {
    if ($ext === 'pdf') {
        return str_contains((string)file_get_contents($path, false, null, 0, 1024), '%PDF-');
    }
    if ($ext === 'png' || $ext === 'jpg' || $ext === 'jpeg') {
        $info = @getimagesize($path);
        return $info !== false && $info['mime'] === ($ext === 'png' ? 'image/png' : 'image/jpeg');
    }
    // docx / xlsx are ZIP packages
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('To upload Word/Excel files, enable the PHP ZIP extension (extension=zip in php.ini).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CHECKCONS) !== true) return false;
    $ok = $zip->locateName('[Content_Types].xml') !== false
       && $zip->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false;
    $zip->close();
    return $ok;
}

function doc_audit(mysqli $conn, int $actor, string $action, int $docId): void {
    nexAudit($conn, nexAuditReady($conn), $actor, $action, 'documents', $docId, 'Document action');
}

/** Best effort: a notification problem must never block an upload. */
function doc_notify(mysqli $conn, bool $isOwner, int $actor, int $docId, string $name, int $taskId): void {
    try {
        if (!nexColumns($conn, 'notifications')) return;
        $short   = function_exists('mb_substr') ? mb_substr($name, 0, 160, 'UTF-8') : substr($name, 0, 160);
        $message = ($isOwner ? 'Owner' : 'Employee') . ' uploaded ' . $short . '.';
        if ($isOwner) {   // owner uploads -> tell the assigned employee
            $recipients = $taskId ? nexRows($conn,
                "SELECT u.user_id FROM tasks t JOIN users u ON u.user_id = t.assigned_to
                 WHERE t.task_id = ? AND u.account_status = 'active'", [$taskId]) : [];
        } else {          // employee uploads -> tell the owner(s)
            $recipients = nexRows($conn, "SELECT user_id FROM users WHERE role = 'owner' AND account_status = 'active'");
        }
        foreach ($recipients as $r) {
            if ((int)$r['user_id'] === $actor) continue;
            nexWrite($conn,
                "INSERT INTO notifications (user_id, notification_type, title, message, reference_type, reference_id)
                 VALUES (?, 'document_uploaded', 'Document uploaded', ?, 'document', ?)",
                [$r['user_id'], $message, $docId]);
        }
    } catch (Throwable $e) {
        error_log('NEXSUS Documents notification skipped: ' . $e->getMessage());
    }
}

/** Validates and stores one uploaded file. Throws RuntimeException with a user-friendly message. */
function doc_handle_upload(mysqli $conn, bool $isOwner, int $userId): void {
    $file = $_FILES['document'] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) throw new RuntimeException('Select one document to upload.');

    $err = (int)$file['error'];
    if ($err === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Select one document to upload.');
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('File exceeds the server upload size limit.');
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed (PHP upload error code ' . $err . ').');

    $tmp   = (string)$file['tmp_name'];
    $bytes = (int)filesize($tmp);
    if ($bytes < 1 || $bytes > DOC_MAX_BYTES) throw new RuntimeException('File must be between 1 byte and 10 MB.');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded file.');

    $original = trim(basename(str_replace('\\', '/', (string)$file['name'])));
    if ($original === '' || strlen($original) > 255 || preg_match('/[\x00-\x1F\x7F]/', $original)) {
        throw new RuntimeException('Invalid original filename.');
    }
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(DOC_MIME[$ext]) || !doc_valid_file($tmp, $ext)) {
        throw new RuntimeException('Allowed files: PDF, JPG, PNG, DOCX or XLSX with valid contents.');
    }

    // Work out client / request / task and make sure they belong together.
    $taskId    = (int)(filter_var(nexText('task_id'), FILTER_VALIDATE_INT) ?: 0);
    $requestId = (int)(filter_var(nexText('service_request_id'), FILTER_VALIDATE_INT) ?: 0);
    $clientId  = (int)(filter_var(nexText('client_id'), FILTER_VALIDATE_INT) ?: 0);

    if ($taskId) {
        $task = nexOne($conn,
            'SELECT t.assigned_to, t.service_request_id, r.client_id
             FROM tasks t JOIN service_requests r ON r.service_request_id = t.service_request_id
             WHERE t.task_id = ?', [$taskId]);
        if (!$task || (!$isOwner && (int)$task['assigned_to'] !== $userId)) {
            throw new RuntimeException('Select a task that you are allowed to access.');
        }
        if ($clientId && $clientId !== (int)$task['client_id']) throw new RuntimeException('The selected task belongs to a different client.');
        if ($requestId && $requestId !== (int)$task['service_request_id']) throw new RuntimeException('The selected task belongs to a different service request.');
        $clientId  = (int)$task['client_id'];
        $requestId = (int)$task['service_request_id'];
    } elseif (!$isOwner) {
        throw new RuntimeException('Select one of your assigned tasks.');
    }

    if ($clientId < 1) throw new RuntimeException('Select a client.');
    if ($requestId && !nexOne($conn, 'SELECT 1 FROM service_requests WHERE service_request_id = ? AND client_id = ?', [$requestId, $clientId])) {
        throw new RuntimeException('The service request must belong to the selected client.');
    }
    $client = nexOne($conn, 'SELECT ' . nexClientStatusExpression($conn) . ' AS st FROM clients c WHERE c.client_id = ?', [$clientId]);
    if (!$client) throw new RuntimeException('The selected client does not exist.');
    if ($client['st'] !== 'active') throw new RuntimeException('Reactivate the client before adding new documents.');

    // Store the file, then the database row. Undo the file if the database fails.
    $hash = hash_file('sha256', $tmp);
    if ($hash === false) throw new RuntimeException('Unable to verify the file.');
    $stored      = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = doc_storage_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('Unable to store the file. Check folder permissions.');

    try {
        $conn->begin_transaction();
        nexWrite($conn,
            "INSERT INTO documents (client_id, service_request_id, task_id, title, original_name, stored_name, storage_path,
                                    file_extension, mime_type, file_size, file_hash, uploaded_by, document_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [$clientId, $requestId ?: null, $taskId ?: null, doc_title($original), $original, $stored, $stored,
             $ext, DOC_MIME[$ext], $bytes, $hash, $userId]);
        $docId = (int)(nexOne($conn, 'SELECT LAST_INSERT_ID() AS id')['id'] ?? 0);
        if ($docId < 1) throw new RuntimeException('The document record could not be created.');
        doc_audit($conn, $userId, 'document.uploaded', $docId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        @unlink($destination);
        throw $e;
    }
    doc_notify($conn, $isOwner, $userId, $docId, $original, $taskId);
}

/* ------------------------------------------------------- schema safety check */

$required = ['document_id', 'client_id', 'service_request_id', 'task_id', 'title', 'original_name', 'stored_name',
    'storage_path', 'file_extension', 'mime_type', 'file_size', 'file_hash', 'uploaded_by', 'document_status', 'archived_at', 'created_at'];
$missing = array_diff($required, nexColumns($conn, 'documents'));
if ($missing) {
    http_response_code(503);
    exit('The documents table is missing or has different columns: ' . implode(', ', $missing)
        . '. Compare SHOW CREATE TABLE documents with your SQL before changing anything.');
}

/* ----------------------------------------------------------------- download */

if (isset($_GET['download'])) {
    $id  = filter_var($_GET['download'], FILTER_VALIDATE_INT);
    $doc = $id ? nexOne($conn,
        "SELECT original_name, stored_name, task_id, document_status FROM documents
         WHERE document_id = ? AND document_status IN ('active','archived')", [$id]) : null;
    if (!$doc) { http_response_code(404); exit('Document not found.'); }

    if (!$isOwner) {
        $allowed = $doc['document_status'] === 'active' && $doc['task_id'] !== null
            && nexOne($conn, 'SELECT 1 FROM tasks WHERE task_id = ? AND assigned_to = ?', [$doc['task_id'], $userId]);
        if (!$allowed) { http_response_code(403); exit('Access denied.'); }
    }

    $stored = (string)$doc['stored_name'];
    if (!preg_match('/\A[a-f0-9]{32}\.(?:pdf|png|jpg|jpeg|docx|xlsx)\z/D', $stored)) { http_response_code(404); exit('Document unavailable.'); }
    $path = doc_storage_dir() . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Stored document is missing.'); }

    try { doc_audit($conn, $userId, 'document.downloaded', (int)$id); } catch (Throwable $e) { error_log('NEXSUS Documents audit: ' . $e->getMessage()); }

    $name  = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', (string)$doc['original_name']) ?: 'document';
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'document';
    while (ob_get_level() > 0) ob_end_clean(); // nothing may be sent before the file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . (string)filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    session_write_close();
    readfile($path);
    exit;
}

/* --------------------------------------------------------------------- POST */

$errors = [];
$notice = get_flash();
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'The file is larger than the PHP post_max_size setting.';
    } elseif (!csrf_valid()) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    } else {
        $action = nexText('action');
        try {
            if ($action === 'upload') {
                doc_handle_upload($conn, $isOwner, $userId);
                flash('success', 'Document uploaded successfully.');
                nexRedirect($self);
            }

            if ($action === 'archive' || $action === 'restore') {
                if (!$isOwner) { http_response_code(403); exit('Only the Owner can archive or restore documents.'); }
                $docId = filter_var(nexText('document_id'), FILTER_VALIDATE_INT);
                if (!$docId || $docId < 1) throw new RuntimeException('Invalid document selection.');
                $archive = $action === 'archive';

                $conn->begin_transaction();
                try {
                    $changed = nexWrite($conn, $archive
                        ? "UPDATE documents SET document_status = 'archived', archived_at = NOW() WHERE document_id = ? AND document_status = 'active'"
                        : "UPDATE documents SET document_status = 'active', archived_at = NULL WHERE document_id = ? AND document_status = 'archived'",
                        [$docId]);
                    if ($changed !== 1) throw new RuntimeException('Document not found or already ' . ($archive ? 'archived.' : 'active.'));
                    doc_audit($conn, $userId, $archive ? 'document.archived' : 'document.restored', $docId);
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
                flash('success', $archive ? 'Document archived. The file is kept in private storage.' : 'Document restored to the active library.');
                nexRedirect($self . ($archive ? '' : '?status=archived'));
            }

            throw new RuntimeException('Unknown document action.');
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('NEXSUS Documents: ' . $e->getMessage());
            // While developing (display_errors on) show the real cause; otherwise point to the log.
            $errors[] = 'Unable to complete this action. ' . (ini_get('display_errors') ? '(' . $e->getMessage() . ')' : 'Check the PHP error log.');
        }
    }
}
$uploadFailed = $errors && $action === 'upload';

/* ------------------------------------------------------------ page data */

$label = nexClientLabelExpression($conn); // works with your different client schemas

$clients = $isOwner ? nexRows($conn, "SELECT c.client_id, $label AS display_name FROM clients c ORDER BY display_name") : [];

$taskScope = $isOwner ? '' : 'WHERE t.assigned_to = ?';
$tasks = nexRows($conn,
    "SELECT t.task_id, t.task_title, t.service_request_id, r.client_id
     FROM tasks t JOIN service_requests r ON r.service_request_id = t.service_request_id
     $taskScope ORDER BY t.task_id DESC LIMIT 200", $isOwner ? [] : [$userId]);

$serviceRequests = $isOwner ? nexRows($conn,
    "SELECT sr.service_request_id, sr.client_id, s.service_name
     FROM service_requests sr JOIN services s ON s.service_id = sr.service_id
     ORDER BY sr.service_request_id DESC LIMIT 300") : [];

$requestedClient = (int)(filter_var($_GET['client_id'] ?? 0, FILTER_VALIDATE_INT) ?: 0);
$q          = substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$visibility = ($isOwner && ($_GET['status'] ?? '') === 'archived') ? 'archived' : 'active';

$sql = "SELECT d.document_id, d.original_name, d.file_size, d.created_at, d.client_id, d.service_request_id, d.task_id,
               $label AS client_name, t.task_title, u.full_name AS uploaded_name, s.service_name
        FROM documents d
        JOIN clients c ON c.client_id = d.client_id
        JOIN users u ON u.user_id = d.uploaded_by
        LEFT JOIN tasks t ON t.task_id = d.task_id
        LEFT JOIN service_requests sr ON sr.service_request_id = d.service_request_id
        LEFT JOIN services s ON s.service_id = sr.service_id
        WHERE d.document_status = ? AND (d.original_name LIKE ? OR $label LIKE ?)";
$args = [$visibility, '%' . $q . '%', '%' . $q . '%'];
if (!$isOwner)           { $sql .= ' AND t.assigned_to = ?'; $args[] = $userId; }
if ($requestedClient > 0) { $sql .= ' AND d.client_id = ?';   $args[] = $requestedClient; }
$sql .= ' ORDER BY d.created_at DESC, d.document_id DESC LIMIT 200';

$documents  = nexRows($conn, $sql, $args);
$totalBytes = array_sum(array_map(fn($d) => (int)$d['file_size'], $documents));

/* ------------------------------------------------------------------- page */

require_once __DIR__ . '/nexsus_shell.php';
nexShellStart($user, $isOwner ? 'Documents' : 'My Documents', $self, $csrf, [
    'mainClass' => $isOwner ? 'docs-page' : 'docs-page my-documents-page',
]);
?>
      <div class="docs-heading">
        <div><p class="eyebrow">WORKFLOW / DOCUMENTS</p><h2>Document Management</h2><p>Securely upload, organise and download client documents linked to service requests and tasks.</p></div>
        <div class="docs-heading-actions">
          <span class="docs-limit"><i class="bi bi-shield-lock"></i> Private storage · Max 10 MB</span>
          <button type="button" class="primary-button docs-upload-trigger" data-open-docs-dialog aria-controls="docsUploadDialog" aria-haspopup="dialog"><i class="bi bi-plus-lg" aria-hidden="true"></i> Upload Document</button>
        </div>
      </div>

      <?php if ($notice): ?><div class="docs-alert success" role="status"><?= h((string)$notice['message']) ?></div><?php endif; ?>
      <?php if ($errors && !$uploadFailed): ?>
        <div class="docs-alert error" role="alert"><strong>Please correct:</strong><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <section class="docs-stats" aria-label="Document overview">
        <div class="docs-stat"><span><i class="bi bi-file-earmark-text"></i> <?= $visibility === 'active' ? 'Visible documents' : 'Archived documents' ?></span><strong><?= count($documents) ?></strong></div>
        <div class="docs-stat"><span><i class="bi bi-hdd"></i> Combined file size</span><strong><?= h(doc_size($totalBytes)) ?></strong></div>
      </section>

      <section class="docs-panel docs-library" aria-labelledby="libraryHeading" id="documentsTable">
        <div class="docs-panel-header">
          <div><h3 id="libraryHeading"><i class="bi bi-folder2-open" aria-hidden="true"></i> Documents Library</h3>
            <p class="docs-help">View uploaded files, their clients and requests, and who uploaded them.</p></div>
          <span class="docs-result-count"><?= count($documents) ?> shown</span>
        </div>

        <?php if ($requestedClient > 0 && $isOwner): ?>
          <div class="docs-context"><i class="bi bi-person-vcard" aria-hidden="true"></i> Showing documents for client #<?= $requestedClient ?>
            <a href="<?= h($self) ?>">View all clients</a></div>
        <?php endif; ?>

        <form method="get" action="<?= h($self) ?>#documentsTable" class="docs-search" role="search">
          <?php if ($requestedClient > 0): ?><input type="hidden" name="client_id" value="<?= $requestedClient ?>"><?php endif; ?>
          <label class="docs-search-field" for="docsQuery"><span class="docs-sr-only">Search documents</span><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="docsQuery" name="q" value="<?= h($q) ?>" placeholder="Search filename or client" maxlength="80"></label>
          <?php if ($isOwner): ?>
            <label for="docsStatus" class="docs-sr-only">Document status</label>
            <select id="docsStatus" name="status">
              <option value="active" <?= $visibility === 'active' ? 'selected' : '' ?>>Active documents</option>
              <option value="archived" <?= $visibility === 'archived' ? 'selected' : '' ?>>Archived documents</option>
            </select>
          <?php endif; ?>
          <button type="submit" class="secondary-button"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
          <?php if ($q !== '' || $visibility === 'archived'): ?><a class="secondary-button" href="<?= h($self . ($requestedClient > 0 ? '?client_id=' . $requestedClient : '')) ?>#documentsTable">Clear</a><?php endif; ?>
        </form>

        <div class="docs-table-wrap">
          <table class="docs-table">
            <thead>
              <tr><th scope="col">Document</th><th scope="col">Client</th><th scope="col">Service Request / Task</th><th scope="col">Uploaded by</th><th scope="col">Uploaded</th><th scope="col">Size</th><th scope="col">Actions</th></tr>
            </thead>
            <tbody>
              <?php if (!$documents): ?>
                <tr><td colspan="7" class="docs-table-empty"><i class="bi bi-folder2-open" aria-hidden="true"></i><strong>No documents found</strong><p><?= $q !== '' ? 'Try another search.' : 'Select Upload Document to add the first file.' ?></p></td></tr>
              <?php else: foreach ($documents as $doc): $docId = (int)$doc['document_id']; ?>
                <tr>
                  <td><span class="docs-file-label"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><strong><?= h((string)$doc['original_name']) ?></strong></span></td>
                  <td><?= h((string)($doc['client_name'] ?: 'Client #' . $doc['client_id'])) ?></td>
                  <td>
                    <?php if ($doc['service_request_id']): ?><small>Request #<?= (int)$doc['service_request_id'] ?><?= $doc['service_name'] ? ' · ' . h((string)$doc['service_name']) : '' ?></small><?php endif; ?>
                    <?php if ($doc['task_id']): ?><small>Task #<?= (int)$doc['task_id'] ?><?= $doc['task_title'] ? ' · ' . h((string)$doc['task_title']) : '' ?></small><?php endif; ?>
                    <?php if (!$doc['task_id'] && !$doc['service_request_id']): ?><span class="docs-muted">Client file</span><?php endif; ?>
                  </td>
                  <td><?= h((string)$doc['uploaded_name']) ?></td>
                  <td><time datetime="<?= h((string)$doc['created_at']) ?>"><?= h((string)$doc['created_at']) ?></time></td>
                  <td><?= h(doc_size((int)$doc['file_size'])) ?></td>
                  <td>
                    <div class="docs-actions">
                      <a class="secondary-button" href="<?= h($self) ?>?download=<?= $docId ?>"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
                      <?php if ($isOwner): $arch = $visibility === 'archived'; ?>
                        <form method="post" action="<?= h($self) ?>" data-docs-confirm="<?= $arch ? 'Restore this document?' : 'Archive this document? The file will be kept.' ?>">
                          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                          <input type="hidden" name="action" value="<?= $arch ? 'restore' : 'archive' ?>">
                          <input type="hidden" name="document_id" value="<?= $docId ?>">
                          <button class="secondary-button" type="submit"><i class="bi <?= $arch ? 'bi-arrow-counterclockwise' : 'bi-archive' ?>" aria-hidden="true"></i> <?= $arch ? 'Restore' : 'Archive' ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <dialog class="docs-upload-dialog" id="docsUploadDialog" aria-labelledby="docsUploadTitle" data-docs-dialog data-autoopen="<?= $uploadFailed ? '1' : '0' ?>">
        <div class="docs-modal-top">
          <div><p class="eyebrow">DOCUMENT MANAGEMENT</p><h3 id="docsUploadTitle">Add a document</h3><p class="docs-help">Securely save a file to a client, service request or assigned task.</p></div>
          <button type="button" class="docs-close" data-close-docs-dialog aria-label="Close document form"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <?php if ($uploadFailed): ?>
          <div class="docs-alert error" role="alert"><strong>Upload could not be completed:</strong><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form action="<?= h($self) ?>" method="post" enctype="multipart/form-data" class="docs-form" data-nxs-document-form>
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="upload">

          <?php if ($isOwner): ?>
            <label for="docClient">Client <em>*</em></label>
            <select id="docClient" name="client_id" required>
              <option value="">Select client</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['client_id'] ?>" <?= $requestedClient === (int)$c['client_id'] ? 'selected' : '' ?>><?= h((string)($c['display_name'] ?: 'Client #' . $c['client_id'])) ?></option>
              <?php endforeach; ?>
            </select>

            <label for="docRequest">Service Request <span>(optional)</span></label>
            <select id="docRequest" name="service_request_id">
              <option value="">No linked request</option>
              <?php foreach ($serviceRequests as $r): ?>
                <option value="<?= (int)$r['service_request_id'] ?>" data-client="<?= (int)$r['client_id'] ?>">#<?= (int)$r['service_request_id'] ?> · <?= h((string)$r['service_name']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>

          <label for="docTask"><?= $isOwner ? 'Related Task (optional)' : 'Assigned Task *' ?></label>
          <select id="docTask" name="task_id" <?= $isOwner ? '' : 'required' ?>>
            <option value=""><?= $isOwner ? 'No related task' : 'Select your task' ?></option>
            <?php foreach ($tasks as $t): ?>
              <option value="<?= (int)$t['task_id'] ?>" data-client="<?= (int)$t['client_id'] ?>" data-request="<?= (int)$t['service_request_id'] ?>"><?= h((string)$t['task_title']) ?> (#<?= (int)$t['task_id'] ?>)</option>
            <?php endforeach; ?>
          </select>

          <label for="docFile">Choose File <em>*</em></label>
          <div class="docs-file-drop" data-nxs-file-drop>
            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
            <p>Drop a file here or choose one from your computer</p>
            <input id="docFile" name="document" type="file" accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx" required>
            <small data-nxs-filename aria-live="polite">No file selected</small>
          </div>
          <p class="docs-help">PDF, JPG, PNG, DOCX or XLSX · Up to 10 MB per file · Private storage.</p>

          <div class="docs-form-actions">
            <button type="button" class="secondary-button" data-close-docs-dialog>Cancel</button>
            <button type="submit" class="primary-button" <?= ($isOwner ? !$clients : !$tasks) ? 'disabled' : '' ?>><i class="bi bi-check2" aria-hidden="true"></i> Save Document</button>
          </div>
          <?php if ($isOwner && !$clients): ?><p class="docs-help">Create a client first before uploading.</p><?php endif; ?>
          <?php if (!$isOwner && !$tasks): ?><p class="docs-help">You need an assigned task before uploading.</p><?php endif; ?>
        </form>
      </dialog>
<?php nexShellEnd(); ?>