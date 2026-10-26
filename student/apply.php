<?php
/**
 * student/apply.php - Submit a hostel booking request / accommodation application.
 *                     Supports optional supporting-document uploads.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_student();
$uid = (int)$me['id'];

$hostels = hostel_options();
$types   = room_type_options();
$errors  = [];

$preHostel = get_int('hostel');
$preType   = get_int('type');
$preRoom   = get_int('room');

$old = [
    'hostel_id'       => $preHostel,
    'room_type_id'    => $preType,
    'preferred_room'  => $preRoom,
    'intake_period'   => INTAKE_PERIODS[0],
    'checkin_date'    => '',
    'roommates'       => 0,
    'special_request' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($old as $k => $_) {
        if (isset($_POST[$k])) {
            $old[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
        }
    }
    $old['hostel_id']      = (int)$old['hostel_id'];
    $old['room_type_id']   = (int)$old['room_type_id'];
    $old['preferred_room'] = (int)$old['preferred_room'];
    $old['roommates']      = max(0, (int)$old['roommates']);

    // ---------- validation ----------
    $hostel = db_one($conn, 'SELECT * FROM hostels WHERE id = ? AND is_active = 1', 'i', [$old['hostel_id']]);
    $type   = db_one($conn, 'SELECT * FROM room_types WHERE id = ?', 'i', [$old['room_type_id']]);

    if (!$hostel) {
        $errors['hostel_id'] = 'Please choose a hostel block.';
    } elseif ($hostel['gender_policy'] !== 'mixed' && $me['gender'] && $hostel['gender_policy'] !== $me['gender']) {
        $errors['hostel_id'] = $hostel['name'] . ' is a ' . $hostel['gender_policy'] . '-only residence and does not match the gender on your profile.';
    }
    if (!$type) {
        $errors['room_type_id'] = 'Please choose a room type.';
    }
    if (!in_array($old['intake_period'], INTAKE_PERIODS, true)) {
        $errors['intake_period'] = 'Please select a valid intake period.';
    }
    if ($old['checkin_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['checkin_date'])) {
        $errors['checkin_date'] = 'Enter a valid preferred check-in date.';
    }
    if ($old['checkin_date'] !== '' && strtotime($old['checkin_date']) < strtotime(date('Y-m-d') . ' -30 days')) {
        $errors['checkin_date'] = 'The check-in date cannot be that far in the past.';
    }
    if ($hostel && $type) {
        $vac = vacancy_for($old['hostel_id'], $old['room_type_id']);
        if ($vac <= 0) {
            $errors['room_type_id'] = 'There are currently no vacant ' . $type['name'] . ' rooms in ' . $hostel['name'] . '. Choose another option or join the waitlist from the hostel page.';
        }
    }
    if ($old['preferred_room'] > 0 && $hostel && $type) {
        $room = db_one($conn, 'SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id WHERE r.id=?', 'i', [$old['preferred_room']]);
        if (!$room || (int)$room['hostel_id'] !== $old['hostel_id'] || (int)$room['room_type_id'] !== $old['room_type_id']) {
            $errors['preferred_room'] = 'The preferred room does not belong to the selected block / room type.';
        } elseif ($room['status'] !== 'available' || (int)$room['occupied'] >= (int)$room['capacity']) {
            $errors['preferred_room'] = 'That room is no longer vacant.';
        }
    }
    if (mb_strlen((string)$old['special_request']) > 1000) {
        $errors['special_request'] = 'Please keep your request under 1000 characters.';
    }

    // duplicate open application for the same period + block + type
    $dupe = db_one(
        $conn,
        "SELECT id, reference_no FROM bookings
          WHERE student_id = ? AND intake_period = ? AND hostel_id = ? AND room_type_id = ?
            AND status IN ('pending','approved','assigned') LIMIT 1",
        'isii',
        [$uid, $old['intake_period'], $old['hostel_id'], $old['room_type_id']]
    );
    if ($dupe) {
        $errors['form'] = 'You already have an open application (' . $dupe['reference_no'] . ') for this block, room type and intake period.';
    }

    // ---------- insert ----------
    if (!$errors) {
        $ref     = next_reference();
        $status  = 'pending';
        $ci      = $old['checkin_date'] !== '' ? $old['checkin_date'] : null;
        $request = $old['preferred_room'] > 0
            ? trim('Preferred room: ' . (db_one($conn, 'SELECT room_number FROM rooms WHERE id=?', 'i', [$old['preferred_room']])['room_number'] ?? '') . '. ' . $old['special_request'])
            : trim((string)$old['special_request']);

        db_exec(
            $conn,
            'INSERT INTO bookings (reference_no, student_id, hostel_id, room_type_id, intake_period, checkin_date, roommates, special_request, status)
             VALUES (?,?,?,?,?,?,?,?,?)',
            'siiisssis',
            [$ref, $uid, $old['hostel_id'], $old['room_type_id'], $old['intake_period'], $ci, $old['roommates'], $request ?: null, $status]
        );
        $bookingId = mysqli_insert_id($conn);

        // ---------- documents ----------
        $uploaded = 0;
        if (!empty($_FILES['documents']['name'][0])) {
            $files = $_FILES['documents'];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $docType = trim((string)($_POST['doc_type'][$i] ?? 'Supporting document'));
                $single  = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
                $res = store_document($single, $bookingId, $docType ?: 'Supporting document', $uid);
                if ($res['ok']) {
                    $uploaded++;
                } else {
                    flash_set('warning', 'Document "' . $single['name'] . '" was not uploaded: ' . $res['message']);
                }
            }
        }

        notify($uid, 'Application received', 'Your application ' . $ref . ' for ' . $hostel['name'] . ' (' . $type['name'] . ') was submitted and is awaiting review.', 'info', 'student/my-booking.php?id=' . $bookingId);
        foreach (db_all($conn, "SELECT id FROM users WHERE role='admin'") as $adm) {
            notify((int)$adm['id'], 'New application: ' . $ref, $me['full_name'] . ' (' . $me['student_id'] . ') applied for a ' . $type['name'] . ' at ' . $hostel['name'] . ' — ' . $old['intake_period'] . '.', 'warning', 'admin/applications.php');
        }

        flash_set('success', 'Application ' . $ref . ' submitted successfully' . ($uploaded ? ' with ' . $uploaded . ' document' . ($uploaded === 1 ? '' : 's') : '') . '. You will be notified once it is reviewed.');
        redirect('student/my-booking.php?id=' . $bookingId);
    }
}

// Rooms of the pre-selected hostel/type (for the preferred-room dropdown)
$roomChoices = [];
if ($preHostel > 0 && $preType > 0) {
    $roomChoices = db_all(
        $conn,
        "SELECT r.*, rt.capacity FROM rooms r JOIN room_types rt ON rt.id = r.room_type_id
          WHERE r.hostel_id = ? AND r.room_type_id = ? AND r.status='available' AND r.occupied < rt.capacity
          ORDER BY r.floor, LENGTH(r.room_number), r.room_number",
        'ii',
        [$preHostel, $preType]
    );
}

page_header('New Application', 'apply', 'Hostel booking request / accommodation application form');
?>

<a class="btn btn-text btn-sm mb-2" href="hostels.php"><?= icon('arrow_back', 'icon icon-sm') ?> Browse hostels</a>

<div class="grid" style="grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:24px;align-items:start">
  <form method="post" action="apply.php" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>

    <?php if (!empty($errors['form'])): ?>
      <div class="alert alert-danger mb-2"><?= icon('error') ?><span><?= e($errors['form']) ?></span></div>
    <?php endif; ?>

    <!-- Step 1 -->
    <div class="card card-pad-lg mb-3 anim-rise">
      <div class="row mb-2" style="gap:12px">
        <span class="step-badge" style="width:40px;height:40px;font-size:1rem;border-radius:14px">1</span>
        <div>
          <h3 class="card-title mb-0">Choose your accommodation</h3>
          <p class="card-sub">Select the hostel block and the room type you are applying for.</p>
        </div>
      </div>

      <div class="form-grid">
        <div class="field <?= isset($errors['hostel_id']) ? 'field-error' : '' ?>">
          <label class="field-label" for="hostel_id">Hostel block <span class="req">*</span></label>
          <select class="select" id="hostel_id" name="hostel_id" required>
            <option value="">Select a block&hellip;</option>
            <?php foreach ($hostels as $h):
                $eligible = $h['gender_policy'] === 'mixed' || !$me['gender'] || $h['gender_policy'] === $me['gender'];
            ?>
              <option value="<?= (int)$h['id'] ?>" <?= (int)$old['hostel_id'] === (int)$h['id'] ? 'selected' : '' ?> <?= $eligible ? '' : 'disabled' ?>>
                <?= e($h['block_code']) ?> — <?= e($h['name']) ?><?= $eligible ? '' : ' (not eligible)' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['hostel_id'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['hostel_id']) ?></span>
          <?php else: ?><span class="help-text">Your profile gender: <strong><?= e(ucfirst((string)$me['gender'] ?: 'not set')) ?></strong>. Gender-specific blocks that do not match are disabled.</span><?php endif; ?>
        </div>

        <div class="field <?= isset($errors['room_type_id']) ? 'field-error' : '' ?>">
          <label class="field-label" for="room_type_id">Room type <span class="req">*</span></label>
          <select class="select" id="room_type_id" name="room_type_id" required>
            <option value="">Select a room type&hellip;</option>
            <?php foreach ($types as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= (int)$old['room_type_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                <?= e($t['name']) ?> — <?= (int)$t['capacity'] ?> bed<?= (int)$t['capacity'] === 1 ? '' : 's' ?> — <?= money($t['price']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['room_type_id'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['room_type_id']) ?></span><?php endif; ?>
        </div>
      </div>

      <div class="field mt-2 <?= isset($errors['preferred_room']) ? 'field-error' : '' ?>">
        <label class="field-label" for="preferred_room">Preferred room <span class="muted">(optional)</span></label>
        <select class="select" id="preferred_room" name="preferred_room">
          <option value="0">No preference — assign any vacant room</option>
          <?php foreach ($roomChoices as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= (int)$old['preferred_room'] === (int)$r['id'] ? 'selected' : '' ?>>
              Room <?= e($r['room_number']) ?> (Floor <?= (int)$r['floor'] ?>) — <?= (int)$r['capacity'] - (int)$r['occupied'] ?> bed(s) free
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['preferred_room'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['preferred_room']) ?></span>
        <?php elseif (!$roomChoices && $old['hostel_id'] && $old['room_type_id']): ?><span class="help-text">Reload this page after choosing a block and type to see vacant rooms.</span>
        <?php else: ?><span class="help-text">Administrators will honour this where possible; otherwise the lowest vacant room is assigned.</span><?php endif; ?>
      </div>
    </div>

    <!-- Step 2 -->
    <div class="card card-pad-lg mb-3 anim-rise anim-rise-1">
      <div class="row mb-2" style="gap:12px">
        <span class="step-badge" style="width:40px;height:40px;font-size:1rem;border-radius:14px">2</span>
        <div>
          <h3 class="card-title mb-0">Intake &amp; stay details</h3>
          <p class="card-sub">Tell us when you intend to move in.</p>
        </div>
      </div>

      <div class="form-grid">
        <div class="field <?= isset($errors['intake_period']) ? 'field-error' : '' ?>">
          <label class="field-label" for="intake_period">Intake period <span class="req">*</span></label>
          <select class="select" id="intake_period" name="intake_period" required>
            <?php foreach (INTAKE_PERIODS as $p): ?>
              <option value="<?= e($p) ?>" <?= $old['intake_period'] === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['intake_period'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['intake_period']) ?></span><?php endif; ?>
        </div>

        <div class="field <?= isset($errors['checkin_date']) ? 'field-error' : '' ?>">
          <label class="field-label" for="checkin_date">Preferred check-in date</label>
          <input class="input" type="date" id="checkin_date" name="checkin_date" value="<?= e($old['checkin_date']) ?>" min="<?= date('Y-m-d', strtotime('-30 days')) ?>">
          <?php if (isset($errors['checkin_date'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['checkin_date']) ?></span><?php endif; ?>
        </div>

        <div class="field">
          <label class="field-label" for="roommates">Applying with roommates?</label>
          <select class="select" id="roommates" name="roommates">
            <?php for ($n = 0; $n <= 3; $n++): ?>
              <option value="<?= $n ?>" <?= (int)$old['roommates'] === $n ? 'selected' : '' ?>><?= $n === 0 ? 'Just me' : $n . ' additional student' . ($n === 1 ? '' : 's') ?></option>
            <?php endfor; ?>
          </select>
          <span class="help-text">Group applications are placed in the same room when capacity allows.</span>
        </div>
      </div>

      <div class="field mt-2 <?= isset($errors['special_request']) ? 'field-error' : '' ?>">
        <label class="field-label" for="special_request">Special request / remarks</label>
        <textarea class="textarea" id="special_request" name="special_request" rows="3"
                  placeholder="e.g. medical condition, preferred floor, need for a study desk…"><?= e((string)$old['special_request']) ?></textarea>
        <?php if (isset($errors['special_request'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['special_request']) ?></span><?php endif; ?>
      </div>
    </div>

    <!-- Step 3 -->
    <div class="card card-pad-lg mb-3 anim-rise anim-rise-2">
      <div class="row mb-2" style="gap:12px">
        <span class="step-badge" style="width:40px;height:40px;font-size:1rem;border-radius:14px">3</span>
        <div>
          <h3 class="card-title mb-0">Supporting documents <span class="muted" style="font-weight:400;font-size:1rem">(optional)</span></h3>
          <p class="card-sub">PDF, JPG, PNG, DOC or DOCX &middot; up to 3 MB each &middot; you can add more after submitting.</p>
        </div>
      </div>

      <div id="doc-rows" class="stack" style="gap:12px">
        <div class="doc-row card card-flat card-pad-sm">
          <div class="form-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:12px">
            <div class="field">
              <label class="field-label">Document type</label>
              <select class="select" name="doc_type[]">
                <?php foreach (['Student ID copy', 'Guarantor letter', 'Medical certificate', 'Payment proof', 'Parental consent', 'Other'] as $d): ?>
                  <option value="<?= e($d) ?>"><?= e($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="field-label">File</label>
              <input class="file" type="file" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
            </div>
          </div>
        </div>
      </div>

      <button type="button" class="btn btn-text btn-sm mt-1" id="add-doc"><?= icon('add', 'icon icon-sm') ?> Add another document</button>
    </div>

    <!-- Step 4 -->
    <div class="card card-pad-lg anim-rise anim-rise-3">
      <div class="row mb-2" style="gap:12px">
        <span class="step-badge" style="width:40px;height:40px;font-size:1rem;border-radius:14px">4</span>
        <div>
          <h3 class="card-title mb-0">Review &amp; submit</h3>
          <p class="card-sub">Submitting creates a pending application that the hostel office will review.</p>
        </div>
      </div>

      <label class="check mb-2">
        <input type="checkbox" name="declare" value="1" required>
        <span class="check-box"><?= icon('check') ?></span>
        <span class="check-text">I declare that the information provided is true and complete, and I accept the hostel rules and regulations.</span>
      </label>

      <div class="row wrap" style="gap:10px">
        <button type="submit" class="btn btn-lg"><?= icon('upload') ?> Submit application</button>
        <a class="btn btn-lg btn-outlined" href="hostels.php">Cancel</a>
      </div>
    </div>
  </form>

  <!-- Sidebar -->
  <aside class="stack" style="position:sticky;top:96px">
    <div class="card">
      <h3 class="card-title mb-2">Applicant</h3>
      <div class="row mb-2" style="gap:12px">
        <span class="avatar"><?= e(initials($me['full_name'])) ?></span>
        <div class="grow" style="min-width:0">
          <div class="label-m"><?= e($me['full_name']) ?></div>
          <div class="tiny"><?= e($me['student_id']) ?> &middot; <?= e($me['year_level'] ?: 'Student') ?></div>
        </div>
      </div>
      <dl class="dl">
        <dt>Programme</dt><dd><?= e($me['programme'] ?: '—') ?></dd>
        <dt>Email</dt><dd style="word-break:break-all"><?= e($me['email']) ?></dd>
        <dt>Phone</dt><dd><?= e($me['phone'] ?: '—') ?></dd>
        <dt>Gender</dt><dd><?= e(ucfirst((string)$me['gender'] ?: '—')) ?></dd>
      </dl>
      <a class="btn btn-text btn-sm btn-block mt-1" href="profile.php"><?= icon('edit', 'icon icon-sm') ?> Update my details</a>
    </div>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Live vacancy</h3>
      <?php foreach ($hostels as $h):
          $sum = hostel_summary((int)$h['id']); ?>
        <div class="hbar-row" style="grid-template-columns:1fr auto">
          <span class="hbar-label"><?= e($h['block_code']) ?> &middot; <?= e($h['name']) ?></span>
          <span class="chip <?= (int)$sum['vacant_beds'] > 0 ? 'chip-success' : 'chip-neutral' ?>"><?= (int)$sum['vacant_beds'] ?> free</span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card card-flat">
      <div class="row" style="gap:12px">
        <span class="stat-icon tone-5"><?= icon('info') ?></span>
        <div class="tiny">Applications are reviewed by the hostel office within 2–3 working days. You will receive a notification the moment the status changes.</div>
      </div>
    </div>
  </aside>
</div>

<script>
document.getElementById('add-doc').addEventListener('click', function () {
  var wrap = document.getElementById('doc-rows');
  if (wrap.children.length >= 5) { alert('You can attach up to 5 documents per application.'); return; }
  var row = wrap.firstElementChild.cloneNode(true);
  row.querySelectorAll('select, input').forEach(function (el) { if (el.type === 'file') el.value = ''; });
  wrap.appendChild(row);
});
// Refresh the preferred-room list when block/type change (client-side reload keeps it simple + correct)
(function () {
  var h = document.getElementById('hostel_id'), t = document.getElementById('room_type_id');
  function reload() {
    if (!h.value || !t.value) return;
    var url = new URL(window.location.href);
    url.searchParams.set('hostel', h.value);
    url.searchParams.set('type', t.value);
    window.location.href = url.toString();
  }
  h.addEventListener('change', reload);
  t.addEventListener('change', reload);
})();
</script>

<?php page_footer(); ?>
