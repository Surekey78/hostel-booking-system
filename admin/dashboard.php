<?php
/**
 * admin/dashboard.php - Administrator overview: occupancy, queue, recent activity.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

$kpi = db_one(
    $conn,
    "SELECT
        (SELECT COUNT(*) FROM hostels WHERE is_active=1)                                  hostels,
        (SELECT COUNT(*) FROM rooms)                                                      rooms,
        (SELECT COALESCE(SUM(rt.capacity),0) FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id) beds,
        (SELECT COALESCE(SUM(r.occupied),0)  FROM rooms r)                                occupied,
        (SELECT COUNT(*) FROM rooms WHERE status='maintenance')                           maintenance,
        (SELECT COUNT(*) FROM bookings WHERE status='pending')                            pending,
        (SELECT COUNT(*) FROM bookings WHERE status='approved')                           approved,
        (SELECT COUNT(*) FROM bookings WHERE status='assigned')                           assigned,
        (SELECT COUNT(*) FROM bookings WHERE status='rejected')                           rejected,
        (SELECT COUNT(*) FROM bookings)                                                   bookings_total,
        (SELECT COUNT(*) FROM users WHERE role='student')                                 students,
        (SELECT COUNT(*) FROM assignments WHERE status='active')                          active_assignments,
        (SELECT COALESCE(SUM(rt.price),0) FROM assignments a
            JOIN bookings b ON b.id=a.booking_id JOIN room_types rt ON rt.id=b.room_type_id
            WHERE a.status='active')                                                      revenue,
        (SELECT COUNT(*) FROM documents)                                                  documents"
);

$vacant = (int)$kpi['beds'] - (int)$kpi['occupied'];
$rate   = (int)$kpi['beds'] > 0 ? round(((int)$kpi['occupied'] / (int)$kpi['beds']) * 100, 1) : 0.0;

$hostelRows = db_all($conn, 'SELECT * FROM v_hostel_occupancy ORDER BY block_code');
$typeRows   = db_all(
    $conn,
    "SELECT rt.*, COUNT(r.id) rooms, COALESCE(SUM(r.occupied),0) occupied,
            COALESCE(SUM(rt.capacity),0) beds, COALESCE(SUM(rt.capacity - r.occupied),0) vacant
       FROM room_types rt LEFT JOIN rooms r ON r.room_type_id = rt.id
      GROUP BY rt.id ORDER BY rt.price DESC"
);

$queue = db_all(
    $conn,
    "SELECT b.*, u.id AS student_user_id, u.full_name, u.student_id, u.programme, h.name hostel_name, h.block_code, rt.name room_type_name
       FROM bookings b
       JOIN users u       ON u.id = b.student_id
       JOIN hostels h     ON h.id = b.hostel_id
       JOIN room_types rt ON rt.id = b.room_type_id
      WHERE b.status = 'pending'
      ORDER BY b.created_at ASC LIMIT 6"
);

$recent = db_all(
    $conn,
    "SELECT b.*, u.id AS student_user_id, u.full_name, u.student_id, h.name hostel_name, h.block_code, rt.name room_type_name,
            pa.full_name admin_name
       FROM bookings b
       JOIN users u        ON u.id = b.student_id
       JOIN hostels h      ON h.id = b.hostel_id
       JOIN room_types rt  ON rt.id = b.room_type_id
       LEFT JOIN users pa  ON pa.id = b.processed_by
      ORDER BY COALESCE(b.processed_at, b.created_at) DESC LIMIT 8"
);

$monthly = db_all(
    $conn,
    "SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
       FROM bookings
      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY ym ORDER BY ym"
);
$maxMonthly = max(1, (int)max(array_map(fn($m) => (int)$m['c'], $monthly ?: [['c' => 1]])));

$statusMix = [
    'pending'   => (int)$kpi['pending'],
    'approved'  => (int)$kpi['approved'],
    'assigned'  => (int)$kpi['assigned'],
    'rejected'  => (int)$kpi['rejected'],
];
$mixTotal = max(1, array_sum($statusMix));

page_header('Dashboard', 'dashboard', 'Hostel administration console', [
    ['label' => 'Review queue', 'href' => 'applications.php?status=pending', 'class' => 'btn-tonal', 'icon' => 'assignment'],
    ['label' => 'Reports', 'href' => 'reports.php', 'class' => '', 'icon' => 'chart'],
]);
?>

<!-- KPI hero -->
<section class="hero hero-primary mb-3 anim-rise" style="padding:36px 40px">
  <span class="blur-shape" style="width:320px;height:320px;background:rgba(255,255,255,.18);top:-110px;right:-70px" aria-hidden="true"></span>
  <span class="blur-shape" style="width:250px;height:250px;background:rgba(232,222,248,.24);bottom:-110px;left:8%" aria-hidden="true"></span>
  <div class="row-between wrap" style="gap:24px">
    <div>
      <span class="chip glass mb-2"><?= icon('dashboard', 'icon icon-xs') ?> <?= e(date('l, d F Y')) ?></span>
      <h2 class="headline-l mb-1">Campus occupancy is at <?= $rate ?>%</h2>
      <p class="body-l muted mb-2" style="max-width:58ch">
        <?= number_format($vacant) ?> of <?= number_format((int)$kpi['beds']) ?> beds are still vacant
        <?php if ((int)$kpi['pending'] > 0): ?>
          and <strong><?= (int)$kpi['pending'] ?> application<?= (int)$kpi['pending'] === 1 ? '' : 's' ?></strong> need<?= (int)$kpi['pending'] === 1 ? 's' : '' ?> your review.
        <?php else: ?>
          . The application queue is clear.
        <?php endif; ?>
      </p>
      <div class="row wrap" style="gap:8px">
        <a class="btn" style="--btn-bg:#fff;--btn-fg:#6750A4" href="applications.php?status=pending"><?= icon('assignment') ?> Review applications</a>
        <a class="btn glass" href="rooms.php"><?= icon('layers') ?> Manage rooms</a>
      </div>
    </div>

    <div class="glass card-pad-lg" style="text-align:center;color:#fff;min-width:220px">
      <div class="donut" style="background:conic-gradient(#fff 0 <?= $rate ?>%, rgba(255,255,255,.25) <?= $rate ?>% 100%);margin:0 auto 10px">
        <div class="donut-center" style="color:#fff">
          <b><?= $rate ?>%</b>
          <span style="color:rgba(255,255,255,.8)">Occupied</span>
        </div>
      </div>
      <div class="tiny" style="color:rgba(255,255,255,.85)">
        <?= number_format((int)$kpi['occupied']) ?> occupied &middot; <?= number_format($vacant) ?> vacant
      </div>
    </div>
  </div>
</section>

<!-- KPI tiles -->
<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['assignment',   'tone-5', (int)$kpi['pending'],      'Pending applications', 'applications.php?status=pending'],
      ['check_circle', 'tone-4', (int)$kpi['assigned'],     'Rooms assigned',       'assignments.php'],
      ['bed',          'tone-1', number_format($vacant),    'Vacant beds',          'rooms.php'],
      ['people',       'tone-2', (int)$kpi['students'],     'Registered students',  'students.php'],
      ['apartment',    'tone-3', (int)$kpi['hostels'],      'Hostel blocks',        'hostels.php'],
      ['layers',       'tone-1', (int)$kpi['rooms'],        'Total rooms',          'rooms.php'],
      ['money',        'tone-4', money($kpi['revenue']),    'Active semester value', 'reports.php'],
      ['close',        'tone-6', (int)$kpi['rejected'],     'Rejected requests',    'applications.php?status=rejected'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $val, $label, $href]): ?>
    <a class="card card-hover anim-rise anim-rise-<?= ($i % 4) + 1 ?>" href="<?= e($href) ?>" style="text-decoration:none;color:inherit">
      <div class="stat">
        <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
        <span class="stat-value" style="<?= is_string($val) && !is_numeric($val) ? 'font-size:1.5rem' : '' ?>"><?= e((string)$val) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </div>
    </a>
  <?php endforeach; ?>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:24px;align-items:start">
  <div class="stack">
    <!-- Pending queue -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Applications awaiting review</h3>
          <p class="card-sub">Oldest first — <?= (int)$kpi['pending'] ?> in the queue</p>
        </div>
        <a class="btn btn-tonal btn-sm" href="applications.php?status=pending">Open queue</a>
      </div>

      <?php if (!$queue): ?>
        <div class="empty">
          <div class="empty-icon"><?= icon('check_circle') ?></div>
          <p class="body-m mb-0">Queue is clear</p>
          <p class="tiny">Every submitted application has been processed.</p>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Reference</th><th>Student</th><th>Hostel</th><th>Room type</th><th>Waiting</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($queue as $q): ?>
                <tr>
                  <td class="cell-strong"><?= e($q['reference_no']) ?></td>
                  <td><?= e($q['full_name']) ?><div class="cell-sub"><?= e($q['student_id']) ?> &middot; <?= e($q['programme'] ?: '—') ?></div></td>
                  <td><?= e($q['hostel_name']) ?><div class="cell-sub"><?= e($q['block_code']) ?></div></td>
                  <td><?= e($q['room_type_name']) ?></td>
                  <td class="tiny"><?= e(time_ago($q['created_at'])) ?></td>
                  <td style="text-align:right"><a class="btn btn-sm" href="application-view.php?id=<?= (int)$q['id'] ?>">Review</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Occupancy by block -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Occupancy by hostel block</h3>
          <p class="card-sub">Live bed utilisation</p>
        </div>
        <a class="btn btn-text btn-sm" href="reports.php?report=occupancy">Full report</a>
      </div>
      <?php foreach ($hostelRows as $h):
          $r = (float)$h['occupancy_rate']; ?>
        <div class="hbar-row">
          <span class="hbar-label"><?= e($h['block_code']) ?> &middot; <?= e($h['name']) ?></span>
          <span class="bar bar-lg"><span class="bar-fill <?= $r >= 90 ? 'bar-full' : ($r >= 70 ? 'bar-high' : 'bar-low') ?>" style="width:<?= $r ?>%"></span></span>
          <span class="hbar-value"><?= (int)$h['occupied_beds'] ?>/<?= (int)$h['total_beds'] ?> &middot; <?= $r ?>%</span>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Room type performance -->
    <div class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Room types</h3>
          <p class="card-sub">Capacity, pricing and remaining vacancy</p>
        </div>
        <a class="btn btn-text btn-sm" href="room-types.php">Manage</a>
      </div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>Room type</th><th class="num">Rooms</th><th class="num">Beds</th><th class="num">Occupied</th><th class="num">Vacant</th><th class="num">Price</th></tr></thead>
          <tbody>
            <?php foreach ($typeRows as $t): ?>
              <tr>
                <td class="cell-strong"><?= e($t['name']) ?><div class="cell-sub"><?= (int)$t['capacity'] ?> bed<?= (int)$t['capacity'] === 1 ? '' : 's' ?> per room</div></td>
                <td class="num"><?= (int)$t['rooms'] ?></td>
                <td class="num"><?= (int)$t['beds'] ?></td>
                <td class="num"><?= (int)$t['occupied'] ?></td>
                <td class="num"><span class="chip <?= (int)$t['vacant'] > 0 ? 'chip-success' : 'chip-neutral' ?>"><?= (int)$t['vacant'] ?></span></td>
                <td class="num cell-strong"><?= money($t['price']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div class="stack">
    <div class="card">
      <h3 class="card-title mb-2">Application status mix</h3>
      <?php
      $colors = ['pending' => 'var(--md-warning)', 'approved' => 'var(--md-info)', 'assigned' => 'var(--md-success)', 'rejected' => 'var(--md-error)'];
      $offset = 0;
      $segments = [];
      foreach ($statusMix as $k => $v) {
          $pct = ($v / $mixTotal) * 100;
          $segments[] = $colors[$k] . ' ' . $offset . '% ' . ($offset + $pct) . '%';
          $offset += $pct;
      }
      ?>
      <div class="donut" style="background:conic-gradient(<?= implode(',', $segments) ?>);margin:0 auto 14px">
        <div class="donut-center">
          <b><?= (int)$kpi['bookings_total'] ?></b><span>Requests</span>
        </div>
      </div>
      <?php foreach ($statusMix as $k => $v): ?>
        <div class="hbar-row" style="grid-template-columns:auto 1fr auto;padding:5px 0">
          <span class="row" style="gap:8px"><span style="width:10px;height:10px;border-radius:50%;background:<?= $colors[$k] ?>"></span><span class="hbar-label"><?= e(ucfirst($k)) ?></span></span>
          <span class="bar"><span class="bar-fill" style="width:<?= ($v / $mixTotal) * 100 ?>%;background:<?= $colors[$k] ?>"></span></span>
          <span class="hbar-value"><?= $v ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-header mb-1">
        <h3 class="card-title">Applications per month</h3>
      </div>
      <?php if (!$monthly): ?>
        <p class="tiny mb-0">No applications in the last 6 months.</p>
      <?php else: ?>
        <div class="row" style="align-items:flex-end;gap:10px;height:130px">
          <?php foreach ($monthly as $m):
              $h = max(6, round(((int)$m['c'] / $maxMonthly) * 110)); ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px">
              <span class="tiny"><?= (int)$m['c'] ?></span>
              <div style="width:100%;height:<?= $h ?>px;border-radius:12px 12px 4px 4px;background:linear-gradient(180deg,var(--md-primary),var(--md-tertiary))"></div>
              <span class="tiny" style="font-size:.6875rem"><?= e(date('M', strtotime($m['ym'] . '-01'))) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header mb-1">
        <h3 class="card-title">Recent activity</h3>
        <a class="btn btn-text btn-sm" href="applications.php">All</a>
      </div>
      <div class="timeline">
        <?php foreach (array_slice($recent, 0, 6) as $r):
            $state = $r['status'] === 'rejected' ? 'reject' : ($r['status'] === 'assigned' ? 'done' : ($r['status'] === 'pending' ? 'current' : 'done'));
        ?>
          <div class="tl-item">
            <span class="tl-dot <?= $state ?>"><?= $state === 'reject' ? icon('close') : ($state === 'current' ? icon('history') : icon('check')) ?></span>
            <a class="label-m" href="application-view.php?id=<?= (int)$r['id'] ?>" style="display:block"><?= e($r['reference_no']) ?> — <?= e($r['full_name']) ?></a>
            <div class="tiny"><?= e($r['hostel_name']) ?> &middot; <?= e(ucfirst(str_replace('_', ' ', $r['status']))) ?> &middot; <?= e(time_ago($r['processed_at'] ?: $r['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Housekeeping</h3>
      <div class="hbar-row" style="grid-template-columns:1fr auto">
        <span class="hbar-label"><?= icon('settings', 'icon icon-xs') ?> Rooms under maintenance</span>
        <span class="chip chip-warning"><?= (int)$kpi['maintenance'] ?></span>
      </div>
      <div class="hbar-row" style="grid-template-columns:1fr auto">
        <span class="hbar-label"><?= icon('description', 'icon icon-xs') ?> Uploaded documents</span>
        <span class="chip chip-outline"><?= (int)$kpi['documents'] ?></span>
      </div>
      <div class="hbar-row" style="grid-template-columns:1fr auto">
        <span class="hbar-label"><?= icon('key', 'icon icon-xs') ?> Active assignments</span>
        <span class="chip chip-success"><?= (int)$kpi['active_assignments'] ?></span>
      </div>
    </div>
  </div>
</div>

<?php page_footer(); ?>
