<?php
/**
 * student/hostel-detail.php - Single hostel: description, facilities, rooms, vacancy.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_student();

$hostelId = get_int('id');
$hostel   = db_one($conn, 'SELECT * FROM hostels WHERE id = ? AND is_active = 1', 'i', [$hostelId]);

if (!$hostel) {
    flash_set('danger', 'That hostel block could not be found.');
    redirect('student/hostels.php');
}

$occ   = hostel_summary($hostelId);
$types = room_type_options();
$rooms = db_all(
    $conn,
    "SELECT r.*, rt.name type_name, rt.capacity, rt.price, rt.facilities type_facilities
       FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
      WHERE r.hostel_id = ?
      ORDER BY rt.capacity, r.floor, LENGTH(r.room_number), r.room_number",
    'i',
    [$hostelId]
);

$filterType = get_int('type');
$filterOnly = get_str('only');            // 'vacant'
if ($filterType > 0) {
    $rooms = array_values(array_filter($rooms, fn($r) => (int)$r['room_type_id'] === $filterType));
}
if ($filterOnly === 'vacant') {
    $rooms = array_values(array_filter($rooms, fn($r) => $r['status'] === 'available' && (int)$r['occupied'] < (int)$r['capacity']));
}

page_header(e($hostel['name']), 'hostels', e($hostel['block_code']) . ' &middot; ' . e($hostel['location']), [
    ['label' => 'Apply here', 'href' => 'apply.php?hostel=' . $hostelId, 'class' => '', 'icon' => 'add'],
]);
?>

<a class="btn btn-text btn-sm mb-2" href="hostels.php"><?= icon('arrow_back', 'icon icon-sm') ?> All hostels</a>

<section class="hero mb-3" style="background:linear-gradient(135deg,var(--md-primary-container),var(--md-tertiary-container))">
  <span class="blur-shape blur-a" aria-hidden="true"></span>
  <span class="blur-shape blur-c" aria-hidden="true"></span>
  <div class="row-between wrap" style="gap:24px">
    <div class="grow">
      <div class="row mb-1" style="gap:8px">
        <span class="chip chip-primary chip-lg"><?= e($hostel['block_code']) ?></span>
        <span class="chip chip-outline chip-lg"><?= e(ucfirst($hostel['gender_policy'])) ?> residence</span>
        <span class="chip <?= (int)$occ['vacant_beds'] > 0 ? 'chip-success' : 'chip-danger' ?> chip-lg">
          <span class="chip-dot"></span><?= (int)$occ['vacant_beds'] > 0 ? (int)$occ['vacant_beds'] . ' beds vacant' : 'Fully occupied' ?>
        </span>
      </div>
      <h2 class="headline-l mb-1"><?= e($hostel['name']) ?></h2>
      <p class="body-m muted mb-2" style="max-width:66ch"><?= e($hostel['description']) ?></p>
      <div class="row wrap" style="gap:6px"><?= facility_chips($hostel['facilities']) ?></div>
    </div>

    <div class="card card-elevated" style="min-width:250px;text-align:center">
      <div class="donut" style="background:conic-gradient(var(--md-primary) 0 <?= (float)$occ['occupancy_rate'] ?>%, var(--md-surface-container-low) <?= (float)$occ['occupancy_rate'] ?>% 100%);margin:0 auto 12px">
        <div class="donut-center">
          <b><?= (float)$occ['occupancy_rate'] ?>%</b>
          <span>Occupied</span>
        </div>
      </div>
      <div class="grid g-3" style="gap:8px">
        <div><div class="title-m mb-0"><?= (int)$occ['total_rooms'] ?></div><div class="tiny">Rooms</div></div>
        <div><div class="title-m mb-0"><?= (int)$occ['occupied_beds'] ?></div><div class="tiny">Occupied</div></div>
        <div><div class="title-m mb-0"><?= (int)$occ['vacant_beds'] ?></div><div class="tiny">Vacant</div></div>
      </div>
    </div>
  </div>
</section>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start">
  <!-- Rooms -->
  <div class="stack">
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Rooms in this block</h3>
          <p class="card-sub"><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?> shown</p>
        </div>
      </div>

      <form method="get" action="hostel-detail.php" class="filter-bar mb-2">
        <input type="hidden" name="id" value="<?= $hostelId ?>">
        <div class="field">
          <label class="field-label" for="type">Room type</label>
          <select class="select" id="type" name="type" data-auto-submit>
            <option value="">All types</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= $filterType === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= (int)$t['capacity'] ?> beds)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="only">Availability</label>
          <select class="select" id="only" name="only" data-auto-submit>
            <option value="">All rooms</option>
            <option value="vacant" <?= $filterOnly === 'vacant' ? 'selected' : '' ?>>Vacant only</option>
          </select>
        </div>
        <div class="row" style="gap:8px">
          <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Apply</button>
          <a class="btn btn-text" href="hostel-detail.php?id=<?= $hostelId ?>">Reset</a>
        </div>
      </form>

      <?php if (!$rooms): ?>
        <div class="empty">
          <div class="empty-icon"><?= icon('bed') ?></div>
          <p class="body-m mb-0">No rooms match this filter</p>
          <a class="btn btn-tonal btn-sm mt-1" href="hostel-detail.php?id=<?= $hostelId ?>">Clear filters</a>
        </div>
      <?php else: ?>
        <div class="grid g-3" style="gap:16px">
          <?php foreach ($rooms as $r):
              $cap = (int)$r['capacity'];
              $free = $cap - (int)$r['occupied'];
              $isVacant = $r['status'] === 'available' && $free > 0;
          ?>
            <div class="room-card<?= $isVacant ? '' : ' is-full' ?>">
              <div class="row-between">
                <span class="room-number"><?= e($r['room_number']) ?></span>
                <?php if ($r['status'] === 'maintenance'): ?>
                  <span class="chip chip-warning"><span class="chip-dot"></span>Maintenance</span>
                <?php elseif (!$isVacant): ?>
                  <span class="chip chip-neutral"><span class="chip-dot"></span>Full</span>
                <?php else: ?>
                  <span class="chip chip-success"><span class="chip-dot"></span><?= $free ?> free</span>
                <?php endif; ?>
              </div>
              <div class="tiny"><?= e($r['type_name']) ?> &middot; Floor <?= (int)$r['floor'] ?></div>
              <div class="row-between" style="gap:8px">
                <div class="beds" title="<?= (int)$r['occupied'] ?> of <?= $cap ?> beds taken">
                  <?php for ($b = 1; $b <= $cap; $b++): ?>
                    <span class="bed<?= $b <= (int)$r['occupied'] ? ' taken' : '' ?>"></span>
                  <?php endfor; ?>
                </div>
                <span class="label-m"><?= money($r['price']) ?></span>
              </div>
              <?= occupancy_bar((int)$r['occupied'], $cap) ?>
              <a class="btn btn-sm <?= $isVacant ? 'btn-tonal' : 'btn-outlined' ?>" <?= $isVacant ? '' : 'disabled aria-disabled="true"' ?>
                 href="apply.php?hostel=<?= $hostelId ?>&type=<?= (int)$r['room_type_id'] ?>&room=<?= (int)$r['id'] ?>">
                <?= $isVacant ? 'Apply for this room' : 'Unavailable' ?>
              </a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sidebar -->
  <div class="stack">
    <div class="card">
      <h3 class="card-title mb-2">Rates &amp; vacancy</h3>
      <?php foreach ($types as $t):
          $v = vacancy_for($hostelId, (int)$t['id']); ?>
        <div class="hbar-row" style="grid-template-columns:1fr auto">
          <div>
            <div class="label-m"><?= e($t['name']) ?></div>
            <div class="tiny"><?= (int)$t['capacity'] ?> bed<?= (int)$t['capacity'] === 1 ? '' : 's' ?> &middot; <?= money($t['price']) ?></div>
          </div>
          <span class="chip <?= $v > 0 ? 'chip-success' : 'chip-neutral' ?>"><?= $v > 0 ? $v . ' free' : 'Full' ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Hostel office</h3>
      <dl class="dl">
        <dt>Warden</dt><dd><?= e($hostel['warden_name'] ?: '—') ?></dd>
        <dt>Phone</dt><dd><?= e($hostel['warden_phone'] ?: '—') ?></dd>
        <dt>Location</dt><dd><?= e($hostel['location']) ?></dd>
        <dt>Policy</dt><dd><?= e(ucfirst($hostel['gender_policy'])) ?> only</dd>
      </dl>
    </div>

    <div class="card">
      <h3 class="card-title mb-1">Room facilities</h3>
      <?php foreach ($types as $t): ?>
        <div class="mb-2">
          <div class="label-m mb-1"><?= e($t['name']) ?></div>
          <div class="row wrap" style="gap:6px"><?= facility_chips($t['facilities']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php page_footer(); ?>
