<?php
/**
 * student/my-bookings.php - Track all applications and their status.
 */
require_once __DIR__ . '/../includes/header.php';
$me  = require_student();
$uid = (int)$me['id'];

// ---- Handle cancellation ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    verify_csrf();
    $bid = (int)($_POST['booking_id'] ?? 0);
    $b   = db_one($conn, 'SELECT * FROM bookings WHERE id = ? AND student_id = ?', 'ii', [$bid, $uid]);
    if (!$b) {
        flash_set('danger', 'Application not found.');
    } elseif (!in_array($b['status'], ['pending', 'approved'], true)) {
        flash_set('danger', 'Only pending or approved applications can be cancelled.');
    } else {
        db_exec($conn, "UPDATE bookings SET status = 'cancelled' WHERE id = ?", 'i', [$bid]);
        notify($uid, 'Application cancelled', 'You cancelled application ' . $b['reference_no'] . '.', 'info', 'student/my-booking.php?id=' . $bid);
        foreach (db_all($conn, "SELECT id FROM users WHERE role='admin'") as $adm) {
            notify((int)$adm['id'], 'Application cancelled', $me['full_name'] . ' cancelled ' . $b['reference_no'] . '.', 'info', 'admin/applications.php');
        }
        flash_set('success', 'Application ' . $b['reference_no'] . ' has been cancelled.');
    }
    redirect('student/my-bookings.php');
}

$statusFilter = get_str('status');
$allowed = ['', 'pending', 'approved', 'assigned', 'rejected', 'cancelled', 'checked_out'];
$statusFilter = safe_choice($statusFilter, $allowed, '');

$sql = "SELECT b.*, h.name hostel_name, h.block_code, rt.name room_type_name, rt.capacity, rt.price,
               a.id assignment_id, r.room_number, r.floor, a.bed_number
          FROM bookings b
          JOIN hostels h      ON h.id  = b.hostel_id
          JOIN room_types rt  ON rt.id = b.room_type_id
          LEFT JOIN assignments a ON a.booking_id = b.id AND a.status = 'active'
          LEFT JOIN rooms r       ON r.id = a.room_id
         WHERE b.student_id = ?";
$types = 'i';
$params = [$uid];
if ($statusFilter !== '') {
    $sql .= ' AND b.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY b.created_at DESC';

$bookings = db_all($conn, $sql, $types, $params);

$counts = db_one(
    $conn,
    "SELECT COUNT(*) total, SUM(status='pending') pending, SUM(status='approved') approved,
            SUM(status='assigned') assigned, SUM(status='rejected') rejected, SUM(status='cancelled') cancelled,
            SUM(status='checked_out') checked_out
       FROM bookings WHERE student_id = ?",
    'i',
    [$uid]
);

$docCounts = [];
foreach (db_all($conn, 'SELECT booking_id, COUNT(*) c FROM documents WHERE uploaded_by = ? GROUP BY booking_id', 'i', [$uid]) as $d) {
    $docCounts[(int)$d['booking_id']] = (int)$d['c'];
}

page_header('My Applications', 'my-bookings', (int)$counts['total'] . ' application' . ((int)$counts['total'] === 1 ? '' : 's') . ' submitted', [
    ['label' => 'New application', 'href' => 'apply.php', 'class' => '', 'icon' => 'add'],
]);
?>

<!-- Status filter chips -->
<div class="card mb-3">
  <div class="row wrap" style="gap:8px" data-chip-group>
    <span class="tiny" style="margin-right:4px">Filter:</span>
    <?php
    $chips = [
        ''            => ['All', (int)$counts['total']],
        'pending'     => ['Pending', (int)$counts['pending']],
        'approved'    => ['Approved', (int)$counts['approved']],
        'assigned'    => ['Assigned', (int)$counts['assigned']],
        'rejected'    => ['Rejected', (int)$counts['rejected']],
        'cancelled'   => ['Cancelled', (int)$counts['cancelled']],
        'checked_out' => ['Checked out', (int)$counts['checked_out']],
    ];
    foreach ($chips as $key => [$label, $n]): ?>
      <a class="chip chip-selectable chip-lg<?= $statusFilter === $key ? ' selected' : '' ?>"
         href="?<?= $key === '' ? '' : 'status=' . $key ?>">
        <?= e($label) ?> <span class="badge-count" style="<?= $statusFilter === $key ? 'background:rgba(255,255,255,.28)' : '' ?>"><?= $n ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$bookings): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= icon('assignment') ?></div>
      <h3 class="title-m mb-0"><?= $statusFilter ? 'No ' . e($statusFilter) . ' applications' : 'No applications yet' ?></h3>
      <p class="tiny mb-2">Browse the hostel blocks and submit an accommodation application to get started.</p>
      <a class="btn" href="apply.php"><?= icon('add') ?> New application</a>
    </div>
  </div>
<?php else: ?>
  <div class="stack">
    <?php foreach ($bookings as $i => $b):
        $docs = $docCounts[(int)$b['id']] ?? 0;
        $canCancel = in_array($b['status'], ['pending', 'approved'], true);
    ?>
      <article class="card card-hover anim-rise anim-rise-<?= ($i % 4) + 1 ?>">
        <div class="row-between wrap mb-2" style="gap:12px">
          <div class="row" style="gap:14px">
            <span class="stat-icon <?= $b['status'] === 'assigned' ? 'tone-4' : ($b['status'] === 'pending' ? 'tone-5' : ($b['status'] === 'rejected' ? 'tone-6' : 'tone-2')) ?>">
              <?= icon($b['status'] === 'assigned' ? 'key' : ($b['status'] === 'rejected' ? 'close' : ($b['status'] === 'pending' ? 'history' : 'check_circle'))) ?>
            </span>
            <div>
              <div class="row" style="gap:8px">
                <span class="title-m mb-0"><?= e($b['reference_no']) ?></span>
                <?= status_chip($b['status']) ?>
              </div>
              <div class="tiny">
                <?= e($b['hostel_name']) ?> (<?= e($b['block_code']) ?>) &middot; <?= e($b['room_type_name']) ?>
                &middot; <?= (int)$b['capacity'] ?> bed<?= (int)$b['capacity'] === 1 ? '' : 's' ?>
              </div>
            </div>
          </div>
          <div class="row" style="gap:8px">
            <?php if ($b['room_number']): ?>
              <span class="chip chip-success chip-lg"><?= icon('bed', 'icon icon-xs') ?> Room <?= e($b['room_number']) ?> &middot; Bed <?= (int)$b['bed_number'] ?></span>
            <?php endif; ?>
            <a class="btn btn-tonal btn-sm" href="my-booking.php?id=<?= (int)$b['id'] ?>">Details</a>
          </div>
        </div>

        <div class="grid g-4" style="gap:12px">
          <div><div class="tiny">Intake period</div><div class="label-m"><?= e($b['intake_period']) ?></div></div>
          <div><div class="tiny">Rate / semester</div><div class="label-m"><?= money($b['price']) ?></div></div>
          <div><div class="tiny">Submitted</div><div class="label-m"><?= fmt_date($b['created_at']) ?> <span class="tiny">(<?= e(time_ago($b['created_at'])) ?>)</span></div></div>
          <div><div class="tiny">Documents</div><div class="label-m"><?= $docs ?> attached</div></div>
        </div>

        <?php if ($b['special_request']): ?>
          <div class="card card-flat card-pad-sm mt-2">
            <div class="tiny mb-1">Your request</div>
            <div class="body-m" style="font-size:.9375rem"><?= e($b['special_request']) ?></div>
          </div>
        <?php endif; ?>

        <?php if ($b['admin_remarks']): ?>
          <div class="alert alert-<?= $b['status'] === 'rejected' ? 'danger' : 'success' ?> mt-2" style="margin-bottom:0">
            <?= icon($b['status'] === 'rejected' ? 'error' : 'check_circle') ?>
            <span><strong>Hostel office:</strong> <?= e($b['admin_remarks']) ?></span>
          </div>
        <?php endif; ?>

        <hr class="divider">
        <div class="row-between wrap" style="gap:10px">
          <div class="tiny">
            <?php if ($b['processed_at']): ?>
              Last action: <strong><?= fmt_datetime($b['processed_at']) ?></strong>
            <?php else: ?>
              Awaiting review by the hostel office
            <?php endif; ?>
          </div>
          <div class="row" style="gap:8px">
            <?php if ($canCancel): ?>
              <form method="post" action="my-bookings.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outlined"
                        data-confirm="Cancel application <?= e($b['reference_no']) ?>? This cannot be undone.">
                  <?= icon('close', 'icon icon-sm') ?> Cancel application
                </button>
              </form>
            <?php endif; ?>
            <?php if (in_array($b['status'], ['rejected', 'cancelled'], true)): ?>
              <a class="btn btn-sm btn-tonal" href="apply.php?hostel=<?= (int)$b['hostel_id'] ?>&type=<?= (int)$b['room_type_id'] ?>"><?= icon('add', 'icon icon-sm') ?> Re-apply</a>
            <?php endif; ?>
            <a class="btn btn-sm btn-text" href="my-booking.php?id=<?= (int)$b['id'] ?>"><?= icon('description', 'icon icon-sm') ?> Full record</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
