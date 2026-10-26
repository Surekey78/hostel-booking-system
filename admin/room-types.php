<?php
/**
 * admin/room-types.php - Manage room types (capacity, price, facilities).
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim((string)($_POST['name'] ?? ''));
        $cap   = max(1, min(12, (int)($_POST['capacity'] ?? 1)));
        $price = (float)($_POST['price'] ?? 0);
        $fac   = trim((string)($_POST['facilities'] ?? ''));
        $desc  = trim((string)($_POST['description'] ?? ''));

        $err = '';
        if (mb_strlen($name) < 3) $err = 'Enter a room type name.';
        elseif ($price < 0)       $err = 'Price cannot be negative.';
        else {
            $dup = db_one($conn, 'SELECT id FROM room_types WHERE name = ? AND id <> ?', 'si', [$name, $id]);
            if ($dup) $err = 'A room type named "' . $name . '" already exists.';
        }

        if ($err) {
            flash_set('danger', $err);
        } elseif ($id > 0) {
            db_exec($conn, 'UPDATE room_types SET name=?, capacity=?, price=?, facilities=?, description=? WHERE id=?', 'sidsdi', [$name, $cap, $price, $fac ?: null, $desc ?: null, $id]);
            flash_set('success', 'Room type "' . $name . '" updated.');
        } else {
            db_exec($conn, 'INSERT INTO room_types (name, capacity, price, facilities, description) VALUES (?,?,?,?,?)', 'sidsd', [$name, $cap, $price, $fac ?: null, $desc ?: null]);
            flash_set('success', 'Room type "' . $name . '" created.');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)(db_one($conn, 'SELECT COUNT(*) c FROM rooms WHERE room_type_id = ?', 'i', [$id])['c'] ?? 0);
        if ($used > 0) {
            flash_set('danger', 'This room type is used by ' . $used . ' room(s) and cannot be deleted.');
        } else {
            db_exec($conn, 'DELETE FROM room_types WHERE id = ?', 'i', [$id]);
            flash_set('success', 'Room type deleted.');
        }
    }
    redirect('admin/room-types.php');
}

$types = db_all(
    $conn,
    "SELECT rt.*, COUNT(r.id) rooms, COALESCE(SUM(r.occupied),0) occupied,
            COALESCE(SUM(rt.capacity),0) beds, COALESCE(SUM(rt.capacity - r.occupied),0) vacant
       FROM room_types rt LEFT JOIN rooms r ON r.room_type_id = rt.id
      GROUP BY rt.id ORDER BY rt.capacity, rt.price"
);
$editing = get_int('edit') ? db_one($conn, 'SELECT * FROM room_types WHERE id = ?', 'i', [get_int('edit')]) : null;

page_header('Room Types', 'room-types', 'Capacity, pricing and facilities per category', [
    ['label' => 'New room type', 'href' => 'room-types.php?new=1', 'class' => '', 'icon' => 'add'],
]);
?>

<div class="grid" style="grid-template-columns:<?= (get_int('new') || $editing) ? 'minmax(0,400px) minmax(0,1fr)' : 'minmax(0,1fr)' ?>;gap:24px;align-items:start">

<?php if (get_int('new') || $editing): ?>
  <div class="card card-pad-lg" style="position:sticky;top:96px">
    <div class="card-header">
      <div>
        <h3 class="card-title"><?= $editing ? 'Edit ' . e($editing['name']) : 'New room type' ?></h3>
        <p class="card-sub">Students see this when applying.</p>
      </div>
      <a class="icon-btn" href="room-types.php" aria-label="Close"><?= icon('close') ?></a>
    </div>
    <form method="post" action="room-types.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div class="stack">
        <div class="field">
          <label class="field-label" for="name">Name <span class="req">*</span></label>
          <input class="input" id="name" name="name" value="<?= e((string)($editing['name'] ?? '')) ?>" placeholder="e.g. Twin Sharing" required>
        </div>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;gap:14px">
          <div class="field">
            <label class="field-label" for="capacity">Beds per room <span class="req">*</span></label>
            <input class="input" type="number" id="capacity" name="capacity" min="1" max="12" value="<?= (int)($editing['capacity'] ?? 2) ?>" required>
          </div>
          <div class="field">
            <label class="field-label" for="price">Price per semester <span class="req">*</span></label>
            <input class="input" type="number" id="price" name="price" min="0" step="0.01" value="<?= e((string)($editing['price'] ?? '')) ?>" placeholder="0.00" required>
          </div>
        </div>
        <div class="field">
          <label class="field-label" for="description">Description</label>
          <textarea class="textarea" id="description" name="description" rows="2" style="min-height:80px"><?= e((string)($editing['description'] ?? '')) ?></textarea>
        </div>
        <div class="field">
          <label class="field-label" for="facilities">Facilities</label>
          <input class="input" id="facilities" name="facilities" value="<?= e((string)($editing['facilities'] ?? '')) ?>" placeholder="Air Conditioning, Study Desk, Wi-Fi">
          <span class="help-text">Comma separated. Icons are matched automatically.</span>
        </div>
        <button type="submit" class="btn btn-block"><?= icon('check') ?> <?= $editing ? 'Save changes' : 'Create room type' ?></button>
        <a class="btn btn-text btn-block" href="room-types.php">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="grid g-2">
  <?php foreach ($types as $i => $t):
      $occ = (int)$t['beds'] > 0 ? round(((int)$t['occupied'] / (int)$t['beds']) * 100, 1) : 0;
  ?>
    <article class="card card-hover anim-rise anim-rise-<?= ($i % 4) + 1 ?>">
      <div class="card-header">
        <div>
          <h3 class="title-m mb-0"><?= e($t['name']) ?></h3>
          <p class="card-sub"><?= (int)$t['capacity'] ?> bed<?= (int)$t['capacity'] === 1 ? '' : 's' ?> per room &middot; <?= money($t['price']) ?> / semester</p>
        </div>
        <span class="chip <?= (int)$t['vacant'] > 0 ? 'chip-success' : 'chip-danger' ?>"><?= (int)$t['vacant'] ?> beds free</span>
      </div>

      <p class="tiny mb-2"><?= e($t['description'] ?: '') ?></p>
      <div class="row wrap mb-2" style="gap:5px"><?= facility_chips($t['facilities']) ?></div>

      <div class="grid g-4 mb-2" style="gap:8px;text-align:center">
        <div><div class="title-m mb-0"><?= (int)$t['rooms'] ?></div><div class="tiny">Rooms</div></div>
        <div><div class="title-m mb-0"><?= (int)$t['beds'] ?></div><div class="tiny">Beds</div></div>
        <div><div class="title-m mb-0"><?= (int)$t['occupied'] ?></div><div class="tiny">Occupied</div></div>
        <div><div class="title-m mb-0"><?= $occ ?>%</div><div class="tiny">Utilisation</div></div>
      </div>
      <?= occupancy_bar((int)$t['occupied'], max(1, (int)$t['beds'])) ?>

      <div class="row wrap mt-2" style="gap:8px">
        <a class="btn btn-sm btn-tonal" href="rooms.php?type=<?= (int)$t['id'] ?>"><?= icon('layers', 'icon icon-sm') ?> Rooms</a>
        <a class="btn btn-sm btn-outlined" href="room-types.php?edit=<?= (int)$t['id'] ?>"><?= icon('edit', 'icon icon-sm') ?> Edit</a>
        <a class="btn btn-sm btn-text" href="applications.php?type=<?= (int)$t['id'] ?>"><?= icon('assignment', 'icon icon-sm') ?> Applications</a>
        <span class="grow"></span>
        <form method="post" style="display:inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-sm btn-text" style="--btn-fg:var(--md-error)" data-confirm="Delete room type <?= e($t['name']) ?>?"><?= icon('delete', 'icon icon-sm') ?></button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>

  <?php if (!$types): ?>
    <div class="card" style="grid-column:1/-1"><div class="empty">
      <div class="empty-icon"><?= icon('money') ?></div>
      <h3 class="title-m mb-0">No room types defined</h3>
      <p class="tiny mb-2">Create room categories before adding rooms.</p>
      <a class="btn" href="room-types.php?new=1"><?= icon('add') ?> New room type</a>
    </div></div>
  <?php endif; ?>
</div>
</div>

<?php page_footer(); ?>
