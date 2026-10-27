<?php



require_once __DIR__ . '/../includes/header.php';
$me = require_student();
$uid = (int)$me['id'];


$counts = db_one(
    $conn,
    "SELECT
        COUNT(*) total,
        SUM(status='pending')     pending,
        SUM(status='approved')    approved,
        SUM(status='assigned')    assigned,
        SUM(status='rejected')    rejected,
        SUM(status='cancelled')   cancelled
     FROM bookings WHERE student_id = ?",
    'i',
    [$uid]
);

$assignment = db_one(
    $conn,
    "SELECT a.*, r.room_number, r.floor, h.name hostel_name, h.block_code, h.location,
            rt.name room_type_name, rt.capacity, rt.price, u.full_name warden, h.warden_name, h.warden_phone
       FROM assignments a
       JOIN rooms r      ON r.id = a.room_id
       JOIN hostels h    ON h.id = r.hostel_id
       JOIN room_types rt ON rt.id = r.room_type_id
       LEFT JOIN users u ON u.id = a.student_id
      WHERE a.student_id = ? AND a.status = 'active'
      ORDER BY a.created_at DESC LIMIT 1",
    'i',
    [$uid]
);

$latest = db_all(
    $conn,
    "SELECT b.*, h.name hostel_name, h.block_code, rt.name room_type_name, rt.capacity, rt.price
       FROM bookings b
       JOIN hostels h     ON h.id = b.hostel_id
       JOIN room_types rt ON rt.id = b.room_type_id
      WHERE b.student_id = ?
      ORDER BY b.created_at DESC LIMIT 5",
    'i',
    [$uid]
);

$hostelRows = db_all($conn, 'SELECT * FROM v_hostel_occupancy WHERE vacant_beds > 0 ORDER BY vacant_beds DESC LIMIT 5');
$totalVacant = total_vacancy();

page_header('My Dashboard', 'dashboard', 'Welcome back, ' . e($me['full_name']), [
    ['label' => 'Apply for a room', 'href' => 'apply.php', 'class' => '', 'icon' => 'add'],
]);
?>


<section class="hero hero-primary mb-3 anim-rise">
  <span class="blur-shape" style="width:320px;height:320px;background:rgba(255,255,255,.18);top:-100px;right:-70px" aria-hidden="true"></span>
  <span class="blur-shape" style="width:240px;height:240px;background:rgba(232,222,248,.24);bottom:-110px;left:5%" aria-hidden="true"></span>
  <div class="row-between wrap" style="gap:24px">
    <div>
      <span class="chip glass mb-2"><?= icon('calendar', 'icon icon-xs') ?> <?= e(INTAKE_PERIODS[0]) ?></span>
      <h2 class="headline-l mb-1">Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', $me['full_name'])[0]) ?>.</h2>
      <p class="body-l muted mb-2" style="max-width:56ch">
        <?php if ($assignment): ?>
          Your accommodation is confirmed: <strong><?= e($assignment['hostel_name']) ?></strong>,
          Room <?= e($assignment['room_number']) ?> (Bed <?= (int)$assignment['bed_number'] ?>).
        <?php elseif ((int)$counts['pending'] > 0): ?>
          You have <?= (int)$counts['pending'] ?> application<?= (int)$counts['pending'] === 1 ? '' : 's' ?> awaiting review by the hostel office.
        <?php else: ?>
          You have not applied for accommodation yet. Browse the hostel blocks and submit an application in a few minutes.
        <?php endif; ?>
      </p>
      <div class="row wrap">
        <?php if ($assignment): ?>
          <a class="btn" style="--btn-bg:#fff;--btn-fg:#6750A4" href="my-assignment.php"><?= icon('key') ?> View my room</a>
          <a class="btn glass" href="my-bookings.php"><?= icon('assignment') ?> Application history</a>
        <?php else: ?>
          <a class="btn" style="--btn-bg:#fff;--btn-fg:#6750A4" href="apply.php"><?= icon('add') ?> Start an application</a>
          <a class="btn glass" href="search-rooms.php"><?= icon('search') ?> Search rooms</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="glass card-pad-lg" style="min-width:240px;color:#fff;text-align:center">
      <div class="label-s" style="color:rgba(255,255,255,.8)">Beds vacant campus-wide</div>
      <div style="font-size:3.25rem;line-height:1.1;font-weight:500" data-count="<?= $totalVacant ?>"></div>
      <div class="tiny" style="color:rgba(255,255,255,.8)"><?= count($hostelRows) ?> blocks with availability</div>
    </div>
  </div>
</section>


<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['assignment',  'tone-1', (int)$counts['total'],    'Total applications'],
      ['history',     'tone-5', (int)$counts['pending'],  'Pending review'],
      ['check_circle','tone-4', (int)$counts['assigned'] + (int)$counts['approved'], 'Approved / assigned'],
      ['key',         'tone-3', $assignment ? 1 : 0,     'Active room'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $val, $label]): ?>
    <div class="card card-hover anim-rise anim-rise-<?= $i + 1 ?>">
      <div class="stat">
        <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
        <span class="stat-value"><?= $val ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:24px;align-items:start">
  
  <div class="stack">
    <?php if ($assignment): ?>
      <div class="card card-pad-lg" style="box-shadow:var(--e2)">
        <div class="card-header">
          <div>
            <h3 class="card-title">My accommodation</h3>
            <p class="card-sub">Confirmed room assignment</p>
          </div>
          <span class="chip chip-success chip-lg"><span class="chip-dot"></span> Active</span>
        </div>
        <div class="grid g-3" style="gap:16px">
          <div class="card card-flat card-pad-sm">
            <div class="tiny">Hostel block</div>
            <div class="title-m mb-0"><?= e($assignment['hostel_name']) ?></div>
            <div class="tiny"><?= e($assignment['block_code']) ?> &middot; <?= e($assignment['location']) ?></div>
          </div>
          <div class="card card-flat card-pad-sm">
            <div class="tiny">Room / Bed</div>
            <div class="title-m mb-0"><?= e($assignment['room_number']) ?> &middot; Bed <?= (int)$assignment['bed_number'] ?></div>
            <div class="tiny">Floor <?= (int)$assignment['floor'] ?> &middot; <?= e($assignment['room_type_name']) ?></div>
          </div>
          <div class="card card-flat card-pad-sm">
            <div class="tiny">Warden</div>
            <div class="title-m mb-0"><?= e($assignment['warden_name'] ?: '—') ?></div>
            <div class="tiny"><?= e($assignment['warden_phone'] ?: '—') ?></div>
          </div>
        </div>
        <div class="row-between mt-2 wrap">
          <span class="tiny">Check-in: <strong><?= fmt_date($assignment['checkin_date']) ?></strong> &middot; Rate: <strong><?= money($assignment['price']) ?></strong> / semester</span>
          <a class="btn btn-tonal btn-sm" href="my-assignment.php">Full details <?= icon('arrow_back', 'icon icon-sm') ?></a>
        </div>
      </div>
    <?php endif; ?>

    
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Recent applications</h3>
          <p class="card-sub">Your last <?= count($latest) ?> submission<?= count($latest) === 1 ? '' : 's' ?></p>
        </div>
        <a class="btn btn-text btn-sm" href="my-bookings.php">View all</a>
      </div>

      <?php if (!$latest): ?>
        <div class="empty">
          <div class="empty-icon"><?= icon('assignment') ?></div>
          <p class="body-m mb-0">No applications yet</p>
          <p class="tiny mb-2">Submit your first accommodation application to get started.</p>
          <a class="btn" href="apply.php"><?= icon('add') ?> New application</a>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr><th>Reference</th><th>Hostel</th><th>Room type</th><th>Submitted</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($latest as $b): ?>
                <tr>
                  <td class="cell-strong"><?= e($b['reference_no']) ?></td>
                  <td><?= e($b['hostel_name']) ?><div class="cell-sub"><?= e($b['block_code']) ?></div></td>
                  <td><?= e($b['room_type_name']) ?><div class="cell-sub"><?= (int)$b['capacity'] ?> beds &middot; <?= money($b['price']) ?></div></td>
                  <td class="tiny"><?= fmt_date($b['created_at']) ?></td>
                  <td><?= status_chip($b['status']) ?></td>
                  <td><a class="btn btn-text btn-sm" href="my-booking.php?id=<?= (int)$b['id'] ?>">Details</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Where is space available?</h3>
          <p class="card-sub">Live vacancy by hostel block</p>
        </div>
        <a class="btn btn-text btn-sm" href="hostels.php">Browse hostels</a>
      </div>
      <?php foreach ($hostelRows as $h):
          $rate = (float)$h['occupancy_rate']; ?>
        <div class="hbar-row">
          <span class="hbar-label"><?= e($h['block_code']) ?> &middot; <?= e($h['name']) ?></span>
          <span class="bar bar-lg"><span class="bar-fill <?= $rate >= 90 ? 'bar-full' : ($rate >= 70 ? 'bar-high' : 'bar-low') ?>" style="width:<?= $rate ?>%"></span></span>
          <span class="hbar-value"><?= (int)$h['vacant_beds'] ?> free</span>
        </div>
      <?php endforeach; ?>
      <?php if (!$hostelRows): ?><p class="tiny mb-0">All blocks are currently full. Join the waitlist by submitting an application anyway.</p><?php endif; ?>
    </div>
  </div>

  
  <div class="stack">
    <div class="card">
      <h3 class="card-title mb-2">Quick actions</h3>
      <div class="stack" style="gap:10px">
        <a class="btn btn-tonal btn-block" href="apply.php"><?= icon('add') ?> New application</a>
        <a class="btn btn-outlined btn-block" href="search-rooms.php"><?= icon('search') ?> Search available rooms</a>
        <a class="btn btn-outlined btn-block" href="my-bookings.php"><?= icon('assignment') ?> Track my applications</a>
        <a class="btn btn-text btn-block" href="profile.php"><?= icon('person') ?> Update my profile</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header mb-1">
        <h3 class="card-title">Latest updates</h3>
        <a class="btn btn-text btn-sm" href="notifications.php">All</a>
      </div>
      <?php
      $notifs = db_all($conn, 'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', 'i', [$uid]);
      if (!$notifs): ?>
        <p class="tiny mb-0">No notifications yet.</p>
      <?php else: foreach ($notifs as $n): ?>
        <a href="<?= e($n['link'] ?: 'notifications.php') ?>" class="card card-flat card-pad-sm mb-1" style="display:flex;gap:12px;text-decoration:none;color:inherit">
          <span class="notif-icon <?= e($n['type']) ?>" style="width:32px;height:32px;border-radius:10px"><?= icon($n['type'] === 'success' ? 'check_circle' : ($n['type'] === 'danger' ? 'error' : ($n['type'] === 'warning' ? 'warning' : 'info')), 'icon icon-sm') ?></span>
          <span class="grow" style="min-width:0">
            <span class="label-m" style="display:block"><?= e($n['title']) ?></span>
            <span class="tiny"><?= e(time_ago($n['created_at'])) ?></span>
          </span>
        </a>
      <?php endforeach; endif; ?>
    </div>

    <div class="card card-flat">
      <div class="row" style="gap:12px">
        <span class="stat-icon tone-6"><?= icon('shield') ?></span>
        <div>
          <div class="label-m">Need help?</div>
          <div class="tiny">Contact the hostel office or your block warden for urgent accommodation issues.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<a class="fab" href="apply.php" aria-label="New application"><?= icon('add') ?></a>
<span class="fab-label">New application</span>

<?php page_footer(); ?>
