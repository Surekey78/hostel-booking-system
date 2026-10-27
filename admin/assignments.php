<?php



require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $bid    = (int)($_POST['booking_id'] ?? 0);

    if ($action === 'release') {
        $res = release_assignment($bid);
        if ($res['ok']) {
            $b = db_one($conn, 'SELECT * FROM bookings WHERE id = ?', 'i', [$bid]);
            if ($b) {
                notify((int)$b['student_id'], 'Room assignment ended', 'Your room assignment for ' . $b['reference_no'] . ' was closed. The bed has been released.', 'warning', 'student/my-bookings.php');
            }
        }
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    if ($action === 'transfer') {
        $targetRoom = (int)($_POST['target_room_id'] ?? 0);
        $asg = db_one($conn, "SELECT * FROM assignments WHERE booking_id = ? AND status='active'", 'i', [$bid]);
        if (!$asg || !$targetRoom) {
            flash_set('danger', 'Transfer failed: assignment or target room not found.');
        } elseif ((int)$asg['room_id'] === $targetRoom) {
            flash_set('warning', 'The student is already in that room.');
        } else {
            $target = db_one($conn, "SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=?", 'i', [$targetRoom]);
            if (!$target || $target['status'] !== 'available' || (int)$target['occupied'] >= (int)$target['capacity']) {
                flash_set('danger', 'The target room is not vacant.');
            } else {
                mysqli_begin_transaction($conn);
                try {
                    
                    $old = db_one($conn, "SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=?", 'i', [(int)$asg['room_id']]);
                    $oldOcc = max(0, (int)$old['occupied'] - 1);
                    $oldStatus = $oldOcc >= (int)$old['capacity'] ? 'full' : 'available';
                    db_exec($conn, 'UPDATE rooms SET occupied=?, status=? WHERE id=?', 'isi', [$oldOcc, $oldStatus, (int)$old['id']]);
                    db_exec($conn, "UPDATE assignments SET status='transferred', checkout_date=CURDATE() WHERE id=?", 'i', [(int)$asg['id']]);

                    
                    $newOcc = (int)$target['occupied'] + 1;
                    $newStatus = $newOcc >= (int)$target['capacity'] ? 'full' : 'available';
                    db_exec($conn, 'UPDATE rooms SET occupied=?, status=? WHERE id=?', 'isi', [$newOcc, $newStatus, $targetRoom]);

                    db_exec(
                        $conn,
                        "INSERT INTO assignments (booking_id, room_id, bed_number, student_id, assigned_by, status, checkin_date)
                         VALUES (?,?,?,?,?, 'active', CURDATE())",
                        'iiiii',
                        [$bid, $targetRoom, $newOcc, (int)$asg['student_id'], (int)$me['id']]
                    );
                    mysqli_commit($conn);

                    $bk = db_one($conn, 'SELECT * FROM bookings WHERE id=?', 'i', [$bid]);
                    notify((int)$asg['student_id'], 'Room transferred', 'You have been moved to Room ' . $target['room_number'] . ' (Bed ' . $newOcc . '). Please collect a new key from the hostel office.', 'success', 'student/my-assignment.php');
                    flash_set('success', 'Student transferred to Room ' . $target['room_number'] . ' (Bed ' . $newOcc . ').');
                } catch (Throwable $ex) {
                    mysqli_rollback($conn);
                    flash_set('danger', 'Transfer failed: ' . $ex->getMessage());
                }
            }
        }
    }
    redirect('admin/assignments.php');
}

$q       = get_str('q');
$hid     = get_int('hostel');
$asgStat = safe_choice(get_str('asg'), ['active', 'checked_out', 'transferred', 'all'], 'active');

$where  = [];
$types  = '';
$params = [];
if ($asgStat !== 'all') { $where[] = 'a.status = ?'; $params[] = $asgStat; $types .= 's'; }
if ($hid > 0)           { $where[] = 'h.id = ?';     $params[] = $hid;     $types .= 'i'; }
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.student_id LIKE ? OR r.room_number LIKE ? OR b.reference_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = db_all(
    $conn,
    "SELECT a.*, u.full_name, u.student_id, u.email, u.phone, u.programme, u.year_level,
            r.room_number, r.floor, r.occupied room_occupied, r.id room_id,
            h.name hostel_name, h.block_code, h.warden_name,
            rt.name room_type_name, rt.capacity, rt.price,
            b.reference_no, b.intake_period, b.status booking_status,
            adm.full_name assigned_by_name
       FROM assignments a
       JOIN users u        ON u.id  = a.student_id
       JOIN rooms r        ON r.id  = a.room_id
       JOIN hostels h      ON h.id  = r.hostel_id
       JOIN room_types rt  ON rt.id = r.room_type_id
       JOIN bookings b     ON b.id  = a.booking_id
       LEFT JOIN users adm ON adm.id = a.assigned_by
       $whereSql
      ORDER BY FIELD(a.status,'active','transferred','checked_out'), h.block_code, r.floor, LENGTH(r.room_number), r.room_number
      LIMIT 500",
    $types,
    $params
);

$totals = db_one(
    $conn,
    "SELECT SUM(status='active') active_count, SUM(status='checked_out') checked_out, SUM(status='transferred') transferred, COUNT(*) total FROM assignments"
);

$transferTargets = db_all(
    $conn,
    "SELECT r.*, h.block_code, h.name hostel_name, rt.name type_name, rt.capacity
       FROM rooms r JOIN hostels h ON h.id=r.hostel_id JOIN room_types rt ON rt.id=r.room_type_id
      WHERE r.status='available' AND r.occupied < rt.capacity
      ORDER BY h.block_code, rt.capacity, r.floor, r.room_number"
);

if (get_str('export') === 'csv') {
    $csv = [];
    foreach ($rows as $r) {
        $csv[] = [
            'id' => $r['id'], 'reference_no' => $r['reference_no'], 'student' => $r['full_name'], 'student_id' => $r['student_id'],
            'hostel' => $r['hostel_name'], 'block' => $r['block_code'], 'room' => $r['room_number'], 'floor' => $r['floor'],
            'bed' => $r['bed_number'], 'room_type' => $r['room_type_name'], 'price' => $r['price'],
            'intake' => $r['intake_period'], 'checkin' => $r['checkin_date'], 'status' => $r['status'], 'assigned_by' => $r['assigned_by_name'] ?: '',
        ];
    }
    csv_export('room-assignments-' . date('Ymd-His') . '.csv', $csv, [
        'id' => 'Assignment ID', 'reference_no' => 'Application', 'student' => 'Student', 'student_id' => 'Student ID',
        'hostel' => 'Hostel', 'block' => 'Block', 'room' => 'Room', 'floor' => 'Floor', 'bed' => 'Bed',
        'room_type' => 'Room type', 'price' => 'Price', 'intake' => 'Intake', 'checkin' => 'Check-in',
        'status' => 'Status', 'assigned_by' => 'Assigned by',
    ]);
}

page_header('Room Assignments', 'assignments', 'Approved applications converted into room + bed records', [
    ['label' => 'Export CSV', 'href' => 'assignments.php?' . e(http_build_query(array_filter(['asg' => $asgStat === 'active' ? '' : $asgStat, 'hostel' => $hid ?: '', 'q' => $q]))) . '&export=csv', 'class' => 'btn-outlined', 'icon' => 'download'],
]);
?>

<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['key',     'tone-4', (int)$totals['active_count'], 'Active assignments'],
      ['history', 'tone-2', (int)$totals['transferred'],  'Transferred'],
      ['logout',  'tone-1', (int)$totals['checked_out'],  'Checked out'],
      ['layers',  'tone-3', (int)$totals['total'],        'Total records'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $v, $l]): ?>
    <div class="card anim-rise anim-rise-<?= $i + 1 ?>"><div class="stat">
      <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
      <span class="stat-value"><?= $v ?></span><span class="stat-label"><?= e($l) ?></span>
    </div></div>
  <?php endforeach; ?>
</div>

<form method="get" action="assignments.php" class="card mb-3">
  <div class="filter-bar">
    <div class="field" style="grid-column:span 2">
      <label class="field-label" for="q">Search</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Student, student ID, room number or reference&hellip;">
    </div>
    <div class="field">
      <label class="field-label" for="hostel">Hostel block</label>
      <select class="select" id="hostel" name="hostel">
        <option value="">All blocks</option>
        <?php foreach (hostel_options() as $h): ?>
          <option value="<?= (int)$h['id'] ?>" <?= $hid === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['block_code']) ?> — <?= e($h['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="asg">Assignment status</label>
      <select class="select" id="asg" name="asg">
        <?php foreach (['active' => 'Active', 'checked_out' => 'Checked out', 'transferred' => 'Transferred', 'all' => 'All records'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $asgStat === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="row wrap mt-2" style="gap:8px">
    <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Filter</button>
    <a class="btn btn-text" href="assignments.php">Reset</a>
    <span class="grow"></span>
    <span class="tiny"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card"><div class="empty">
    <div class="empty-icon"><?= icon('key') ?></div>
    <h3 class="title-m mb-0">No assignment records</h3>
    <p class="tiny mb-2">Approve an application and assign a room to create the first record.</p>
    <a class="btn" href="applications.php?status=approved"><?= icon('assignment') ?> Approved applications</a>
  </div></div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
      <table class="data" style="min-width:1100px">
        <thead>
          <tr>
            <th>#</th><th>Application</th><th>Student</th><th>Hostel</th><th>Room / Bed</th>
            <th>Room type</th><th class="num">Rate</th><th>Check-in</th><th>Assigned by</th><th>Status</th><th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $a): ?>
            <tr>
              <td class="tiny"><?= str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT) ?></td>
              <td><a class="cell-strong" href="application-view.php?id=<?= (int)$a['booking_id'] ?>"><?= e($a['reference_no']) ?></a></td>
              <td><?= e($a['full_name']) ?><div class="cell-sub"><?= e($a['student_id']) ?> &middot; <?= e($a['programme'] ?: '—') ?></div></td>
              <td><?= e($a['hostel_name']) ?><div class="cell-sub"><?= e($a['block_code']) ?></div></td>
              <td>
                <span class="chip chip-primary"><?= icon('bed', 'icon icon-xs') ?> <?= e($a['room_number']) ?> &middot; Bed <?= (int)$a['bed_number'] ?></span>
                <div class="cell-sub">Floor <?= (int)$a['floor'] ?> &middot; <?= (int)$a['room_occupied'] ?>/<?= (int)$a['capacity'] ?> occupied</div>
              </td>
              <td><?= e($a['room_type_name']) ?></td>
              <td class="num"><?= money($a['price']) ?></td>
              <td class="tiny"><?= fmt_date($a['checkin_date']) ?></td>
              <td class="tiny"><?= e($a['assigned_by_name'] ?: '—') ?><div class="cell-sub"><?= e(time_ago($a['created_at'])) ?></div></td>
              <td><span class="chip <?= $a['status'] === 'active' ? 'chip-success' : 'chip-neutral' ?>"><span class="chip-dot"></span><?= e(ucfirst(str_replace('_', ' ', $a['status']))) ?></span></td>
              <td>
                <?php if ($a['status'] === 'active'): ?>
                  <div class="table-actions" style="justify-content:flex-end">
                    <button type="button" class="btn btn-sm btn-tonal" data-open-modal data-target="transfer-modal"
                            data-title="Transfer <?= e($a['full_name']) ?>"
                            onclick="prepareTransfer(<?= (int)$a['booking_id'] ?>)">
                      <?= icon('layers', 'icon icon-sm') ?> Transfer
                    </button>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="release">
                      <input type="hidden" name="booking_id" value="<?= (int)$a['booking_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outlined" style="--btn-fg:var(--md-error)"
                              data-confirm="Check out <?= e($a['full_name']) ?> from Room <?= e($a['room_number']) ?> and release the bed?">
                        <?= icon('logout', 'icon icon-sm') ?> Check out
                      </button>
                    </form>
                  </div>
                <?php else: ?>
                  <span class="tiny">Closed <?= fmt_date($a['checkout_date']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>


<div class="modal" data-modal id="transfer-modal">
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="transfer-title">
    <div class="row-between mb-2">
      <h3 class="title-m mb-0" id="transfer-title" data-modal-title>Transfer room</h3>
      <button class="icon-btn" data-close-modal aria-label="Close"><?= icon('close') ?></button>
    </div>
    <p class="tiny mb-2">The current bed is released and the new bed is occupied in a single transaction. The student is notified automatically.</p>
    <form method="post" action="assignments.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="booking_id" id="transfer-booking" value="">
      <div class="field mb-2">
        <label class="field-label" for="target_room_id">Move to</label>
        <select class="select" id="target_room_id" name="target_room_id" required>
          <option value="">Select a vacant room&hellip;</option>
          <?php foreach ($transferTargets as $t): ?>
            <option value="<?= (int)$t['id'] ?>">
              <?= e($t['block_code']) ?> — Room <?= e($t['room_number']) ?> (<?= e($t['type_name']) ?>, Floor <?= (int)$t['floor'] ?>) — <?= (int)$t['capacity'] - (int)$t['occupied'] ?> free
            </option>
          <?php endforeach; ?>
        </select>
        <span class="help-text">Only vacant rooms are listed.</span>
      </div>
      <div class="row" style="gap:8px">
        <button type="submit" class="btn"><?= icon('layers') ?> Confirm transfer</button>
        <button type="button" class="btn btn-text" data-close-modal>Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function prepareTransfer(bookingId) {
  document.getElementById('transfer-booking').value = bookingId;
}
</script>

<?php page_footer(); ?>
