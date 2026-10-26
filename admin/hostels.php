<?php
/**
 * admin/hostels.php - Manage hostel blocks (create, edit, activate/deactivate).
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $code  = strtoupper(trim((string)($_POST['block_code'] ?? '')));
        $loc   = trim((string)($_POST['location'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));
        $fac   = trim((string)($_POST['facilities'] ?? ''));
        $ward  = trim((string)($_POST['warden_name'] ?? ''));
        $wph   = trim((string)($_POST['warden_phone'] ?? ''));
        $gp    = safe_choice($_POST['gender_policy'] ?? 'mixed', ['male', 'female', 'mixed'], 'mixed');
        $act   = empty($_POST['is_active']) ? 0 : 1;

        $err = '';
        if (mb_strlen($name) < 3)          $err = 'Enter the hostel name.';
        elseif (!preg_match('/^[A-Z0-9\-]{2,20}$/', $code)) $err = 'Block code must be 2-20 characters (letters, numbers, hyphen).';
        else {
            $dup = db_one($conn, 'SELECT id FROM hostels WHERE block_code = ? AND id <> ?', 'si', [$code, $id]);
            if ($dup) $err = 'Block code ' . $code . ' is already used.';
        }

        if ($err) {
            flash_set('danger', $err);
        } elseif ($id > 0) {
            db_exec($conn, 'UPDATE hostels SET name=?, block_code=?, location=?, description=?, facilities=?, warden_name=?, warden_phone=?, gender_policy=?, is_active=? WHERE id=?',
                'ssssssssii', [$name, $code, $loc ?: null, $desc ?: null, $fac ?: null, $ward ?: null, $wph ?: null, $gp, $act, $id]);
            flash_set('success', 'Hostel "' . $name . '" updated.');
        } else {
            db_exec($conn, 'INSERT INTO hostels (name, block_code, location, description, facilities, warden_name, warden_phone, gender_policy, is_active) VALUES (?,?,?,?,?,?,?,?,?)',
                'ssssssssi', [$name, $code, $loc ?: null, $desc ?: null, $fac ?: null, $ward ?: null, $wph ?: null, $gp, $act]);
            flash_set('success', 'Hostel "' . $name . '" created.');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        db_exec($conn, 'UPDATE hostels SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
        flash_set('success', 'Hostel visibility updated.');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $rooms = (int)(db_one($conn, 'SELECT COUNT(*) c FROM rooms WHERE hostel_id = ?', 'i', [$id])['c'] ?? 0);
        if ($rooms > 0) {
            flash_set('danger', 'This block still has ' . $rooms . ' room(s). Deactivate it instead of deleting.');
        } else {
            db_exec($conn, 'DELETE FROM hostels WHERE id = ?', 'i', [$id]);
            flash_set('success', 'Hostel deleted.');
        }
    }
    redirect('admin/hostels.php');
}

$hostels = db_all($conn, 'SELECT * FROM v_hostel_occupancy ORDER BY block_code');
$editing = get_int('edit') ? db_one($conn, 'SELECT * FROM hostels WHERE id = ?', 'i', [get_int('edit')]) : null;

page_header('Hostels', 'hostels', count($hostels) . ' hostel blocks', [
    ['label' => 'New hostel', 'href' => 'hostels.php?new=1', 'class' => '', 'icon' => 'add'],
]);
?>

<div class="grid" style="grid-template-columns:<?= (get_int('new') || $editing) ? 'minmax(0,420px) minmax(0,1fr)' : 'minmax(0,1fr)' ?>;gap:24px;align-items:start">

<?php if (get_int('new') || $editing): ?>
  <div class="card card-pad-lg" style="position:sticky;top:96px">
    <div class="card-header">
      <div>
        <h3 class="card-title"><?= $editing ? 'Edit ' . e($editing['name']) : 'New hostel block' ?></h3>
        <p class="card-sub">Facilities are comma separated.</p>
      </div>
      <a class="icon-btn" href="hostels.php" aria-label="Close"><?= icon('close') ?></a>
    </div>
    <form method="post" action="hostels.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="stack">
        <div class="field">
          <label class="field-label" for="name">Hostel name <span class="req">*</span></label>
          <input class="input" id="name" name="name" value="<?= e((string)($editing['name'] ?? '')) ?>" placeholder="e.g. Narra Hall" required>
        </div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:14px">
          <div class="field">
            <label class="field-label" for="block_code">Block code <span class="req">*</span></label>
            <input class="input" id="block_code" name="block_code" value="<?= e((string)($editing['block_code'] ?? '')) ?>" placeholder="BLK-A" required>
          </div>
          <div class="field">
            <label class="field-label" for="gender_policy">Gender policy</label>
            <select class="select" id="gender_policy" name="gender_policy">
              <?php foreach (['mixed' => 'Mixed', 'male' => 'Male only', 'female' => 'Female only'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= ($editing['gender_policy'] ?? 'mixed') === $v ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="location">Location</label>
          <input class="input" id="location" name="location" value="<?= e((string)($editing['location'] ?? '')) ?>" placeholder="North Campus, near the library">
        </div>
        <div class="field">
          <label class="field-label" for="description">Description</label>
          <textarea class="textarea" id="description" name="description" rows="3"><?= e((string)($editing['description'] ?? '')) ?></textarea>
        </div>
        <div class="field">
          <label class="field-label" for="facilities">Facilities</label>
          <input class="input" id="facilities" name="facilities" value="<?= e((string)($editing['facilities'] ?? '')) ?>" placeholder="Wi-Fi, Air Conditioning, 24/7 Security">
          <span class="help-text">Separate each facility with a comma.</span>
        </div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:14px">
          <div class="field">
            <label class="field-label" for="warden_name">Warden name</label>
            <input class="input" id="warden_name" name="warden_name" value="<?= e((string)($editing['warden_name'] ?? '')) ?>">
          </div>
          <div class="field">
            <label class="field-label" for="warden_phone">Warden phone</label>
            <input class="input" id="warden_phone" name="warden_phone" value="<?= e((string)($editing['warden_phone'] ?? '')) ?>">
          </div>
        </div>
        <div class="switch-row">
          <div>
            <div class="label-m">Accepting applications</div>
            <div class="tiny">Inactive blocks are hidden from students.</div>
          </div>
          <label class="switch">
            <input type="checkbox" name="is_active" value="1" <?= ($editing['is_active'] ?? 1) ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-thumb"></span></span>
          </label>
        </div>
        <button type="submit" class="btn btn-block"><?= icon('check') ?> <?= $editing ? 'Save changes' : 'Create hostel' ?></button>
        <a class="btn btn-text btn-block" href="hostels.php">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="grid g-2">
  <?php foreach ($hostels as $i => $h):
      $full = db_one($conn, 'SELECT * FROM hostels WHERE id = ?', 'i', [(int)$h['id']]);
      $rate = (float)$h['occupancy_rate'];
  ?>
    <article class="card hostel-card card-hover anim-rise anim-rise-<?= ($i % 4) + 1 ?>">
      <div class="hostel-visual" style="height:110px">
        <span class="glow" aria-hidden="true"></span>
        <?= icon('apartment', 'icon icon-lg') ?>
        <span class="chip chip-primary hostel-code"><?= e($h['block_code']) ?></span>
        <span class="chip <?= $full['is_active'] ? 'chip-success' : 'chip-neutral' ?>" style="position:absolute;top:14px;right:14px">
          <span class="chip-dot"></span><?= $full['is_active'] ? 'Active' : 'Inactive' ?>
        </span>
      </div>
      <div class="hostel-body">
        <div class="row-between wrap" style="gap:8px">
          <div>
            <h3 class="title-m mb-0"><?= e($h['name']) ?></h3>
            <p class="tiny mb-0"><?= e($full['location'] ?: '—') ?> &middot; <?= e(ucfirst($h['gender_policy'])) ?></p>
          </div>
          <span class="chip chip-outline"><?= e(ucfirst($h['gender_policy'])) ?></span>
        </div>

        <div class="grid g-4" style="gap:8px;text-align:center">
          <div><div class="title-m mb-0"><?= (int)$h['total_rooms'] ?></div><div class="tiny">Rooms</div></div>
          <div><div class="title-m mb-0"><?= (int)$h['total_beds'] ?></div><div class="tiny">Beds</div></div>
          <div><div class="title-m mb-0"><?= (int)$h['occupied_beds'] ?></div><div class="tiny">Occupied</div></div>
          <div><div class="title-m mb-0"><?= (int)$h['vacant_beds'] ?></div><div class="tiny">Vacant</div></div>
        </div>

        <div>
          <div class="row-between mb-1"><span class="label-m">Occupancy</span><span class="tiny"><?= $rate ?>%</span></div>
          <?= occupancy_bar((int)$h['occupied_beds'], max(1, (int)$h['total_beds'])) ?>
        </div>

        <div class="row wrap" style="gap:5px">
          <?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string)$full['facilities']))), 0, 4) as $f): ?>
            <span class="chip chip-outline"><?= icon_for_facility($f) ?><?= e($f) ?></span>
          <?php endforeach; ?>
        </div>

        <div class="tiny">Warden: <strong><?= e($full['warden_name'] ?: '—') ?></strong> &middot; <?= e($full['warden_phone'] ?: '—') ?></div>

        <div class="row wrap mt-1" style="gap:8px">
          <a class="btn btn-sm btn-tonal" href="rooms.php?hostel=<?= (int)$h['id'] ?>"><?= icon('layers', 'icon icon-sm') ?> Rooms</a>
          <a class="btn btn-sm btn-outlined" href="hostels.php?edit=<?= (int)$h['id'] ?>"><?= icon('edit', 'icon icon-sm') ?> Edit</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
            <button class="btn btn-sm btn-text"><?= icon($full['is_active'] ? 'visibility' : 'lock', 'icon icon-sm') ?> <?= $full['is_active'] ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <span class="grow"></span>
          <form method="post" style="display:inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
            <button class="btn btn-sm btn-text" style="--btn-fg:var(--md-error)" data-confirm="Delete <?= e($h['name']) ?>? Only possible when it has no rooms."><?= icon('delete', 'icon icon-sm') ?></button>
          </form>
        </div>
      </div>
    </article>
  <?php endforeach; ?>

  <?php if (!$hostels): ?>
    <div class="card" style="grid-column:1/-1"><div class="empty">
      <div class="empty-icon"><?= icon('apartment') ?></div>
      <h3 class="title-m mb-0">No hostel blocks yet</h3>
      <p class="tiny mb-2">Create your first hostel block to start accepting applications.</p>
      <a class="btn" href="hostels.php?new=1"><?= icon('add') ?> New hostel</a>
    </div></div>
  <?php endif; ?>
</div>
</div>

<?php page_footer(); ?>
