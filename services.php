<?php
declare(strict_types=1);
require_once __DIR__ . '/nexsus_modules.php';
require_once __DIR__ . '/nexsus_shell.php';
$user = require_owner();
$actor = (int)$user['user_id'];
$csrf = csrf_token();
$categories = nexCategories();
$columns = nexColumns($conn, 'services');
$missing = array_diff(['service_id','service_name','category','description'], $columns);
if ($missing) { http_response_code(503); exit('Service setup incomplete: missing column(s) in services: '.h(implode(', ', $missing)).'. Check the supplied SQL migration; do NOT drop the table.'); }
$statusColumn = nexServiceStatusColumn($conn); // 'status' or 'service_status'
$auditReady = nexAuditReady($conn);
$errors = [];
$mode = '';
$editingId = 0;
$form = ['service_name'=>'','category'=>'','description'=>'','status'=>'active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) { http_response_code(403); exit('Invalid security token. Refresh this page.'); }
    $action = nexText('action');
    if (!in_array($action, ['create','update'], true)) {
        $errors[] = 'Unknown action.';
    } else {
        $mode = 'form';
        $editingId = $action === 'update' ? (int)filter_var($_POST['service_id'] ?? null,FILTER_VALIDATE_INT) : 0;
        $form = [
            'service_name'=>nexText('service_name'),
            'category'=>strtolower(nexText('category')),
            'description'=>nexText('description'),
            'status'=>nexText('status'),
        ];
        if (nexLen($form['service_name']) < 2 || nexLen($form['service_name']) > 120 || preg_match('/[\x00-\x1F\x7F]/',$form['service_name'])) {
            $errors[] = 'Service name must have 2–120 characters, without control characters.';
        }
        if (!isset($categories[$form['category']])) $errors[] = 'Choose Accounting, Taxation or Payroll.';
        if (nexLen($form['description']) > 500) $errors[] = 'Description must not exceed 500 characters.';
        if (!in_array($form['status'], ['active','inactive'], true)) $errors[] = 'Choose Active or Inactive.';
        if ($action === 'update' && !$editingId) $errors[] = 'Choose a service to edit.';
        if (!$errors) {
            // Unique service name within category. Existing global UNIQUE name indexes remain intact.
            $duplicate = nexOne($conn,
                'SELECT service_id FROM services WHERE LOWER(TRIM(service_name))=LOWER(TRIM(?)) AND LOWER(TRIM(category))=? AND service_id<>? LIMIT 1',
                [$form['service_name'],$form['category'],$editingId]);
            if ($duplicate) $errors[] = 'That service already exists in this category.';
        }
        if (!$errors) {
            $started = false;
            try {
                $conn->begin_transaction(); $started = true;
                if ($action === 'create') {
                    nexWrite($conn,"INSERT INTO services (service_name,category,description,`$statusColumn`) VALUES (?,?,?,?)",
                        [$form['service_name'],$form['category'],$form['description'] !== '' ? $form['description'] : null,$form['status']]);
                    $editingId = (int)$conn->insert_id;
                    nexAudit($conn,$auditReady,$actor,'service.created','services',$editingId,'Owner created a catalogue service.');
                } else {
                    $old = nexOne($conn,'SELECT service_id FROM services WHERE service_id=? FOR UPDATE',[$editingId]);
                    if (!$old) throw new RuntimeException('Service no longer exists.');
                    nexWrite($conn,"UPDATE services SET service_name=?,category=?,description=?,`$statusColumn`=? WHERE service_id=?",
                        [$form['service_name'],$form['category'],$form['description'] !== '' ? $form['description'] : null,$form['status'],$editingId]);
                    nexAudit($conn,$auditReady,$actor,'service.updated','services',$editingId,'Owner updated a catalogue service.');
                }
                $conn->commit(); $started = false;
                nexRedirect('services.php?notice='.($action === 'create'?'created':'updated'));
            } catch (Throwable $e) {
                if ($started) $conn->rollback();
                error_log('NEXSUS service catalogue: '.$e->getMessage());
                $errors[] = $e instanceof mysqli_sql_exception && $e->getCode() === 1062
                    ? 'Service name must be unique. Your existing index may require globally unique names.'
                    : 'Could not save the service. Check the database constraints and Apache error log.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editId = (int)(filter_var($_GET['edit'] ?? null,FILTER_VALIDATE_INT) ?: 0);
    if ($editId) {
        $row = nexOne($conn,"SELECT service_id,service_name,category,description,`$statusColumn` AS record_status FROM services WHERE service_id=?",[$editId]);
        if ($row) {
            $mode = 'form'; $editingId=$editId;
            $form=['service_name'=>(string)$row['service_name'],'category'=>strtolower((string)$row['category']),
                   'description'=>(string)($row['description'] ?? ''),'status'=>(string)$row['record_status']];
        } else $errors[] = 'Service not found.';
    } elseif (($_GET['mode'] ?? '') === 'add') { $mode = 'form'; }
}
$search = trim(is_string($_GET['q'] ?? null)?$_GET['q']:'');
$search = substr($search,0,90);
$filter = strtolower(is_string($_GET['category'] ?? null)?$_GET['category']:'all');
if ($filter!=='all' && !isset($categories[$filter])) $filter='all';
$where=[]; $params=[];
if ($search!=='') { $where[]='(service_name LIKE ? OR description LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; }
if ($filter!=='all') { $where[]='LOWER(TRIM(category))=?'; $params[]=$filter; }
$sql="SELECT service_id,service_name,category,description,`$statusColumn` AS record_status FROM services"
    .($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY LOWER(TRIM(category)),service_name LIMIT 400';
$services=nexRows($conn,$sql,$params);
$counts=nexOne($conn,"SELECT COUNT(*) AS total,COALESCE(SUM(`$statusColumn`='active'),0) AS active_count FROM services");
$notice = match ($_GET['notice'] ?? '') {'created'=>'Service added successfully.','updated'=>'Service updated successfully.',default=>''};
nexShellStart($user,'Service Management','services.php',$csrf);
?>
<section class="nex-heading">
  <div><p class="nex-eyebrow">MANAGEMENT / SERVICE CATALOGUE</p><h2>Service Management</h2>
    <p></p></div>
  <a class="nex-btn nex-btn-primary" href="services.php?mode=add#serviceForm"><i class="bi bi-plus-lg"></i> Add Service</a>
</section>
<?php if ($notice): ?><div class="nex-notice nex-notice-ok" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="nex-notice nex-notice-error" role="alert"><strong>Please correct:</strong><ul><?php foreach ($errors as $e): ?><li><?= h((string)$e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="nex-metrics" aria-label="Catalogue totals">
  <div class="nex-metric"><span>Catalogue services</span><strong><?= (int)$counts['total'] ?></strong></div>
  <div class="nex-metric"><span>Active services</span><strong><?= (int)$counts['active_count'] ?></strong></div>
  <div class="nex-metric"><span>Inactive services</span><strong><?= (int)$counts['total']-(int)$counts['active_count'] ?></strong></div>
</section>
<?php if ($mode === 'form'): ?>
<section class="nex-panel nex-form-panel" id="serviceForm">
  <div class="nex-panel-heading"><div><h3><?= $editingId ? 'Edit Service' : 'Add Service' ?></h3><p>Only catalogue details are needed here.</p></div><a class="nex-text-link" href="services.php">Close</a></div>
  <form method="post" action="services.php#serviceForm" class="nex-form" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="<?= $editingId?'update':'create' ?>">
    <?php if ($editingId): ?><input type="hidden" name="service_id" value="<?= $editingId ?>"><?php endif; ?>
    <div class="nex-form-grid">
      <label class="nex-field"><span>Service name <em>*</em></span><input name="service_name" maxlength="120" minlength="2" required value="<?= h($form['service_name']) ?>""></label>
      <label class="nex-field"><span>Category <em>*</em></span><select name="category" required><option value="">Choose category</option>
        <?php foreach ($categories as $key=>$title): ?><option value="<?= h($key) ?>" <?= $form['category']===$key?'selected':'' ?>><?= h($title) ?></option><?php endforeach; ?>
      </select></label>
      <label class="nex-field"><span>Status <em>*</em></span><select name="status" required><option value="active" <?= $form['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $form['status']==='inactive'?'selected':'' ?>>Inactive</option></select></label>
      <label class="nex-field nex-span-full"><span>Description (optional)</span><textarea name="description" maxlength="500" rows="3" placeholder="Brief explanation of what this service covers"><?= h($form['description']) ?></textarea><small>Maximum 500 characters</small></label>
    </div>
    <div class="nex-form-actions"><a class="nex-btn nex-btn-outline" href="services.php">Cancel</a><button class="nex-btn nex-btn-primary" type="submit"><?= $editingId?'Save Changes':'Add Service' ?></button></div>
  </form>
</section>
<?php endif; ?>
<section class="nex-panel">
  <div class="nex-panel-heading"><div><h3>Service Catalogue</h3><p>Services are grouped under Accounting, Taxation and Payroll.</p></div><span class="nex-muted"><?= count($services) ?> displayed</span></div>
  <form class="nex-filters" method="get" action="services.php" role="search">
    <label class="nex-field nex-search"><span>Search services</span><input type="search" name="q" maxlength="90" value="<?= h($search) ?>" placeholder="Service name or description"></label>
    <label class="nex-field"><span>Category</span><select name="category"><option value="all">All categories</option>
      <?php foreach ($categories as $key=>$title): ?><option value="<?= h($key) ?>" <?= $filter===$key?'selected':'' ?>><?= h($title) ?></option><?php endforeach; ?>
    </select></label>
    <button class="nex-btn nex-btn-outline" type="submit"><i class="bi bi-search"></i> Search</button><a class="nex-text-link" href="services.php">Reset</a>
  </form>
  <div class="nex-table-wrap"><table class="nex-table"><thead><tr><th>Service</th><th>Category</th><th>Status</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($services as $row): $cat=strtolower(trim((string)($row['category']??''))); ?>
    <tr><td><strong><?= h((string)$row['service_name']) ?></strong><?php if ($row['description']): ?><small><?= h((string)$row['description']) ?></small><?php endif; ?></td>
      <td><?= h($categories[$cat] ?? 'Uncategorised — edit') ?></td>
      <td><span class="nex-pill <?= $row['record_status']==='active'?'nex-pill-active':'nex-pill-inactive' ?>"><?= h(ucfirst((string)$row['record_status'])) ?></span></td>
      <td><a class="nex-table-link" href="services.php?edit=<?= (int)$row['service_id'] ?>#serviceForm">Edit</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$services): ?><tr><td colspan="4" class="nex-empty">No services found. Add a service or change the filters.</td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<?php nexShellEnd(); ?>
