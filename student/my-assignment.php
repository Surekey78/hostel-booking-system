<?php



require_once __DIR__ . '/../includes/header.php';
$me  = require_student();
$uid = (int)$me['id'];

$a = db_one(
    $conn,
    "SELECT a.*, r.room_number, r.floor, r.occupied, r.status room_status,
            h.name hostel_name, h.block_code, h.location, h.description, h.facilities,
            h.warden_name, h.warden_phone, h.gender_policy,
            rt.name room_type_name, rt.capacity, rt.price, rt.facilities type_facilities,
            b.reference_no, b.intake_period,
            adm.full_name assigned_by_name
       FROM assignments a
       JOIN rooms r        ON r.id  = a.room_id
       JOIN hostels h      ON h.id  = r.hostel_id
       JOIN room_types rt  ON rt.id = r.room_type_id
       JOIN bookings b     ON b.id  = a.booking_id
       LEFT JOIN users adm ON adm.id = a.assigned_by
      WHERE a.student_id = ? AND a.status = 'active'
      ORDER BY a.created_at DESC LIMIT 1",
    'i',
    [$uid]
);

$roommates = [];
if ($a) {
    $roommates = db_all(
        $conn,
        "SELECT u.full_name, u.student_id, u.programme, u.year_level, a.bed_number, a.checkin_date
           FROM assignments a JOIN users u ON u.id = a.student_id
          WHERE a.room_id = ? AND a.status = 'active' AND a.student_id <> ?
          ORDER BY a.bed_number",
        'ii',
        [(int)$a['room_id'], $uid]
    );
}

$history = db_all(
    $conn,
    "SELECT a.*, r.room_number, h.name hostel_name, h.block_code, rt.name room_type_name
       FROM assignments a
       JOIN rooms r       ON r.id = a.room_id
       JOIN hostels h     ON h.id = r.hostel_id
       JOIN room_types rt ON rt.id = r.room_type_id
      WHERE a.student_id = ?
      ORDER BY a.created_at DESC",
    'i',
    [$uid]
);

page_header('My Room', 'my-assignment', $a ? 'Your confirmed accommodation' : 'No active room assignment');
?>

<?php if (!$a): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= icon('key') ?></div>
      <h3 class="title-m mb-0">No room assigned yet</h3>
      <p class="tiny mb-2" style="max-width:52ch">
        A room assignment appears here once the hostel office approves your application and converts it into a room record.
      </p>
      <div class="row wrap" style="justify-content:center;gap:8px">
        <a class="btn" href="apply.php"><?= icon('add') ?> Submit an application</a>
        <a class="btn btn-outlined" href="my-bookings.php"><?= icon('assignment') ?> Track applications</a>
      </div>
    </div>
  </div>
<?php else: ?>

  <section class="hero hero-primary mb-3">
    <span class="blur-shape" style="width:320px;height:320px;background:rgba(255,255,255,.18);top:-100px;right:-60px" aria-hidden="true"></span>
    <span class="blur-shape" style="width:240px;height:240px;background:rgba(232,222,248,.26);bottom:-110px;left:12%" aria-hidden="true"></span>
    <div class="row-between wrap" style="gap:24px">
      <div>
        <span class="chip glass mb-2"><?= icon('check_circle', 'icon icon-xs') ?> Confirmed &middot; <?= e($a['reference_no']) ?></span>
        <h2 class="display-l mb-1" style="font-size:clamp(2rem,4.5vw,3rem)">Room <?= e($a['room_number']) ?></h2>
        <p class="body-l muted mb-2"><?= e($a['hostel_name']) ?> (<?= e($a['block_code']) ?>) &middot; Floor <?= (int)$a['floor'] ?> &middot; Bed <?= (int)$a['bed_number'] ?></p>
        <div class="row wrap" style="gap:8px">
          <span class="chip glass"><?= icon('bed', 'icon icon-xs') ?> <?= e($a['room_type_name']) ?></span>
          <span class="chip glass"><?= icon('people', 'icon icon-xs') ?> <?= (int)$a['capacity'] ?> bed<?= (int)$a['capacity'] === 1 ? '' : 's' ?></span>
          <span class="chip glass"><?= icon('money', 'icon icon-xs') ?> <?= money($a['price']) ?> / semester</span>
          <span class="chip glass"><?= icon('calendar', 'icon icon-xs') ?> <?= e($a['intake_period']) ?></span>
        </div>
      </div>
      <div class="glass card-pad-lg" style="text-align:center;min-width:200px;color:#fff">
        <div class="label-s" style="color:rgba(255,255,255,.8)">Your bed</div>
        <div style="font-size:4rem;line-height:1;font-weight:500"><?= (int)$a['bed_number'] ?></div>
        <div class="tiny" style="color:rgba(255,255,255,.8)">of <?= (int)$a['capacity'] ?> in this room</div>
        <div class="bar mt-2" style="background:rgba(255,255,255,.25)">
          <span class="bar-fill" style="width:<?= round(((int)$a['occupied'] / max(1, (int)$a['capacity'])) * 100) ?>%;background:#fff"></span>
        </div>
      </div>
    </div>
  </section>

  <div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:24px;align-items:start">
    <div class="stack">
      <div class="card card-pad-lg">
        <div class="card-header">
          <div>
            <h3 class="card-title">Assignment record</h3>
            <p class="card-sub">Issued <?= fmt_datetime($a['created_at']) ?><?= $a['assigned_by_name'] ? ' by ' . e($a['assigned_by_name']) : '' ?></p>
          </div>
          <button class="btn btn-text btn-sm no-print" onclick="window.print()"><?= icon('print', 'icon icon-sm') ?> Print</button>
        </div>
        <dl class="dl">
          <dt>Assignment ID</dt><dd>#<?= str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT) ?></dd>
          <dt>Application</dt><dd><?= e($a['reference_no']) ?></dd>
          <dt>Hostel block</dt><dd><?= e($a['hostel_name']) ?> (<?= e($a['block_code']) ?>)</dd>
          <dt>Location</dt><dd><?= e($a['location']) ?></dd>
          <dt>Room number</dt><dd><?= e($a['room_number']) ?> — Floor <?= (int)$a['floor'] ?></dd>
          <dt>Bed number</dt><dd><?= (int)$a['bed_number'] ?> of <?= (int)$a['capacity'] ?></dd>
          <dt>Room type</dt><dd><?= e($a['room_type_name']) ?></dd>
          <dt>Intake period</dt><dd><?= e($a['intake_period']) ?></dd>
          <dt>Check-in date</dt><dd><?= fmt_date($a['checkin_date']) ?></dd>
          <dt>Semester rate</dt><dd><?= money($a['price']) ?></dd>
          <dt>Status</dt><dd><span class="chip chip-success"><span class="chip-dot"></span><?= e(ucfirst($a['status'])) ?></span></dd>
        </dl>
      </div>

      <div class="card card-pad-lg">
        <h3 class="card-title mb-1">Room facilities</h3>
        <p class="card-sub mb-2">Included with <?= e($a['room_type_name']) ?></p>
        <div class="row wrap mb-2" style="gap:6px"><?= facility_chips($a['type_facilities']) ?></div>
        <h3 class="card-title mb-1">Block facilities</h3>
        <div class="row wrap" style="gap:6px"><?= facility_chips($a['facilities']) ?></div>
      </div>

      <div class="card card-pad-lg">
        <div class="card-header">
          <div>
            <h3 class="card-title">Roommates</h3>
            <p class="card-sub"><?= (int)$a['occupied'] ?> of <?= (int)$a['capacity'] ?> beds occupied</p>
          </div>
        </div>
        <div class="stack" style="gap:10px">
          <div class="card card-flat card-pad-sm row" style="gap:12px;box-shadow:inset 0 0 0 2px var(--md-primary)">
            <span class="avatar"><?= e(initials($me['full_name'])) ?></span>
            <span class="grow">
              <span class="label-m" style="display:block"><?= e($me['full_name']) ?> <span class="chip chip-primary" style="height:22px">You</span></span>
              <span class="tiny"><?= e($me['student_id']) ?> &middot; <?= e($me['programme'] ?: 'Student') ?> &middot; Bed <?= (int)$a['bed_number'] ?></span>
            </span>
          </div>
          <?php foreach ($roommates as $rm): ?>
            <div class="card card-flat card-pad-sm row" style="gap:12px">
              <span class="avatar sm"><?= e(initials($rm['full_name'])) ?></span>
              <span class="grow">
                <span class="label-m" style="display:block"><?= e($rm['full_name']) ?></span>
                <span class="tiny"><?= e($rm['student_id']) ?> &middot; <?= e($rm['programme'] ?: 'Student') ?> &middot; Bed <?= (int)$rm['bed_number'] ?> &middot; since <?= fmt_date($rm['checkin_date']) ?></span>
              </span>
            </div>
          <?php endforeach; ?>
          <?php for ($i = count($roommates) + 1; $i < (int)$a['capacity']; $i++): ?>
            <div class="card card-flat card-pad-sm row" style="gap:12px;opacity:.6">
              <span class="avatar sm" style="background:var(--md-surface-container-low);color:var(--md-on-surface-variant)"><?= $i + 1 ?></span>
              <span class="grow"><span class="label-m" style="display:block">Vacant bed</span><span class="tiny">Available for the next applicant</span></span>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <?php if (count($history) > 1): ?>
        <div class="card">
          <h3 class="card-title mb-2">Assignment history</h3>
          <div class="table-wrap">
            <table class="data">
              <thead><tr><th>Hostel</th><th>Room</th><th>Bed</th><th>Type</th><th>Check-in</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td><?= e($h['hostel_name']) ?><div class="cell-sub"><?= e($h['block_code']) ?></div></td>
                    <td class="cell-strong"><?= e($h['room_number']) ?></td>
                    <td><?= (int)$h['bed_number'] ?></td>
                    <td><?= e($h['room_type_name']) ?></td>
                    <td class="tiny"><?= fmt_date($h['checkin_date']) ?></td>
                    <td><span class="chip <?= $h['status'] === 'active' ? 'chip-success' : 'chip-neutral' ?>"><?= e(ucfirst(str_replace('_', ' ', $h['status']))) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <aside class="stack" style="position:sticky;top:96px">
      <div class="card">
        <h3 class="card-title mb-2">Hostel office &amp; warden</h3>
        <div class="row mb-2" style="gap:12px">
          <span class="stat-icon tone-3"><?= icon('person') ?></span>
          <div>
            <div class="label-m"><?= e($a['warden_name'] ?: 'Warden on duty') ?></div>
            <div class="tiny"><?= e($a['warden_phone'] ?: 'Contact the hostel office') ?></div>
          </div>
        </div>
        <dl class="dl">
          <dt>Block</dt><dd><?= e($a['block_code']) ?></dd>
          <dt>Location</dt><dd><?= e($a['location']) ?></dd>
          <dt>Policy</dt><dd><?= e(ucfirst($a['gender_policy'])) ?> residence</dd>
        </dl>
        <p class="tiny mt-1 mb-0"><?= e($a['description']) ?></p>
      </div>

      <div class="card card-flat">
        <h3 class="card-title mb-2">Key collection checklist</h3>
        <ul class="pricing-list">
          <?php foreach ([
              'Bring your student ID card',
              'Report to the block warden within 3 working days',
              'Sign the room inventory form on arrival',
              'Report existing damage before moving in',
              'Return the key at the end of the intake period',
          ] as $c): ?>
            <li><span style="color:var(--md-primary);display:flex"><?= icon('check', 'icon icon-sm') ?></span><span class="tiny" style="align-self:center"><?= e($c) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="card card-flat">
        <div class="row" style="gap:12px">
          <span class="stat-icon tone-5"><?= icon('warning') ?></span>
          <div class="tiny">Need a room transfer or reporting an issue? Contact the hostel office — transfers are handled by administrators and will update this record automatically.</div>
        </div>
      </div>
    </aside>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
