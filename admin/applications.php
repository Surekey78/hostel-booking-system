<?php



require_once __DIR__ . '/../includes/header.php';
$me = require_admin();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $ids    = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));

    if ($action === 'approve' || $action === 'reject') {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $remarks   = trim((string)($_POST['remarks'] ?? ''));

        if (!$ids) {
            flash_set('warning', 'Select at least one application first.');
        } elseif ($action === 'reject' && $remarks === '') {
            flash_set('warning', 'A reason is required when rejecting an application.');
        } else {
            $done = 0;
            foreach ($ids as $bid) {
                $b = db_one($conn, "SELECT * FROM bookings WHERE id = ? AND status = 'pending'", 'i', [$bid]);
                if (!$b) {
                    continue;
                }
                $note = $remarks !== '' ? $remarks : ($action === 'approve'
                    ? 'Requirements verified. Application approved by the hostel office.'
                    : 'Application rejected by the hostel office.');
                db_exec(
                    $conn,
                    'UPDATE bookings SET status = ?, admin_remarks = ?, processed_by = ?, processed_at = NOW() WHERE id = ?',
                    'ssii',
                    [$newStatus, $note, (int)$me['id'], $bid]
                );
                notify(
                    (int)$b['student_id'],
                    $action === 'approve' ? 'Application approved' : 'Application rejected',
                    'Your application ' . $b['reference_no'] . ' has been ' . $newStatus . '. ' . $note,
                    $action === 'approve' ? 'success' : 'danger',
                    'student/my-booking.php?id=' . $bid
                );
                $done++;
            }
            flash_set($action === 'approve' ? 'success' : 'info', $done . ' application' . ($done === 1 ? '' : 's') . ' marked as ' . $newStatus . '.');
        }
    }

    if ($action === 'assign') {
        $bid    = (int)($_POST['booking_id'] ?? 0);
        $roomId = (int)($_POST['room_id'] ?? 0) ?: null;
        $res    = assign_room($bid, $roomId);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    if ($action === 'release') {
        $bid = (int)($_POST['booking_id'] ?? 0);
        $res = release_assignment($bid);
        if ($res['ok']) {
            $b = db_one($conn, 'SELECT * FROM bookings WHERE id = ?', 'i', [$bid]);
            if ($b) {
                notify((int)$b['student_id'], 'Room assignment ended', 'Your room assignment for ' . $b['reference_no'] . ' has been closed (check-out).', 'warning', 'student/my-bookings.php');
            }
        }
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    redirect('admin/applications.php' . (isset($_POST['return_query']) ? '?' . $_POST['return_query'] : ''));
}


$status = safe_choice(get_str('status'), ['', 'pending', 'approved', 'assigned', 'rejected', 'cancelled', 'checked_out'], '');
$q      = get_str('q');
$hid    = get_int('hostel');
$tid    = get_int('type');
$period = get_str('period');
$from   = get_str('from');
$to     = get_str('to');

$where  = ['1=1'];
$types  = '';
$params = [];
if ($status !== '') { $where[] = 'b.status = ?';        $params[] = $status; $types .= 's'; }
if ($hid > 0)       { $where[] = 'b.hostel_id = ?';     $params[] = $hid;    $types .= 'i'; }
if ($tid > 0)       { $where[] = 'b.room_type_id = ?';  $params[] = $tid;    $types .= 'i'; }
if ($period !== '') { $where[] = 'b.intake_period = ?'; $params[] = $period; $types .= 's'; }
if ($from !== '')   { $where[] = 'DATE(b.created_at) >= ?'; $params[] = $from; $types .= 's'; }
if ($to !== '')     { $where[] = 'DATE(b.created_at) <= ?'; $params[] = $to;   $types .= 's'; }
if ($q !== '') {
    $where[] = '(b.reference_no LIKE ? OR u.full_name LIKE ? OR u.student_id LIKE ? OR u.email LIKE ? OR h.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}
$whereSql = implode(' AND ', $where);

$listSql = "SELECT b.*, u.id AS student_user_id, u.full_name, u.student_id, u.email, u.phone, u.programme, u.year_level, u.gender,
                   h.name hostel_name, h.block_code, h.gender_policy,
                   rt.name room_type_name, rt.capacity, rt.price,
                   pa.full_name admin_name,
                   a.id assignment_id, a.bed_number, r.room_number,
                   (SELECT COUNT(*) FROM documents d WHERE d.booking_id = b.id) doc_count
              FROM bookings b
              JOIN users u        ON u.id = b.student_id
              JOIN hostels h      ON h.id = b.hostel_id
              JOIN room_types rt  ON rt.id = b.room_type_id
              LEFT JOIN users pa  ON pa.id = b.processed_by
              LEFT JOIN assignments a ON a.booking_id = b.id AND a.status = 'active'
              LEFT JOIN rooms r       ON r.id = a.room_id
             WHERE $whereSql";

if (get_str('export') === 'csv') {
    $rows = db_all($conn, $listSql . ' ORDER BY b.created_at DESC', $types, $params);
    $csv = [];
    foreach ($rows as $r) {
        $csv[] = [
            'reference_no' => $r['reference_no'], 'student' => $r['full_name'], 'student_id' => $r['student_id'],
            'email' => $r['email'], 'programme' => $r['programme'], 'hostel' => $r['hostel_name'],
            'block' => $r['block_code'], 'room_type' => $r['room_type_name'], 'price' => $r['price'],
            'intake_period' => $r['intake_period'], 'status' => $r['status'], 'room' => $r['room_number'] ?: '',
            'documents' => $r['doc_count'], 'submitted' => $r['created_at'], 'processed' => $r['processed_at'] ?: '',
            'processed_by' => $r['admin_name'] ?: '',
        ];
    }
    csv_export('booking-applications-' . date('Ymd-His') . '.csv', $csv, [
        'reference_no' => 'Reference', 'student' => 'Student', 'student_id' => 'Student ID', 'email' => 'Email',
        'programme' => 'Programme', 'hostel' => 'Hostel', 'block' => 'Block', 'room_type' => 'Room type',
        'price' => 'Price', 'intake_period' => 'Intake period', 'status' => 'Status', 'room' => 'Assigned room',
        'documents' => 'Documents', 'submitted' => 'Submitted', 'processed' => 'Processed', 'processed_by' => 'Processed by',
    ]);
}

$bookings = db_all($conn, $listSql . ' ORDER BY b.created_at DESC LIMIT 300', $types, $params);

$counts = db_one(
    $conn,
    "SELECT COUNT(*) total, SUM(status='pending') pending, SUM(status='approved') approved,
            SUM(status='assigned') assigned, SUM(status='rejected') rejected,
            SUM(status='cancelled') cancelled, SUM(status='checked_out') checked_out FROM bookings"
);

$queryBase = http_build_query(array_filter([
    'q' => $q, 'status' => $status, 'hostel' => $hid ?: '', 'type' => $tid ?: '',
    'period' => $period, 'from' => $from, 'to' => $to,
], fn($v) => $v !== '' && $v !== null));

page_header('Applications', 'applications', 'Review, approve, reject and convert requests into room assignments', [
    ['label' => 'Export CSV', 'href' => 'applications.php?' . e($queryBase) . '&export=csv', 'class' => 'btn-outlined', 'icon' => 'download'],
]);
?>


<div class="card mb-3" style="padding:14px 18px">
  <div class="row wrap" style="gap:8px">
    <?php
    $tabs = [
        '' => ['All', (int)$counts['total']], 'pending' => ['Pending', (int)$counts['pending']],
        'approved' => ['Approved', (int)$counts['approved']], 'assigned' => ['Assigned', (int)$counts['assigned']],
        'rejected' => ['Rejected', (int)$counts['rejected']], 'cancelled' => ['Cancelled', (int)$counts['cancelled']],
        'checked_out' => ['Checked out', (int)$counts['checked_out']],
    ];
    foreach ($tabs as $k => [$label, $n]): ?>
      <a class="chip chip-selectable chip-lg<?= $status === $k ? ' selected' : '' ?>"
         href="?<?= $k === '' ? '' : 'status=' . $k ?>">
        <?= e($label) ?> <span class="badge-count" style="<?= $status === $k ? 'background:rgba(255,255,255,.28)' : '' ?>"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>


<form method="get" action="applications.php" class="card mb-3">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <div class="filter-bar">
    <div class="field" style="grid-column:span 2">
      <label class="field-label" for="q">Search</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Reference, student name, ID or email&hellip;">
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
      <label class="field-label" for="type">Room type</label>
      <select class="select" id="type" name="type">
        <option value="">All types</option>
        <?php foreach (room_type_options() as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $tid === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="period">Intake period</label>
      <select class="select" id="period" name="period">
        <option value="">All periods</option>
        <?php foreach (INTAKE_PERIODS as $p): ?>
          <option value="<?= e($p) ?>" <?= $period === $p ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="from">Submitted from</label>
      <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
    </div>
    <div class="field">
      <label class="field-label" for="to">Submitted to</label>
      <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
    </div>
  </div>
  <div class="row wrap mt-2" style="gap:8px">
    <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Apply filters</button>
    <a class="btn btn-text" href="applications.php">Reset</a>
    <span class="grow"></span>
    <span class="tiny"><?= count($bookings) ?> record<?= count($bookings) === 1 ? '' : 's' ?> shown</span>
  </div>
</form>

<?php if (!$bookings): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= icon('assignment') ?></div>
      <h3 class="title-m mb-0">No applications found</h3>
      <p class="tiny">Adjust the filters above, or wait for students to submit new requests.</p>
      <a class="btn btn-tonal btn-sm mt-1" href="applications.php">Clear filters</a>
    </div>
  </div>
<?php else: ?>

<form method="post" action="applications.php" id="bulk-form">
  <?= csrf_field() ?>
  <input type="hidden" name="return_query" value="<?= e($queryBase) ?>">

  
  <div class="card mb-2" style="padding:14px 18px">
    <div class="row-between wrap" style="gap:12px">
      <div class="row wrap" style="gap:8px">
        <label class="check" style="padding:0">
          <input type="checkbox" id="check-all">
          <span class="check-box"><?= icon('check') ?></span>
          <span class="check-text">Select all pending</span>
        </label>
        <span class="tiny" id="sel-count">0 selected</span>
      </div>
      <div class="row wrap" style="gap:8px">
        <input type="hidden" name="action" id="bulk-action" value="">
        <input class="input" type="text" name="remarks" placeholder="Remarks (required when rejecting)" style="min-height:40px;max-width:320px">
        <button type="submit" class="btn btn-success btn-sm" onclick="document.getElementById('bulk-action').value='approve'" data-confirm="Approve the selected applications?"><?= icon('check', 'icon icon-sm') ?> Approve</button>
        <button type="submit" class="btn btn-danger btn-sm" onclick="document.getElementById('bulk-action').value='reject'" data-confirm="Reject the selected applications?"><?= icon('close', 'icon icon-sm') ?> Reject</button>
      </div>
    </div>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
      <table class="data" style="min-width:1080px">
        <thead>
          <tr>
            <th style="width:44px"></th>
            <th>Reference</th>
            <th>Student</th>
            <th>Hostel / room type</th>
            <th>Intake</th>
            <th class="num">Price</th>
            <th>Docs</th>
            <th>Submitted</th>
            <th>Status</th>
            <th>Room</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $b):
              $isPending = $b['status'] === 'pending';
              $eligible  = $b['gender_policy'] === 'mixed' || !$b['gender'] || $b['gender_policy'] === $b['gender'];
          ?>
            <tr>
              <td>
                <?php if ($isPending): ?>
                  <label class="check" style="padding:0">
                    <input type="checkbox" name="ids[]" value="<?= (int)$b['id'] ?>" class="row-check">
                    <span class="check-box"><?= icon('check') ?></span>
                    <span class="sr-only">Select <?= e($b['reference_no']) ?></span>
                  </label>
                <?php endif; ?>
              </td>
              <td class="cell-strong"><?= e($b['reference_no']) ?></td>
              <td>
                <?= e($b['full_name']) ?>
                <div class="cell-sub"><?= e($b['student_id']) ?> &middot; <?= e($b['programme'] ?: '—') ?><?= $b['year_level'] ? ' &middot; ' . e($b['year_level']) : '' ?></div>
                <?php if (!$eligible): ?><span class="chip chip-danger" style="height:22px;margin-top:4px">Gender policy mismatch</span><?php endif; ?>
              </td>
              <td>
                <?= e($b['hostel_name']) ?> <span class="tiny">(<?= e($b['block_code']) ?>)</span>
                <div class="cell-sub"><?= e($b['room_type_name']) ?> &middot; <?= (int)$b['capacity'] ?> bed<?= (int)$b['capacity'] === 1 ? '' : 's' ?></div>
              </td>
              <td class="tiny"><?= e($b['intake_period']) ?><div class="cell-sub">Check-in <?= fmt_date($b['checkin_date']) ?></div></td>
              <td class="num"><?= money($b['price']) ?></td>
              <td>
                <?php if ((int)$b['doc_count'] > 0): ?>
                  <span class="chip chip-primary"><?= icon('attach', 'icon icon-xs') ?> <?= (int)$b['doc_count'] ?></span>
                <?php else: ?>
                  <span class="tiny">None</span>
                <?php endif; ?>
              </td>
              <td class="tiny"><?= fmt_date($b['created_at']) ?><div class="cell-sub"><?= e(time_ago($b['created_at'])) ?></div></td>
              <td><?= status_chip($b['status']) ?></td>
              <td>
                <?php if ($b['room_number']): ?>
                  <span class="chip chip-success"><?= icon('bed', 'icon icon-xs') ?> <?= e($b['room_number']) ?> / B<?= (int)$b['bed_number'] ?></span>
                <?php else: ?>
                  <span class="tiny">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="table-actions" style="justify-content:flex-end">
                  <a class="btn btn-sm btn-text" href="application-view.php?id=<?= (int)$b['id'] ?>"><?= icon('visibility', 'icon icon-sm') ?> View</a>
                  <?php if ($isPending): ?>
                    <button type="button" class="btn btn-sm btn-success" data-open-modal data-target="assign-modal"
                            data-title="Assign a room — <?= e($b['reference_no']) ?>"
                            onclick="prepareAssign(<?= (int)$b['id'] ?>, '<?= e($b['reference_no']) ?>', <?= (int)$b['hostel_id'] ?>, <?= (int)$b['room_type_id'] ?>)">
                      <?= icon('key', 'icon icon-sm') ?> Assign
                    </button>
                  <?php elseif ($b['status'] === 'approved'): ?>
                    <button type="button" class="btn btn-sm" data-open-modal data-target="assign-modal"
                            data-title="Assign a room — <?= e($b['reference_no']) ?>"
                            onclick="prepareAssign(<?= (int)$b['id'] ?>, '<?= e($b['reference_no']) ?>', <?= (int)$b['hostel_id'] ?>, <?= (int)$b['room_type_id'] ?>)">
                      <?= icon('key', 'icon icon-sm') ?> Assign room
                    </button>
                  <?php elseif ($b['status'] === 'assigned'): ?>
                    <button type="submit" class="btn btn-sm btn-outlined" name="action" value="release"
                            form="release-form-<?= (int)$b['id'] ?>"
                            data-confirm="Release the bed for <?= e($b['reference_no']) ?> and mark it checked out?">
                      <?= icon('logout', 'icon icon-sm') ?> Check out
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>


<?php foreach ($bookings as $b): if ($b['status'] !== 'assigned') continue; ?>
  <form method="post" action="applications.php" id="release-form-<?= (int)$b['id'] ?>" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="release">
    <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
    <input type="hidden" name="return_query" value="<?= e($queryBase) ?>">
  </form>
<?php endforeach; ?>


<div class="modal" data-modal id="assign-modal">
  <div class="modal-backdrop" data-close-modal></div>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="assign-title">
    <div class="row-between mb-2">
      <h3 class="title-m mb-0" id="assign-title" data-modal-title>Assign a room</h3>
      <button class="icon-btn" data-close-modal aria-label="Close"><?= icon('close') ?></button>
    </div>
    <p class="tiny mb-2">
      Assigning converts the approved application into a room-assignment record, occupies the bed and notifies the student.
    </p>
    <form method="post" action="applications.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="booking_id" id="assign-booking-id" value="">
      <input type="hidden" name="return_query" value="<?= e($queryBase) ?>">

      <div class="field mb-2">
        <label class="field-label" for="assign-room">Room</label>
        <select class="select" id="assign-room" name="room_id" data-room-select>
          <option value="0">Auto-assign the lowest vacant room</option>
        </select>
        <span class="help-text" data-room-info>A vacant room will be chosen automatically.</span>
      </div>

      <div class="row" style="gap:8px">
        <button type="submit" class="btn"><?= icon('key') ?> Confirm assignment</button>
        <button type="button" class="btn btn-text" data-close-modal>Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
var ROOMS = <?= json_encode(
    db_all($conn, "SELECT r.id, r.room_number, r.floor, r.occupied, r.hostel_id, r.room_type_id, rt.capacity, rt.name type_name
                     FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
                    WHERE r.status = 'available' AND r.occupied < rt.capacity
                    ORDER BY r.hostel_id, r.room_type_id, r.floor, LENGTH(r.room_number), r.room_number"),
    JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;

function prepareAssign(bookingId, ref, hostelId, typeId) {
  document.getElementById('assign-booking-id').value = bookingId;
  var sel = document.getElementById('assign-room');
  sel.innerHTML = '<option value="0">Auto-assign the lowest vacant room</option>';
  var matches = ROOMS.filter(function (r) { return Number(r.hostel_id) === hostelId && Number(r.room_type_id) === typeId; });
  matches.forEach(function (r) {
    var free = Number(r.capacity) - Number(r.occupied);
    var opt = document.createElement('option');
    opt.value = r.id;
    opt.textContent = 'Room ' + r.room_number + ' (Floor ' + r.floor + ') — ' + free + ' bed(s) free';
    opt.setAttribute('data-info', free + ' bed(s) free in Room ' + r.room_number + ', floor ' + r.floor + '.');
    sel.appendChild(opt);
  });
  if (!matches.length) {
    var opt = document.createElement('option');
    opt.value = '';
    opt.disabled = true;
    opt.textContent = 'No vacant room of this type in this block';
    sel.appendChild(opt);
  }
}

// Bulk selection helpers
var checkAll = document.getElementById('check-all');
var selCount = document.getElementById('sel-count');
function updateCount() {
  var n = document.querySelectorAll('.row-check:checked').length;
  selCount.textContent = n + ' selected';
}
if (checkAll) {
  checkAll.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(function (c) { c.checked = checkAll.checked; });
    updateCount();
  });
}
document.querySelectorAll('.row-check').forEach(function (c) { c.addEventListener('change', updateCount); });

// Ensure a bulk action is set before submitting
document.getElementById('bulk-form').addEventListener('submit', function (e) {
  var act = document.getElementById('bulk-action').value;
  if (!act) { e.preventDefault(); alert('Choose Approve or Reject.'); return; }
  if (act === 'reject') {
    var remarks = this.querySelector('input[name="remarks"]').value.trim();
    if (!remarks) { e.preventDefault(); alert('Please enter a reason for rejecting.'); return; }
  }
  if (!document.querySelectorAll('.row-check:checked').length) {
    e.preventDefault();
    alert('Select at least one pending application.');
  }
});
</script>

<?php endif; ?>

<?php page_footer(); ?>
