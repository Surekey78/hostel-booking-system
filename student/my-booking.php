<?php
/**
 * student/my-booking.php - Full application record: timeline, documents, remarks.
 */
require_once __DIR__ . '/../includes/header.php';
$me  = require_student();
$uid = (int)$me['id'];

$bid = get_int('id');
$b   = db_one(
    $conn,
    "SELECT b.*, h.name hostel_name, h.block_code, h.location, h.warden_name, h.warden_phone, h.facilities hostel_facilities,
            rt.name room_type_name, rt.capacity, rt.price, rt.facilities type_facilities,
            u.full_name processed_by_name,
            a.id assignment_id, a.bed_number, a.checkin_date assigned_checkin, r.room_number, r.floor, r.id room_id
       FROM bookings b
       JOIN hostels h      ON h.id  = b.hostel_id
       JOIN room_types rt  ON rt.id = b.room_type_id
       LEFT JOIN users u        ON u.id = b.processed_by
       LEFT JOIN assignments a  ON a.booking_id = b.id AND a.status = 'active'
       LEFT JOIN rooms r        ON r.id = a.room_id
      WHERE b.id = ? AND b.student_id = ?",
    'ii',
    [$bid, $uid]
);

if (!$b) {
    flash_set('danger', 'That application could not be found.');
    redirect('student/my-bookings.php');
}

// ---------------- POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['document']['name'])) {
            flash_set('warning', 'Please choose a file to upload.');
        } else {
            $docType = trim((string)($_POST['doc_type'] ?? 'Supporting document'));
            $res = store_document($_FILES['document'], (int)$b['id'], $docType, $uid);
            if ($res['ok']) {
                flash_set('success', $res['message']);
                foreach (db_all($conn, "SELECT id FROM users WHERE role='admin'") as $adm) {
                    notify((int)$adm['id'], 'Document uploaded', $me['full_name'] . ' attached a document to ' . $b['reference_no'] . '.', 'info', 'admin/application-view.php?id=' . $b['id']);
                }
            } else {
                flash_set('danger', $res['message']);
            }
        }
    } elseif ($action === 'delete_doc') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $doc   = db_one($conn, 'SELECT * FROM documents WHERE id = ? AND booking_id = ? AND uploaded_by = ?', 'iii', [$docId, (int)$b['id'], $uid]);
        if ($doc) {
            $path = UPLOAD_DIR . '/' . $doc['stored_name'];
            if (is_file($path)) {
                @unlink($path);
            }
            db_exec($conn, 'DELETE FROM documents WHERE id = ?', 'i', [$docId]);
            flash_set('success', 'Document removed.');
        } else {
            flash_set('danger', 'Document not found.');
        }
    } elseif ($action === 'cancel') {
        if (in_array($b['status'], ['pending', 'approved'], true)) {
            db_exec($conn, "UPDATE bookings SET status='cancelled' WHERE id = ?", 'i', [(int)$b['id']]);
            notify($uid, 'Application cancelled', 'You cancelled application ' . $b['reference_no'] . '.', 'info', 'student/my-booking.php?id=' . $b['id']);
            flash_set('success', 'Application cancelled.');
        } else {
            flash_set('danger', 'This application can no longer be cancelled.');
        }
    }
    redirect('student/my-booking.php?id=' . $bid);
}

$docs = db_all($conn, 'SELECT * FROM documents WHERE booking_id = ? ORDER BY created_at DESC', 'i', [(int)$b['id']]);
$notifs = db_all($conn, 'SELECT * FROM notifications WHERE user_id = ? AND message LIKE ? ORDER BY created_at DESC LIMIT 8', 'is', [$uid, '%' . $b['reference_no'] . '%']);

// Timeline model
$steps = [
    ['Submitted',      fmt_datetime($b['created_at']),                    true],
    ['Under review',   $b['processed_at'] ? fmt_datetime($b['processed_at']) : 'Awaiting hostel office', in_array($b['status'], ['pending'], true) ? 'current' : true],
];
if ($b['status'] === 'rejected') {
    $steps[] = ['Rejected', fmt_datetime($b['processed_at']), 'reject'];
} elseif ($b['status'] === 'cancelled') {
    $steps[] = ['Cancelled by student', fmt_datetime($b['updated_at']), 'reject'];
} else {
    $steps[] = ['Approved', in_array($b['status'], ['approved', 'assigned'], true) ? fmt_datetime($b['processed_at']) : 'Pending', in_array($b['status'], ['approved', 'assigned'], true)];
    $steps[] = ['Room assigned', $b['status'] === 'assigned' ? ($b['room_number'] ? 'Room ' . $b['room_number'] . ', Bed ' . $b['bed_number'] : 'Assigned') : 'Pending', $b['status'] === 'assigned' ? 'current' : false];
    if ($b['status'] === 'checked_out') {
        $steps[] = ['Checked out', fmt_date($b['updated_at']), true];
    }
}

page_header('Application ' . e($b['reference_no']), 'my-bookings', e($b['hostel_name']) . ' &middot; ' . e($b['room_type_name']));
?>

<a class="btn btn-text btn-sm mb-2" href="my-bookings.php"><?= icon('arrow_back', 'icon icon-sm') ?> Back to my applications</a>

<div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:24px;align-items:start">
  <div class="stack">
    <!-- Summary -->
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h2 class="title-l mb-0"><?= e($b['reference_no']) ?></h2>
          <p class="card-sub">Submitted <?= fmt_datetime($b['created_at']) ?> &middot; <?= e(time_ago($b['created_at'])) ?></p>
        </div>
        <?= status_chip($b['status']) ?>
      </div>

      <dl class="dl">
        <dt>Hostel block</dt><dd><?= e($b['hostel_name']) ?> (<?= e($b['block_code']) ?>)</dd>
        <dt>Room type</dt><dd><?= e($b['room_type_name']) ?> — <?= (int)$b['capacity'] ?> bed<?= (int)$b['capacity'] === 1 ? '' : 's' ?></dd>
        <dt>Rate</dt><dd><?= money($b['price']) ?> per semester</dd>
        <dt>Intake period</dt><dd><?= e($b['intake_period']) ?></dd>
        <dt>Preferred check-in</dt><dd><?= fmt_date($b['checkin_date']) ?></dd>
        <dt>Roommates</dt><dd><?= (int)$b['roommates'] === 0 ? 'Applying alone' : (int)$b['roommates'] . ' additional student(s)' ?></dd>
        <?php if ($b['special_request']): ?>
          <dt>Special request</dt><dd><?= nl2br(e($b['special_request'])) ?></dd>
        <?php endif; ?>
        <?php if ($b['admin_remarks']): ?>
          <dt>Office remarks</dt><dd><?= nl2br(e($b['admin_remarks'])) ?></dd>
        <?php endif; ?>
        <?php if ($b['processed_by_name']): ?>
          <dt>Processed by</dt><dd><?= e($b['processed_by_name']) ?> &middot; <?= fmt_datetime($b['processed_at']) ?></dd>
        <?php endif; ?>
      </dl>

      <?php if ($b['status'] === 'assigned' && $b['room_number']): ?>
        <div class="alert alert-success mt-2">
          <?= icon('key') ?>
          <span>Your room has been assigned: <strong><?= e($b['hostel_name']) ?></strong>, Room <strong><?= e($b['room_number']) ?></strong>,
            Bed <strong><?= (int)$b['bed_number'] ?></strong> (Floor <?= (int)$b['floor'] ?>).
            <a href="my-assignment.php">View my room &rarr;</a></span>
        </div>
      <?php elseif ($b['status'] === 'pending'): ?>
        <div class="alert alert-warning mt-2">
          <?= icon('history') ?>
          <span>Your application is in the queue. The hostel office typically responds within 2–3 working days.</span>
        </div>
      <?php elseif ($b['status'] === 'rejected'): ?>
        <div class="alert alert-danger mt-2">
          <?= icon('error') ?>
          <span>This application was not approved. You may submit a new application for another block or room type.</span>
        </div>
      <?php endif; ?>

      <div class="row wrap mt-2" style="gap:8px">
        <?php if (in_array($b['status'], ['pending', 'approved'], true)): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <button class="btn btn-outlined" data-confirm="Cancel application <?= e($b['reference_no']) ?>?"><?= icon('close', 'icon icon-sm') ?> Cancel application</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-tonal" href="apply.php?hostel=<?= (int)$b['hostel_id'] ?>&type=<?= (int)$b['room_type_id'] ?>"><?= icon('add', 'icon icon-sm') ?> New application</a>
        <button class="btn btn-text no-print" onclick="window.print()"><?= icon('print', 'icon icon-sm') ?> Print record</button>
      </div>
    </div>

    <!-- Documents -->
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Supporting documents</h3>
          <p class="card-sub"><?= count($docs) ?> file<?= count($docs) === 1 ? '' : 's' ?> attached &middot; PDF, JPG, PNG, DOC, DOCX &middot; max 3 MB</p>
        </div>
      </div>

      <?php if ($docs): ?>
        <div class="table-wrap mb-2">
          <table class="data">
            <thead><tr><th>Type</th><th>File</th><th class="num">Size</th><th>Uploaded</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($docs as $d): ?>
                <tr>
                  <td><span class="chip chip-outline"><?= icon('description', 'icon icon-xs') ?> <?= e($d['doc_type']) ?></span></td>
                  <td class="cell-strong"><?= e($d['original_name']) ?></td>
                  <td class="num tiny"><?= e(human_filesize((int)$d['file_size'])) ?></td>
                  <td class="tiny"><?= fmt_datetime($d['created_at']) ?></td>
                  <td>
                    <div class="table-actions" style="justify-content:flex-end">
                      <a class="btn btn-sm btn-text" href="<?= app_url('document.php') ?>?id=<?= (int)$d['id'] ?>"><?= icon('download', 'icon icon-sm') ?></a>
                      <?php if (in_array($b['status'], ['pending', 'approved'], true)): ?>
                        <form method="post" style="display:inline">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="delete_doc">
                          <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                          <button class="btn btn-sm btn-text" style="--btn-fg:var(--md-error)" data-confirm="Delete this document?" aria-label="Delete document"><?= icon('delete', 'icon icon-sm') ?></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="tiny mb-2">No documents attached yet. Some hostels require a student ID copy or a guarantor letter.</p>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'approved'], true)): ?>
        <form method="post" enctype="multipart/form-data" class="card card-flat card-pad-sm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <div class="form-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) auto;gap:12px;align-items:end">
            <div class="field">
              <label class="field-label" for="doc_type">Document type</label>
              <select class="select" id="doc_type" name="doc_type">
                <?php foreach (['Student ID copy', 'Guarantor letter', 'Medical certificate', 'Payment proof', 'Parental consent', 'Other'] as $d): ?>
                  <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="field-label" for="document">Choose file</label>
              <input class="file" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
            </div>
            <button type="submit" class="btn"><?= icon('upload') ?> Upload</button>
          </div>
        </form>
      <?php else: ?>
        <p class="tiny mb-0">Documents can only be added while the application is pending or approved.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sidebar: timeline + activity -->
  <aside class="stack" style="position:sticky;top:96px">
    <div class="card">
      <h3 class="card-title mb-2">Application progress</h3>
      <div class="timeline">
        <?php foreach ($steps as [$label, $meta, $state]):
            $cls = $state === 'current' ? 'current' : ($state === 'reject' ? 'reject' : ($state === true ? 'done' : ''));
        ?>
          <div class="tl-item">
            <span class="tl-dot <?= $cls ?>">
              <?php if ($cls === 'done'): ?><?= icon('check') ?>
              <?php elseif ($cls === 'reject'): ?><?= icon('close') ?>
              <?php elseif ($cls === 'current'): ?><?= icon('history') ?><?php endif; ?>
            </span>
            <div class="label-m"><?= e($label) ?></div>
            <div class="tiny"><?= is_string($meta) ? e($meta) : '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($b['room_number']): ?>
      <div class="card" style="background:linear-gradient(135deg,var(--md-primary),var(--md-tertiary));color:#fff">
        <div class="label-s" style="color:rgba(255,255,255,.8)">Your room</div>
        <div class="headline-m mb-0" style="color:#fff"><?= e($b['room_number']) ?></div>
        <div class="muted" style="color:rgba(255,255,255,.85)">Bed <?= (int)$b['bed_number'] ?> &middot; Floor <?= (int)$b['floor'] ?> &middot; <?= e($b['hostel_name']) ?></div>
        <a class="btn mt-2" style="--btn-bg:#fff;--btn-fg:#6750A4" href="my-assignment.php"><?= icon('key') ?> View assignment</a>
      </div>
    <?php endif; ?>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Hostel office</h3>
      <dl class="dl">
        <dt>Warden</dt><dd><?= e($b['warden_name'] ?: '—') ?></dd>
        <dt>Phone</dt><dd><?= e($b['warden_phone'] ?: '—') ?></dd>
        <dt>Location</dt><dd><?= e($b['location']) ?></dd>
      </dl>
      <div class="row wrap mt-1" style="gap:6px"><?= facility_chips($b['hostel_facilities']) ?></div>
    </div>

    <?php if ($notifs): ?>
      <div class="card card-flat">
        <h3 class="card-title mb-2">Activity log</h3>
        <?php foreach ($notifs as $n): ?>
          <div class="row mb-1" style="gap:10px;align-items:flex-start">
            <span class="notif-icon <?= e($n['type']) ?>" style="width:28px;height:28px;border-radius:9px"><?= icon('info', 'icon icon-xs') ?></span>
            <span class="grow">
              <span class="label-m" style="display:block;font-size:.8125rem"><?= e($n['title']) ?></span>
              <span class="tiny"><?= e(time_ago($n['created_at'])) ?></span>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </aside>
</div>

<?php page_footer(); ?>
