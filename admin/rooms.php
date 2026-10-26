<?php
/**
 * admin/rooms.php - Room inventory: add, edit, change status, delete, floor plan view.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

// ---------------- Actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $typeId   = (int)($_POST['room_type_id'] ?? 0);
        $number   = trim((string)($_POST['room_number'] ?? ''));
        $floor    = max(1, min(30, (int)($_POST['floor'] ?? 1)));
        $status   = safe_choice($_POST['status'] ?? 'available', ['available', 'full', 'maintenance', 'blocked'], 'available');

        $err = '';
        if (!$hostelId) $err = 'Choose a hostel block.';
        elseif (!$typeId) $err = 'Choose a room type.';
        elseif ($number === '') $err = 'Enter a room number.';
        else {
            $dup = db_one($conn, 'SELECT id FROM rooms WHERE hostel_id=? AND room_number=? AND id<>?', 'isi', [$hostelId, $number, $id]);
            if ($dup) $err = 'Room ' . $number . ' already exists in that block.';
        }
        if ($status === 'available' && $id > 0) {
            $cur = db_one($conn, 'SELECT r.occupied, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=?', 'i', [$id]);
            if ($cur && (int)$cur['occupied'] >= (int)$cur['capacity'] && $typeId === (int)(db_one($conn, 'SELECT room_type_id FROM rooms WHERE id=?', 'i', [$id])['room_type_id'] ?? 0)) {
                $status = 'full';
            }
        }

        if ($err) {
            flash_set('danger', $err);
        } elseif ($id > 0) {
            db_exec($conn, 'UPDATE rooms SET hostel_id=?, room_type_id=?, room_number=?, floor=?, status=? WHERE id=?', 'iisssi', [$hostelId, $typeId, $number, $floor, $status, $id]);
            flash_set('success', 'Room ' . $number . ' updated.');
        } else {
            db_exec($conn, 'INSERT INTO rooms (hostel_id, room_type_id, room_number, floor, occupied, status) VALUES (?,?,?,?,0,?)', 'iisis', [$hostelId, $typeId, $number, $floor, $status]);
            flash_set('success', 'Room ' . $number . ' added.');
        }
    }

    if ($action === 'bulk_status') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        $status = safe_choice($_POST['status'] ?? '', ['available', 'maintenance', 'blocked'], '');
        if (!$ids || !$status) {
            flash_set('warning', 'Select rooms and a new status.');
        } else {
            $n = 0;
            foreach ($ids as $rid) {
                $r = db_one($conn, 'SELECT r.occupied, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=?', 'i', [$rid]);
                if (!$r) continue;
                $final = $status === 'available' ? ((int)$r['occupied'] >= (int)$r['capacity'] ? 'full' : 'available') : $status;
                db_exec($conn, 'UPDATE rooms SET status=? WHERE id=?', 'si', [$final, $rid]);
                $n++;
            }
            flash_set('success', $n . ' room(s) updated to "' . $status . '".');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $r  = db_one($conn, 'SELECT * FROM rooms WHERE id=?', 'i', [$id]);
        if (!$r) {
            flash_set('danger', 'Room not found.');
        } elseif ((int)$r['occupied'] > 0) {
            flash_set('danger', 'Room ' . $r['room_number'] . ' still has occupants. Check them out first.');
        } else {
            db_exec($conn, 'DELETE FROM rooms WHERE id=?', 'i', [$id]);
            flash_set('success', 'Room ' . $r['room_number'] . ' deleted.');
        }
    }
    redirect('admin/rooms.php');
}

// ---------------- Filters ----------------
$hid    = get_int('hostel');
$tid    = get_int('type');
$status = safe_choice(get_str('status'), ['', 'available', 'full', 'maintenance', 'blocked'], '');
$floor  = get_int('floor');
$q      = get_str('q');
$view   = safe_choice(get_str('view'), ['table', 'plan'], 'table');

$where  = ['1=1'];
$types  = '';
$params = [];
if ($hid > 0)    { $where[] = 'r.hostel_id = ?';    $params[] = $hid;    $types .= 'i'; }
if ($tid > 0)    { $where[] = 'r.room_type_id = ?'; $params[] = $tid;    $types .= 'i'; }
if ($floor > 0)  { $where[] = 'r.floor = ?';        $params[] = $floor;  $types .= 'i'; }
if ($status !== '') { $where[] = 'r.status = ?';    $params[] = $status; $types .= 's'; }
if ($q !== '') {
    $where[] = '(r.room_number LIKE ? OR h.name LIKE ? OR h.block_code LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
$whereSql = implode(' AND ', $where);

$rooms = db_all(
    $conn,
    "SELECT r.*, h.name hostel_name, h.block_code, h.gender_policy,
            rt.name type_name, rt.capacity, rt.price, (rt.capacity - r.occupied) vacancy
       FROM rooms r
       JOIN hostels h     ON h.id  = r.hostel_id
       JOIN room_types rt ON rt.id = r.room_type_id
      WHERE $whereSql
      ORDER BY h.block_code, rt.capacity, r.floor, LENGTH(r.room_number), r.room_number
      LIMIT 600",
    $types,
    $params
);

$kpi = db_one(
    $conn,
    "SELECT COUNT(*) rooms,
            COALESCE(SUM(rt.capacity),0) beds,
            COALESCE(SUM(r.occupied),0) occupied,
            SUM(r.status='available') available_rooms,
            SUM(r.status='full') full_rooms,
            SUM(r.status='maintenance') maintenance_rooms,
            SUM(r.status='blocked') blocked_rooms
       FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id"
);

$editing = null;
if (get_int('edit') > 0) {
    $editing = db_one($conn, 'SELECT * FROM rooms WHERE id = ?', 'i', [get_int('edit')]);
}

page_header('Rooms & Blocks', 'rooms', 'Room inventory, status and floor plans', [
    ['label' => 'Add room', 'href' => 'rooms.php?new=1', 'class' => '', 'icon' => 'add'],
    ['label' => ($view === 'table' ? 'Floor plan' : 'Table view'), 'href' => 'rooms.php?view=' . ($view === 'table' ? 'plan' : 'table'), 'class' => 'btn-outlined', 'icon' => 'layers'],
]);
?>

<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['layers', 'tone-1', (int)$kpi['rooms'], 'Total rooms'],
      ['bed', 'tone-3', number_format((int)$kpi['beds'] - (int)$kpi['occupied']), 'Vacant beds'],
      ['check_circle', 'tone-4', (int)$kpi['available_rooms'], 'Rooms available'],
      ['settings', 'tone-5', (int)$kpi['maintenance_rooms'], 'Under maintenance'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $v, $l]): ?>
    <div class="card anim-rise anim-rise-<?= $i + 1 ?>"><div class="stat">
      <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
      <span class="stat-value"><?= e((string)$v) ?></span><span class="stat-label"><?= e($l) ?></span>
    </div></div>
  <?php endforeach; ?>
</div>

<div class="grid" style="grid-template-columns:<?= (get_int('new') || $editing) ? 'minmax(0,380px) minmax(0,1fr)' : 'minmax(0,1fr)' ?>;gap:24px;align-items:start">

<?php if (get_int('new') || $editing): ?>
  <!-- Add / edit form -->
  <div class="card card-pad-lg" style="position:sticky;top:96px">
    <div class="card-header">
      <div>
        <h3 class="card-title"><?= $editing ? 'Edit room ' . e($editing['room_number']) : 'Add a room' ?></h3>
        <p class="card-sub"><?= $editing ? 'Update the room record' : 'Register a new room in the inventory' ?></p>
      </div>
      <a class="icon-btn" href="rooms.php" aria-label="Close form"><?= icon('close') ?></a>
    </div>
    <form method="post" action="rooms.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="stack">
        <div class="field">
          <label class="field-label" for="hostel_id">Hostel block <span class="req">*</span></label>
          <select class="select" id="hostel_id" name="hostel_id" required>
            <option value="">Select&hellip;</option>
            <?php foreach (hostel_options() as $h): ?>
              <option value="<?= (int)$h['id'] ?>" <?= (int)($editing['hostel_id'] ?? $hid) === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['block_code']) ?> — <?= e($h['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="room_type_id">Room type <span class="req">*</span></label>
          <select class="select" id="room_type_id" name="room_type_id" required>
            <option value="">Select&hellip;</option>
            <?php foreach (room_type_options() as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= (int)($editing['room_type_id'] ?? $tid) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> — <?= (int)$t['capacity'] ?> bed(s), <?= money($t['price']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:14px">
          <div class="field">
            <label class="field-label" for="room_number">Room number <span class="req">*</span></label>
            <input class="input" id="room_number" name="room_number" value="<?= e((string)($editing['room_number'] ?? '')) ?>" placeholder="e.g. 204" required>
          </div>
          <div class="field">
            <label class="field-label" for="floor">Floor</label>
            <input class="input" type="number" id="floor" name="floor" min="1" max="30" value="<?= (int)($editing['floor'] ?? 1) ?>">
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="status">Status</label>
          <select class="select" id="status" name="status">
            <?php foreach (['available' => 'Available', 'full' => 'Full', 'maintenance' => 'Under maintenance', 'blocked' => 'Blocked'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= ($editing['status'] ?? 'available') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($editing): ?><span class="help-text">Occupied beds: <strong><?= (int)$editing['occupied'] ?></strong>. Occupancy is managed through assignments.</span><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-block"><?= icon('check') ?> <?= $editing ? 'Save changes' : 'Add room' ?></button>
        <a class="btn btn-text btn-block" href="rooms.php">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="stack">
  <!-- Filters -->
  <form method="get" action="rooms.php" class="card">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="filter-bar">
      <div class="field" style="grid-column:span 2">
        <label class="field-label" for="q">Search</label>
        <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Room number or block&hellip;">
      </div>
      <div class="field">
        <label class="field-label" for="f-hostel">Hostel block</label>
        <select class="select" id="f-hostel" name="hostel">
          <option value="">All blocks</option>
          <?php foreach (hostel_options() as $h): ?>
            <option value="<?= (int)$h['id'] ?>" <?= $hid === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['block_code']) ?> — <?= e($h['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="f-type">Room type</label>
        <select class="select" id="f-type" name="type">
          <option value="">All types</option>
          <?php foreach (room_type_options() as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= $tid === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="f-status">Status</label>
        <select class="select" id="f-status" name="status">
          <option value="">Any status</option>
          <?php foreach (['available' => 'Available', 'full' => 'Full', 'maintenance' => 'Maintenance', 'blocked' => 'Blocked'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="f-floor">Floor</label>
        <input class="input" type="number" id="f-floor" name="floor" min="1" value="<?= $floor ?: '' ?>" placeholder="Any">
      </div>
    </div>
    <div class="row wrap mt-2" style="gap:8px">
      <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Filter</button>
      <a class="btn btn-text" href="rooms.php">Reset</a>
      <span class="grow"></span>
      <span class="tiny"><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?></span>
    </div>
  </form>

  <?php if (!$rooms): ?>
    <div class="card"><div class="empty">
      <div class="empty-icon"><?= icon('layers') ?></div>
      <h3 class="title-m mb-0">No rooms found</h3>
      <p class="tiny mb-2">Adjust the filters or add a new room.</p>
      <a class="btn" href="rooms.php?new=1"><?= icon('add') ?> Add room</a>
    </div></div>

  <?php elseif ($view === 'plan'): ?>
    <!-- Floor plan view grouped by block + floor -->
    <?php
    $grouped = [];
    foreach ($rooms as $r) {
        $grouped[$r['block_code'] . ' — ' . $r['hostel_name']][(int)$r['floor']][] = $r;
    }
    foreach ($grouped as $block => $floors): ?>
      <div class="card">
        <div class="card-header">
          <div><h3 class="card-title"><?= e($block) ?></h3><p class="card-sub"><?= count($floors) ?> floor<?= count($floors) === 1 ? '' : 's' ?></p></div>
        </div>
        <?php ksort($floors); foreach ($floors as $fl => $list): ?>
          <div class="mb-2">
            <div class="label-s mb-1" style="color:var(--md-primary)">Floor <?= (int)$fl ?></div>
            <div class="row wrap" style="gap:8px">
              <?php foreach ($list as $r):
                  $tone = $r['status'] === 'maintenance' ? 'chip-warning' : ($r['status'] === 'blocked' ? 'chip-neutral' : ((int)$r['vacancy'] > 0 ? 'chip-success' : 'chip-danger')); ?>
                <a class="room-card" href="rooms.php?edit=<?= (int)$r['id'] ?>" style="width:132px;padding:14px;text-decoration:none;color:inherit;gap:6px">
                  <div class="row-between"><span class="room-number" style="font-size:1.125rem"><?= e($r['room_number']) ?></span></div>
                  <div class="beds">
                    <?php for ($b = 1; $b <= (int)$r['capacity']; $b++): ?>
                      <span class="bed<?= $b <= (int)$r['occupied'] ? ' taken' : '' ?>"></span>
                    <?php endfor; ?>
                  </div>
                  <span class="chip <?= $tone ?>" style="height:24px;font-size:.6875rem"><span class="chip-dot"></span><?= (int)$r['vacancy'] > 0 ? (int)$r['vacancy'] . ' free' : e(ucfirst($r['status'])) ?></span>
                  <span class="tiny" style="font-size:.6875rem"><?= e($r['type_name']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  <?php else: ?>
    <!-- Table view with bulk actions -->
    <form method="post" action="rooms.php" id="rooms-form">
      <?= csrf_field() ?>
      <div class="card mb-2" style="padding:14px 18px">
        <div class="row-between wrap" style="gap:12px">
          <label class="check" style="padding:0">
            <input type="checkbox" id="check-all-rooms"><span class="check-box"><?= icon('check') ?></span>
            <span class="check-text">Select all</span>
          </label>
          <div class="row wrap" style="gap:8px">
            <select class="select" name="status" style="min-height:40px;width:auto;padding-right:40px" aria-label="New status">
              <option value="">Set status to&hellip;</option>
              <option value="available">Available</option>
              <option value="maintenance">Under maintenance</option>
              <option value="blocked">Blocked</option>
            </select>
            <button type="submit" name="action" value="bulk_status" class="btn btn-tonal btn-sm"><?= icon('settings', 'icon icon-sm') ?> Apply</button>
          </div>
        </div>
      </div>

      <div class="card" style="padding:0;overflow:hidden">
        <div class="table-wrap">
          <table class="data" style="min-width:1020px">
            <thead>
              <tr>
                <th style="width:44px"></th><th>Room</th><th>Block</th><th>Type</th><th>Floor</th>
                <th>Occupancy</th><th class="num">Rate</th><th>Status</th><th style="text-align:right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rooms as $r): ?>
                <tr>
                  <td>
                    <label class="check" style="padding:0">
                      <input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="room-check">
                      <span class="check-box"><?= icon('check') ?></span><span class="sr-only">Select room <?= e($r['room_number']) ?></span>
                    </label>
                  </td>
                  <td class="cell-strong"><?= e($r['room_number']) ?></td>
                  <td><?= e($r['block_code']) ?><div class="cell-sub"><?= e($r['hostel_name']) ?></div></td>
                  <td><?= e($r['type_name']) ?><div class="cell-sub"><?= (int)$r['capacity'] ?> bed<?= (int)$r['capacity'] === 1 ? '' : 's' ?></div></td>
                  <td><?= (int)$r['floor'] ?></td>
                  <td style="min-width:150px">
                    <div class="row-between mb-1" style="gap:8px">
                      <span class="tiny"><?= (int)$r['occupied'] ?>/<?= (int)$r['capacity'] ?></span>
                      <span class="tiny"><?= (int)$r['vacancy'] ?> free</span>
                    </div>
                    <?= occupancy_bar((int)$r['occupied'], (int)$r['capacity']) ?>
                  </td>
                  <td class="num"><?= money($r['price']) ?></td>
                  <td>
                    <?php
                    $chip = ['available' => 'chip-success', 'full' => 'chip-danger', 'maintenance' => 'chip-warning', 'blocked' => 'chip-neutral'][$r['status']] ?? 'chip-neutral';
                    ?>
                    <span class="chip <?= $chip ?>"><span class="chip-dot"></span><?= e(ucfirst($r['status'])) ?></span>
                  </td>
                  <td>
                    <div class="table-actions" style="justify-content:flex-end">
                      <a class="btn btn-sm btn-text" href="rooms.php?edit=<?= (int)$r['id'] ?>"><?= icon('edit', 'icon icon-sm') ?> Edit</a>
                      <button type="submit" name="action" value="delete" class="btn btn-sm btn-text" style="--btn-fg:var(--md-error)"
                              form="del-room-<?= (int)$r['id'] ?>"
                              data-confirm="Delete room <?= e($r['room_number']) ?> in <?= e($r['block_code']) ?>?"><?= icon('delete', 'icon icon-sm') ?></button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </form>

    <?php foreach ($rooms as $r): ?>
      <form method="post" action="rooms.php" id="del-room-<?= (int)$r['id'] ?>" style="display:none">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      </form>
    <?php endforeach; ?>

    <script>
    (function () {
      var all = document.getElementById('check-all-rooms');
      if (!all) return;
      all.addEventListener('change', function () {
        document.querySelectorAll('.room-check').forEach(function (c) { c.checked = all.checked; });
      });
    })();
    </script>
  <?php endif; ?>
</div>
</div>

<?php page_footer(); ?>
