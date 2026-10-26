<?php
/**
 * admin/application-view.php - Full application record with approve / reject / assign controls.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

$bid = get_int('id');
$b = db_one(
    $conn,
    "SELECT b.*, u.id AS student_user_id, u.full_name, u.student_id, u.email, u.phone, u.programme, u.year_level, u.gender,
            u.address, u.emergency_contact_name, u.emergency_contact_phone, u.created_at user_since,
            h.name hostel_name, h.block_code, h.location, h.gender_policy, h.warden_name, h.warden_phone,
            rt.name room_type_name, rt.capacity, rt.price, rt.facilities type_facilities,
            pa.full_name admin_name,
            a.id assignment_id, a.bed_number, a.checkin_date assigned_checkin, a.created_at assigned_at,
            r.room_number, r.floor, r.id room_id
       FROM bookings b
       JOIN users u        ON u.id = b.student_id
       JOIN hostels h      ON h.id = b.hostel_id
       JOIN room_types rt  ON rt.id = b.room_type_id
       LEFT JOIN users pa  ON pa.id = b.processed_by
       LEFT JOIN assignments a ON a.booking_id = b.id AND a.status = 'active'
       LEFT JOIN rooms r       ON r.id = a.room_id
      WHERE b.id = ?",
    'i',
    [$bid]
);

if (!$b) {
    flash_set('danger', 'Application not found.');
    redirect('admin/applications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve' || $action === 'reject') {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $remarks   = trim((string)($_POST['admin_remarks'] ?? ''));
        if ($action === 'reject' && $remarks === '') {
            flash_set('warning', 'A reason is required when rejecting an application.');
        } elseif (!in_array($b['status'], ['pending', 'approved', 'rejected'], true)) {
            flash_set('warning', 'This application has already been processed.');
        } else {
            $note = $remarks !== '' ? $remarks : ($action === 'approve'
                ? 'Requirements verified. Application approved by the hostel office.'
                : 'Application rejected by the hostel office.');
            db_exec($conn, 'UPDATE bookings SET status=?, admin_remarks=?, processed_by=?, processed_at=NOW() WHERE id=?', 'ssii', [$newStatus, $note, (int)$me['id'], $bid]);
            notify((int)$b['student_user_id'], $action === 'approve' ? 'Application approved' : 'Application rejected',
                'Your application ' . $b['reference_no'] . ' has been ' . $newStatus . '. ' . $note,
                $action === 'approve' ? 'success' : 'danger', 'student/my-booking.php?id=' . $bid);
            flash_set($action === 'approve' ? 'success' : 'info', 'Application ' . $b['reference_no'] . ' marked as ' . $newStatus . '.');
        }
    }

    if ($action === 'assign') {
        $roomId = (int)($_POST['room_id'] ?? 0) ?: null;
        $res = assign_room($bid, $roomId);
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    if ($action === 'release') {
        $res = release_assignment($bid);
        if ($res['ok']) {
            notify((int)$b['student_user_id'], 'Room assignment ended', 'Your room assignment for ' . $b['reference_no'] . ' was closed (check-out).', 'warning', 'student/my-bookings.php');
        }
        flash_set($res['ok'] ? 'success' : 'danger', $res['message']);
    }

    if ($action === 'cancel') {
        if (in_array($b['status'], ['pending', 'approved'], true)) {
            db_exec($conn, "UPDATE bookings SET status='cancelled', admin_remarks=?, processed_by=?, processed_at=NOW() WHERE id=?", 'sii', [trim((string)($_POST['admin_remarks'] ?? 'Cancelled by the hostel office.')), (int)$me['id'], $bid]);
            notify((int)$b['student_user_id'], 'Application cancelled', 'The hostel office cancelled your application ' . $b['reference_no'] . '.', 'danger', 'student/my-bookings.php');
            flash_set('info', 'Application cancelled.');
        }
    }

    redirect('admin/application-view.php?id=' . $bid);
}

$docs = db_all($conn, 'SELECT d.*, u.full_name FROM documents d JOIN users u ON u.id = d.uploaded_by WHERE d.booking_id = ? ORDER BY d.created_at DESC', 'i', [$bid]);

$freeRooms = db_all(
    $conn,
    "SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
      WHERE r.hostel_id = ? AND r.room_type_id = ? AND r.status='available' AND r.occupied < rt.capacity
      ORDER BY r.floor, LENGTH(r.room_number), r.room_number",
    'ii',
    [(int)$b['hostel_id'], (int)$b['room_type_id']]
);

$history = db_all(
    $conn,
    "SELECT b.*, h.name hostel_name, rt.name room_type_name FROM bookings b
       JOIN hostels h ON h.id=b.hostel_id JOIN room_types rt ON rt.id=b.room_type_id
      WHERE b.student_id = ? AND b.id <> ? ORDER BY b.created_at DESC",
    'ii',
    [(int)$b['student_user_id'], $bid]
);

$roommates = $b['room_id']
    ? db_all($conn, "SELECT u.full_name, u.student_id, a.bed_number FROM assignments a JOIN users u ON u.id=a.student_id
                      WHERE a.room_id = ? AND a.status='active' AND a.booking_id <> ? ORDER BY a.bed_number", 'ii', [(int)$b['room_id'], $bid])
    : [];

$eligible = $b['gender_policy'] === 'mixed' || !$b['gender'] || $b['gender_policy'] === $b['gender'];

page_header('Application ' . e($b['reference_no']), 'applications', e($b['full_name']) . ' &middot; ' . e($b['hostel_name']), [
    ['label' => 'Back to queue', 'href' => 'applications.php', 'class' => 'btn-outlined', 'icon' => 'arrow_back'],
]);
?>

<?php if (!$eligible): ?>
  <div class="alert alert-danger mb-2"><?= icon('warning') ?><span><strong>Eligibility warning:</strong> <?= e($b['hostel_name']) ?> is a <?= e($b['gender_policy']) ?>-only residence, but this student's profile gender is <strong><?= e(ucfirst((string)$b['gender'] ?: 'not set')) ?></strong>.</span></div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:24px;align-items:start">
  <div class="stack">
    <!-- Decision panel -->
    <div class="card card-pad-lg" style="box-shadow:var(--e2)">
      <div class="card-header">
        <div>
          <h3 class="card-title">Decision</h3>
          <p class="card-sub">Current status: <?= strip_tags(status_chip($b['status'])) ?></p>
        </div>
        <?php if ($b['processed_at']): ?>
          <span class="tiny">Last action <?= e(time_ago($b['processed_at'])) ?><?= $b['admin_name'] ? ' by ' . e($b['admin_name']) : '' ?></span>
        <?php endif; ?>
      </div>

      <?php if ($b['status'] === 'pending'): ?>
        <form method="post" action="application-view.php?id=<?= $bid ?>">
          <?= csrf_field() ?>
          <div class="field mb-2">
            <label class="field-label" for="admin_remarks">Remarks to the student</label>
            <textarea class="textarea" id="admin_remarks" name="admin_remarks" rows="3"
                      placeholder="e.g. Requirements verified. Please report to the hostel office for key collection."></textarea>
            <span class="help-text">Required when rejecting. The student receives this text as a notification.</span>
          </div>
          <div class="row wrap" style="gap:10px">
            <button type="submit" name="action" value="approve" class="btn btn-success btn-lg"><?= icon('check_circle') ?> Approve application</button>
            <button type="submit" name="action" value="reject" class="btn btn-danger btn-lg" data-confirm="Reject this application?"><?= icon('close') ?> Reject</button>
            <button type="submit" name="action" value="cancel" class="btn btn-outlined btn-lg" data-confirm="Cancel this application on the student's behalf?"><?= icon('delete') ?> Cancel</button>
          </div>
        </form>
      <?php elseif ($b['status'] === 'approved'): ?>
        <div class="alert alert-info mb-2"><?= icon('info') ?><span>Approved. Convert this into a room assignment below — the bed is occupied and the student is notified automatically.</span></div>
        <form method="post" action="application-view.php?id=<?= $bid ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <div class="field mb-2">
            <label class="field-label" for="room_id">Assign room</label>
            <select class="select" id="room_id" name="room_id" data-room-select>
              <option value="0">Auto-assign the lowest vacant <?= e($b['room_type_name']) ?> room</option>
              <?php foreach ($freeRooms as $r): ?>
                <option value="<?= (int)$r['id'] ?>" data-info="<?= (int)$r['capacity'] - (int)$r['occupied'] ?> bed(s) free">
                  Room <?= e($r['room_number']) ?> — Floor <?= (int)$r['floor'] ?> — <?= (int)$r['capacity'] - (int)$r['occupied'] ?> bed(s) free
                </option>
              <?php endforeach; ?>
            </select>
            <span class="help-text" data-room-info><?= $freeRooms ? count($freeRooms) . ' vacant room(s) of this type in ' . e($b['hostel_name']) . '.' : 'No vacant room of this type in this block.' ?></span>
          </div>
          <div class="row wrap" style="gap:10px">
            <button type="submit" class="btn btn-lg" <?= $freeRooms ? '' : 'disabled aria-disabled="true"' ?>><?= icon('key') ?> Assign room now</button>
            <button type="submit" name="action" value="reject" class="btn btn-danger btn-lg" data-confirm="Change the decision to rejected?"><?= icon('close') ?> Reject instead</button>
          </div>
        </form>
      <?php elseif ($b['status'] === 'assigned'): ?>
        <div class="alert alert-success mb-2">
          <?= icon('key') ?>
          <span>Room assigned: <strong><?= e($b['hostel_name']) ?></strong>, Room <strong><?= e($b['room_number']) ?></strong>, Bed <strong><?= (int)$b['bed_number'] ?></strong> (Floor <?= (int)$b['floor'] ?>) on <?= fmt_datetime($b['assigned_at']) ?>.</span>
        </div>
        <div class="row wrap" style="gap:10px">
          <a class="btn btn-tonal" href="assignments.php">View all assignments</a>
          <a class="btn btn-outlined" href="rooms.php?hostel=<?= (int)$b['hostel_id'] ?>">Room plan</a>
          <form method="post" action="application-view.php?id=<?= $bid ?>" style="display:inline">
            <?= csrf_field() ?>
            <button type="submit" name="action" value="release" class="btn btn-danger btn-outlined" style="--btn-fg:var(--md-error)"
                    data-confirm="Check this student out and release the bed?">
              <?= icon('logout') ?> Check out / release bed
            </button>
          </form>
        </div>
      <?php elseif ($b['status'] === 'rejected'): ?>
        <div class="alert alert-danger mb-2"><?= icon('error') ?><span>Rejected on <?= fmt_datetime($b['processed_at']) ?><?= $b['admin_name'] ? ' by ' . e($b['admin_name']) : '' ?>.</span></div>
        <form method="post" action="application-view.php?id=<?= $bid ?>" class="row wrap" style="gap:10px">
          <?= csrf_field() ?>
          <button type="submit" name="action" value="approve" class="btn btn-success"><?= icon('check_circle') ?> Reverse — approve instead</button>
        </form>
      <?php else: ?>
        <div class="alert alert-info mb-0"><?= icon('info') ?><span>This application is <?= e(str_replace('_', ' ', $b['status'])) ?>. No further action is available.</span></div>
      <?php endif; ?>
    </div>

    <!-- Application detail -->
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Application details</h3>
          <p class="card-sub"><?= e($b['reference_no']) ?> &middot; submitted <?= fmt_datetime($b['created_at']) ?></p>
        </div>
        <button class="btn btn-text btn-sm no-print" onclick="window.print()"><?= icon('print', 'icon icon-sm') ?> Print</button>
      </div>
      <dl class="dl">
        <dt>Hostel block</dt><dd><?= e($b['hostel_name']) ?> (<?= e($b['block_code']) ?>) — <?= e($b['location']) ?></dd>
        <dt>Room type</dt><dd><?= e($b['room_type_name']) ?> &middot; <?= (int)$b['capacity'] ?> bed<?= (int)$b['capacity'] === 1 ? '' : 's' ?> &middot; <?= money($b['price']) ?> / semester</dd>
        <dt>Intake period</dt><dd><?= e($b['intake_period']) ?></dd>
        <dt>Preferred check-in</dt><dd><?= fmt_date($b['checkin_date']) ?></dd>
        <dt>Roommates</dt><dd><?= (int)$b['roommates'] === 0 ? 'Applying alone' : (int)$b['roommates'] . ' additional student(s)' ?></dd>
        <dt>Special request</dt><dd><?= $b['special_request'] ? nl2br(e($b['special_request'])) : '—' ?></dd>
        <dt>Office remarks</dt><dd><?= $b['admin_remarks'] ? nl2br(e($b['admin_remarks'])) : '—' ?></dd>
      </dl>
      <div class="row wrap mt-2" style="gap:6px"><?= facility_chips($b['type_facilities']) ?></div>
    </div>

    <!-- Applicant -->
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Applicant</h3>
          <p class="card-sub">Registered <?= fmt_date($b['user_since']) ?></p>
        </div>
        <a class="btn btn-text btn-sm" href="students.php?q=<?= e(urlencode($b['student_id'])) ?>">Student record</a>
      </div>
      <div class="row mb-2" style="gap:14px">
        <span class="avatar lg"><?= e(initials($b['full_name'])) ?></span>
        <div>
          <div class="title-m mb-0"><?= e($b['full_name']) ?></div>
          <div class="tiny"><?= e($b['student_id']) ?> &middot; <?= e(ucfirst((string)$b['gender'] ?: '—')) ?> &middot; <?= e($b['year_level'] ?: 'Student') ?></div>
        </div>
      </div>
      <dl class="dl">
        <dt>Programme</dt><dd><?= e($b['programme'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= e($b['email']) ?></dd>
        <dt>Phone</dt><dd><?= e($b['phone'] ?: '—') ?></dd>
        <dt>Address</dt><dd><?= e($b['address'] ?: '—') ?></dd>
        <dt>Emergency contact</dt><dd><?= e($b['emergency_contact_name'] ?: '—') ?><?= $b['emergency_contact_phone'] ? ' &middot; ' . e($b['emergency_contact_phone']) : '' ?></dd>
      </dl>

      <?php if ($history): ?>
        <hr class="divider">
        <div class="label-s mb-1" style="color:var(--md-primary)">Previous applications</div>
        <div class="table-wrap">
          <table class="data" style="min-width:520px">
            <thead><tr><th>Reference</th><th>Hostel</th><th>Type</th><th>Intake</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($history as $h): ?>
                <tr>
                  <td><a href="application-view.php?id=<?= (int)$h['id'] ?>" class="cell-strong"><?= e($h['reference_no']) ?></a></td>
                  <td><?= e($h['hostel_name']) ?></td>
                  <td><?= e($h['room_type_name']) ?></td>
                  <td class="tiny"><?= e($h['intake_period']) ?></td>
                  <td><?= status_chip($h['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Documents -->
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Supporting documents</h3>
          <p class="card-sub"><?= count($docs) ?> file<?= count($docs) === 1 ? '' : 's' ?> uploaded</p>
        </div>
      </div>
      <?php if (!$docs): ?>
        <p class="tiny mb-0">The student has not attached any documents to this application.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Type</th><th>File</th><th class="num">Size</th><th>Uploaded by</th><th>Date</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($docs as $d): ?>
                <tr>
                  <td><span class="chip chip-outline"><?= icon('description', 'icon icon-xs') ?> <?= e($d['doc_type']) ?></span></td>
                  <td class="cell-strong"><?= e($d['original_name']) ?></td>
                  <td class="num tiny"><?= e(human_filesize((int)$d['file_size'])) ?></td>
                  <td class="tiny"><?= e($d['full_name']) ?></td>
                  <td class="tiny"><?= fmt_datetime($d['created_at']) ?></td>
                  <td style="text-align:right"><a class="btn btn-sm btn-tonal" href="<?= app_url('document.php') ?>?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= icon('visibility', 'icon icon-sm') ?> View</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sidebar -->
  <aside class="stack" style="position:sticky;top:96px">
    <?php if ($b['room_number']): ?>
      <div class="card" style="background:linear-gradient(135deg,var(--md-primary),var(--md-tertiary));color:#fff">
        <div class="label-s" style="color:rgba(255,255,255,.8)">Assigned room</div>
        <div class="headline-m mb-0" style="color:#fff"><?= e($b['room_number']) ?></div>
        <div class="muted" style="color:rgba(255,255,255,.85)">Bed <?= (int)$b['bed_number'] ?> &middot; Floor <?= (int)$b['floor'] ?> &middot; <?= e($b['hostel_name']) ?></div>
        <div class="tiny mt-1" style="color:rgba(255,255,255,.8)">Check-in <?= fmt_date($b['assigned_checkin']) ?></div>
      </div>

      <div class="card">
        <h3 class="card-title mb-2">Other occupants</h3>
        <?php if (!$roommates): ?>
          <p class="tiny mb-0">This student currently has the room to themselves.</p>
        <?php else: foreach ($roommates as $rm): ?>
          <div class="row mb-1" style="gap:10px">
            <span class="avatar sm"><?= e(initials($rm['full_name'])) ?></span>
            <span class="grow">
              <span class="label-m" style="display:block"><?= e($rm['full_name']) ?></span>
              <span class="tiny"><?= e($rm['student_id']) ?> &middot; Bed <?= (int)$rm['bed_number'] ?></span>
            </span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    <?php else: ?>
      <div class="card card-flat">
        <h3 class="card-title mb-2">Vacancy for this request</h3>
        <?php $vac = vacancy_for((int)$b['hostel_id'], (int)$b['room_type_id']); ?>
        <div class="row-between mb-1">
          <span class="label-m"><?= e($b['room_type_name']) ?> in <?= e($b['block_code']) ?></span>
          <span class="chip <?= $vac > 0 ? 'chip-success' : 'chip-danger' ?>"><?= $vac ?> free bed<?= $vac === 1 ? '' : 's' ?></span>
        </div>
        <p class="tiny mb-0">
          <?= $vac > 0
              ? 'A room can be assigned immediately once the application is approved.'
              : 'No vacancy right now — consider another room type or block before approving.' ?>
        </p>
      </div>
    <?php endif; ?>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Hostel office</h3>
      <dl class="dl">
        <dt>Warden</dt><dd><?= e($b['warden_name'] ?: '—') ?></dd>
        <dt>Phone</dt><dd><?= e($b['warden_phone'] ?: '—') ?></dd>
        <dt>Policy</dt><dd><?= e(ucfirst($b['gender_policy'])) ?></dd>
      </dl>
      <a class="btn btn-text btn-sm btn-block mt-1" href="hostels.php?id=<?= (int)$b['hostel_id'] ?>">Block details</a>
    </div>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Audit trail</h3>
      <div class="timeline">
        <div class="tl-item">
          <span class="tl-dot done"><?= icon('check') ?></span>
          <div class="label-m">Submitted</div>
          <div class="tiny"><?= fmt_datetime($b['created_at']) ?> by <?= e($b['full_name']) ?></div>
        </div>
        <?php if ($b['processed_at']): ?>
          <div class="tl-item">
            <span class="tl-dot <?= $b['status'] === 'rejected' ? 'reject' : 'done' ?>"><?= $b['status'] === 'rejected' ? icon('close') : icon('check') ?></span>
            <div class="label-m"><?= e(ucfirst($b['status'] === 'assigned' ? 'Approved' : $b['status'])) ?></div>
            <div class="tiny"><?= fmt_datetime($b['processed_at']) ?><?= $b['admin_name'] ? ' by ' . e($b['admin_name']) : '' ?></div>
          </div>
        <?php else: ?>
          <div class="tl-item">
            <span class="tl-dot current"><?= icon('history') ?></span>
            <div class="label-m">Awaiting review</div>
            <div class="tiny">No decision recorded yet</div>
          </div>
        <?php endif; ?>
        <?php if ($b['assigned_at']): ?>
          <div class="tl-item">
            <span class="tl-dot done"><?= icon('key') ?></span>
            <div class="label-m">Room assigned</div>
            <div class="tiny"><?= fmt_datetime($b['assigned_at']) ?> — Room <?= e($b['room_number']) ?>, Bed <?= (int)$b['bed_number'] ?></div>
          </div>
        <?php endif; ?>
        <?php foreach (db_all($conn, 'SELECT * FROM documents WHERE booking_id = ? ORDER BY created_at DESC LIMIT 3', 'i', [$bid]) as $d): ?>
          <div class="tl-item">
            <span class="tl-dot"><?= icon('attach') ?></span>
            <div class="label-m">Document added</div>
            <div class="tiny"><?= e($d['doc_type']) ?> &middot; <?= e(time_ago($d['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>
</div>

<?php page_footer(); ?>
