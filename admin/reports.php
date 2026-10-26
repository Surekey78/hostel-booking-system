<?php
/**
 * admin/reports.php - Vacancy, occupancy, booking and accommodation reports
 *                     with CSV export and a print-friendly layout.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

$report = safe_choice(get_str('report'), ['vacancy', 'occupancy', 'bookings', 'accommodation'], 'occupancy');
$from   = get_str('from', date('Y-m-01', strtotime('-3 months')));
$to     = get_str('to', date('Y-m-d'));
$hid    = get_int('hostel');
$status = safe_choice(get_str('status'), ['', 'pending', 'approved', 'assigned', 'rejected', 'cancelled', 'checked_out'], '');

$titles = [
    'vacancy'       => ['Vacancy Report', 'Beds available per hostel block and room type'],
    'occupancy'     => ['Occupancy Report', 'Bed utilisation across the hostel estate'],
    'bookings'      => ['Booking Report', 'Applications received within the selected date range'],
    'accommodation' => ['Accommodation Report', 'Current residents and their room assignments'],
];

/* ---------------- Data per report ---------------- */
$data = [];
$headers = [];

if ($report === 'vacancy') {
    $sql = "SELECT h.block_code, h.name hostel_name, rt.name room_type_name, rt.capacity, rt.price,
                   COUNT(r.id) rooms,
                   COALESCE(SUM(rt.capacity),0) beds,
                   COALESCE(SUM(r.occupied),0) occupied,
                   COALESCE(SUM(rt.capacity - r.occupied),0) vacant,
                   COALESCE(SUM(r.status='maintenance'),0) maintenance
              FROM hostels h
              JOIN room_types rt ON 1=1
              LEFT JOIN rooms r  ON r.hostel_id = h.id AND r.room_type_id = rt.id AND r.status <> 'blocked'
             WHERE h.is_active = 1" . ($hid ? ' AND h.id = ' . (int)$hid : '') . "
             GROUP BY h.id, rt.id
            HAVING rooms > 0
             ORDER BY h.block_code, rt.capacity";
    $data = db_all($conn, $sql);
    $headers = ['block_code' => 'Block', 'hostel_name' => 'Hostel', 'room_type_name' => 'Room type', 'capacity' => 'Beds/room',
                'rooms' => 'Rooms', 'beds' => 'Total beds', 'occupied' => 'Occupied', 'vacant' => 'Vacant', 'maintenance' => 'Maintenance'];

} elseif ($report === 'occupancy') {
    $sql = "SELECT * FROM v_hostel_occupancy" . ($hid ? ' WHERE id = ' . (int)$hid : '') . " ORDER BY block_code";
    $data = db_all($conn, $sql);
    $headers = ['block_code' => 'Block', 'name' => 'Hostel', 'gender_policy' => 'Policy', 'total_rooms' => 'Rooms',
                'total_beds' => 'Beds', 'occupied_beds' => 'Occupied', 'vacant_beds' => 'Vacant', 'occupancy_rate' => 'Occupancy %'];

} elseif ($report === 'bookings') {
    $where = ["DATE(b.created_at) BETWEEN ? AND ?"];
    $types = 'ss';
    $params = [$from, $to];
    if ($hid)    { $where[] = 'b.hostel_id = ?'; $params[] = $hid; $types .= 'i'; }
    if ($status) { $where[] = 'b.status = ?';   $params[] = $status; $types .= 's'; }
    $data = db_all(
        $conn,
        "SELECT b.reference_no, u.full_name, u.student_id, u.programme, h.name hostel_name, h.block_code,
                rt.name room_type_name, rt.price, b.intake_period, b.status, b.created_at, b.processed_at,
                r.room_number, a.bed_number
           FROM bookings b
           JOIN users u ON u.id=b.student_id
           JOIN hostels h ON h.id=b.hostel_id
           JOIN room_types rt ON rt.id=b.room_type_id
           LEFT JOIN assignments a ON a.booking_id=b.id AND a.status='active'
           LEFT JOIN rooms r ON r.id=a.room_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY b.created_at DESC",
        $types,
        $params
    );
    $headers = ['reference_no' => 'Reference', 'full_name' => 'Student', 'student_id' => 'Student ID', 'programme' => 'Programme',
                'hostel_name' => 'Hostel', 'block_code' => 'Block', 'room_type_name' => 'Room type', 'price' => 'Price',
                'intake_period' => 'Intake', 'status' => 'Status', 'room_number' => 'Room', 'created_at' => 'Submitted'];

} else { // accommodation
    $where = ["a.status = 'active'"];
    $types = '';
    $params = [];
    if ($hid) { $where[] = 'h.id = ?'; $params[] = $hid; $types .= 'i'; }
    $data = db_all(
        $conn,
        "SELECT u.full_name, u.student_id, u.email, u.programme, u.year_level, u.gender,
                h.name hostel_name, h.block_code, h.warden_name, r.room_number, r.floor,
                a.bed_number, rt.name room_type_name, rt.capacity, rt.price,
                a.checkin_date, b.reference_no, b.intake_period
           FROM assignments a
           JOIN users u ON u.id=a.student_id
           JOIN rooms r ON r.id=a.room_id
           JOIN hostels h ON h.id=r.hostel_id
           JOIN room_types rt ON rt.id=r.room_type_id
           JOIN bookings b ON b.id=a.booking_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY h.block_code, r.floor, LENGTH(r.room_number), r.room_number, a.bed_number",
        $types,
        $params
    );
    $headers = ['block_code' => 'Block', 'hostel_name' => 'Hostel', 'room_number' => 'Room', 'floor' => 'Floor',
                'bed_number' => 'Bed', 'full_name' => 'Resident', 'student_id' => 'Student ID', 'programme' => 'Programme',
                'room_type_name' => 'Room type', 'price' => 'Rate', 'checkin_date' => 'Check-in', 'intake_period' => 'Intake'];
}

if (get_str('export') === 'csv') {
    csv_export($report . '-report-' . date('Ymd-His') . '.csv', $data, $headers);
}

/* ---------------- Summary numbers ---------------- */
$kpi = db_one(
    $conn,
    "SELECT (SELECT COALESCE(SUM(rt.capacity),0) FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id) beds,
            (SELECT COALESCE(SUM(r.occupied),0) FROM rooms r) occupied,
            (SELECT COUNT(*) FROM rooms) rooms,
            (SELECT COUNT(*) FROM assignments WHERE status='active') residents,
            (SELECT COUNT(*) FROM bookings WHERE status='pending') pending,
            (SELECT COALESCE(SUM(rt.price),0) FROM assignments a JOIN bookings b ON b.id=a.booking_id JOIN room_types rt ON rt.id=b.room_type_id WHERE a.status='active') revenue"
);
$vacant = (int)$kpi['beds'] - (int)$kpi['occupied'];
$rate   = (int)$kpi['beds'] > 0 ? round(((int)$kpi['occupied'] / (int)$kpi['beds']) * 100, 1) : 0.0;

$qs = http_build_query(array_filter(['report' => $report, 'from' => $from, 'to' => $to, 'hostel' => $hid ?: '', 'status' => $status], fn($v) => $v !== ''));

page_header('Reports', 'reports', e($titles[$report][1]), [
    ['label' => 'Export CSV', 'href' => 'reports.php?' . e($qs) . '&export=csv', 'class' => 'btn-outlined', 'icon' => 'download'],
]);
?>

<!-- Report picker -->
<div class="card mb-3">
  <div class="tabs no-print">
    <?php foreach ($titles as $key => [$label, $sub]): ?>
      <a class="tab<?= $report === $key ? ' active' : '' ?>" href="?report=<?= $key ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $hid ? '&hostel=' . $hid : '' ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </div>
  <form method="get" action="reports.php" class="filter-bar mt-2 no-print">
    <input type="hidden" name="report" value="<?= e($report) ?>">
    <div class="field">
      <label class="field-label" for="hostel">Hostel block</label>
      <select class="select" id="hostel" name="hostel">
        <option value="">All blocks</option>
        <?php foreach (hostel_options() as $h): ?>
          <option value="<?= (int)$h['id'] ?>" <?= $hid === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['block_code']) ?> — <?= e($h['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($report === 'bookings'): ?>
      <div class="field">
        <label class="field-label" for="from">From</label>
        <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
      </div>
      <div class="field">
        <label class="field-label" for="to">To</label>
        <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
      </div>
      <div class="field">
        <label class="field-label" for="status">Status</label>
        <select class="select" id="status" name="status">
          <option value="">All statuses</option>
          <?php foreach (['pending', 'approved', 'assigned', 'rejected', 'cancelled', 'checked_out'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $s))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <?php endif; ?>
    <div class="row" style="gap:8px">
      <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Generate</button>
      <button type="button" class="btn btn-outlined" onclick="window.print()"><?= icon('print') ?> Print</button>
    </div>
  </form>
</div>

<!-- Report header (print) -->
<div class="card mb-3" id="print-head">
  <div class="row-between wrap" style="gap:16px">
    <div>
      <div class="label-s" style="color:var(--md-primary)"><?= APP_NAME ?> &middot; Hostel Office</div>
      <h2 class="headline-m mb-0"><?= e($titles[$report][0]) ?></h2>
      <p class="tiny mb-0">
        <?= e($titles[$report][1]) ?>
        <?php if ($report === 'bookings'): ?> &middot; <?= e($from) ?> to <?= e($to) ?><?php endif; ?>
        <?php if ($hid): $hn = db_one($conn, 'SELECT name FROM hostels WHERE id=?', 'i', [$hid]); ?> &middot; <?= e($hn['name'] ?? '') ?><?php endif; ?>
      </p>
    </div>
    <div class="tiny" style="text-align:right">
      Generated <?= date('d M Y, g:i A') ?><br>by <?= e($me['full_name']) ?>
    </div>
  </div>
</div>

<!-- Summary tiles -->
<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['bed', 'tone-3', number_format($vacant), 'Vacant beds'],
      ['people', 'tone-1', (int)$kpi['residents'], 'Current residents'],
      ['chart', 'tone-2', $rate . '%', 'Occupancy rate'],
      ['money', 'tone-4', money($kpi['revenue']), 'Active semester value'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $v, $l]): ?>
    <div class="card"><div class="stat">
      <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
      <span class="stat-value" style="font-size:<?= is_string($v) && strlen($v) > 6 ? '1.35rem' : '2rem' ?>"><?= e((string)$v) ?></span>
      <span class="stat-label"><?= e($l) ?></span>
    </div></div>
  <?php endforeach; ?>
</div>

<?php if ($report === 'occupancy'): ?>
  <!-- Occupancy visual -->
  <div class="grid g-2 mb-3">
    <div class="card">
      <h3 class="card-title mb-2">Occupancy by block</h3>
      <?php foreach ($data as $h): $r = (float)$h['occupancy_rate']; ?>
        <div class="hbar-row">
          <span class="hbar-label"><?= e($h['block_code']) ?> &middot; <?= e($h['name']) ?></span>
          <span class="bar bar-lg"><span class="bar-fill <?= $r >= 90 ? 'bar-full' : ($r >= 70 ? 'bar-high' : 'bar-low') ?>" style="width:<?= $r ?>%"></span></span>
          <span class="hbar-value"><?= (int)$h['occupied_beds'] ?>/<?= (int)$h['total_beds'] ?> &middot; <?= $r ?>%</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="card">
      <h3 class="card-title mb-2">Estate split</h3>
      <div class="row" style="gap:24px;align-items:center;flex-wrap:wrap">
        <div class="donut" style="background:conic-gradient(var(--md-primary) 0 <?= $rate ?>%, var(--md-success) <?= $rate ?>% 100%)">
          <div class="donut-center"><b><?= $rate ?>%</b><span>Occupied</span></div>
        </div>
        <div class="grow" style="min-width:180px">
          <div class="hbar-row" style="grid-template-columns:auto 1fr auto">
            <span class="row" style="gap:8px"><span style="width:10px;height:10px;border-radius:50%;background:var(--md-primary)"></span><span class="hbar-label">Occupied beds</span></span>
            <span class="bar"><span class="bar-fill" style="width:<?= $rate ?>%"></span></span>
            <span class="hbar-value"><?= number_format((int)$kpi['occupied']) ?></span>
          </div>
          <div class="hbar-row" style="grid-template-columns:auto 1fr auto">
            <span class="row" style="gap:8px"><span style="width:10px;height:10px;border-radius:50%;background:var(--md-success)"></span><span class="hbar-label">Vacant beds</span></span>
            <span class="bar"><span class="bar-fill bar-low" style="width:<?= 100 - $rate ?>%"></span></span>
            <span class="hbar-value"><?= number_format($vacant) ?></span>
          </div>
          <hr class="divider">
          <div class="tiny">Total rooms: <strong><?= (int)$kpi['rooms'] ?></strong> &middot; Total beds: <strong><?= number_format((int)$kpi['beds']) ?></strong></div>
          <div class="tiny">Pending applications: <strong><?= (int)$kpi['pending'] ?></strong></div>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($report === 'bookings'): ?>
  <?php
  $byStatus = [];
  $byHostel = [];
  $totalVal = 0.0;
  foreach ($data as $row) {
      $byStatus[$row['status']] = ($byStatus[$row['status']] ?? 0) + 1;
      $byHostel[$row['block_code'] . ' — ' . $row['hostel_name']] = ($byHostel[$row['block_code'] . ' — ' . $row['hostel_name']] ?? 0) + 1;
      $totalVal += (float)$row['price'];
  }
  $maxH = max(1, max($byHostel ?: [1]));
  ?>
  <div class="grid g-2 mb-3">
    <div class="card">
      <h3 class="card-title mb-2">Applications by block</h3>
      <?php foreach ($byHostel as $label => $n): ?>
        <div class="hbar-row">
          <span class="hbar-label"><?= e($label) ?></span>
          <span class="bar"><span class="bar-fill" style="width:<?= ($n / $maxH) * 100 ?>%"></span></span>
          <span class="hbar-value"><?= $n ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$byHostel): ?><p class="tiny mb-0">No applications in this period.</p><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="card-title mb-2">Applications by status</h3>
      <div class="row wrap" style="gap:8px">
        <?php foreach ($byStatus as $s => $n): ?>
          <span class="chip chip-lg"><?= status_chip($s) ?> <strong style="margin-left:6px"><?= $n ?></strong></span>
        <?php endforeach; ?>
      </div>
      <hr class="divider">
      <div class="grid g-3" style="gap:10px;text-align:center">
        <div><div class="title-m mb-0"><?= count($data) ?></div><div class="tiny">Applications</div></div>
        <div><div class="title-m mb-0"><?= money($totalVal) ?></div><div class="tiny">Requested value</div></div>
        <div><div class="title-m mb-0"><?= $data ? round(count(array_filter($data, fn($r) => $r['status'] === 'assigned')) / max(1, count($data)) * 100) : 0 ?>%</div><div class="tiny">Conversion</div></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Data table -->
<div class="card" style="padding:0;overflow:hidden">
  <div class="card-header" style="padding:20px 24px 0">
    <div>
      <h3 class="card-title"><?= e($titles[$report][0]) ?> data</h3>
      <p class="card-sub"><?= count($data) ?> row<?= count($data) === 1 ? '' : 's' ?></p>
    </div>
    <a class="btn btn-tonal btn-sm no-print" href="?<?= e($qs) ?>&export=csv"><?= icon('download', 'icon icon-sm') ?> Download CSV</a>
  </div>
  <?php if (!$data): ?>
    <div class="empty">
      <div class="empty-icon"><?= icon('chart') ?></div>
      <h3 class="title-m mb-0">No records for this report</h3>
      <p class="tiny">Change the filters or date range and generate again.</p>
    </div>
  <?php else: ?>
    <div class="table-wrap" style="padding:16px 0 0">
      <table class="data">
        <thead>
          <tr>
            <th>#</th>
            <?php foreach ($headers as $key => $label): ?>
              <th<?= in_array($key, ['capacity', 'rooms', 'beds', 'occupied', 'vacant', 'maintenance', 'total_rooms', 'total_beds', 'occupied_beds', 'vacant_beds', 'occupancy_rate', 'price', 'bed_number', 'floor'], true) ? ' class="num"' : '' ?>><?= e($label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data as $i => $row): ?>
            <tr>
              <td class="tiny"><?= $i + 1 ?></td>
              <?php foreach ($headers as $key => $label):
                  $val = $row[$key] ?? '';
                  $numeric = in_array($key, ['capacity', 'rooms', 'beds', 'occupied', 'vacant', 'maintenance', 'total_rooms', 'total_beds', 'occupied_beds', 'vacant_beds', 'occupancy_rate', 'price', 'bed_number', 'floor'], true);
              ?>
                <td<?= $numeric ? ' class="num"' : '' ?>>
                  <?php if ($key === 'status'): ?>
                    <?= status_chip((string)$val) ?>
                  <?php elseif ($key === 'price'): ?>
                    <?= money($val) ?>
                  <?php elseif ($key === 'occupancy_rate'): ?>
                    <span class="chip <?= (float)$val >= 90 ? 'chip-danger' : ((float)$val >= 70 ? 'chip-warning' : 'chip-success') ?>"><?= (float)$val ?>%</span>
                  <?php elseif ($key === 'vacant' || $key === 'vacant_beds'): ?>
                    <strong style="color:<?= (int)$val > 0 ? 'var(--md-success)' : 'var(--md-error)' ?>"><?= (int)$val ?></strong>
                  <?php elseif ($key === 'created_at' || $key === 'processed_at' || $key === 'checkin_date'): ?>
                    <span class="tiny"><?= fmt_date((string)$val, 'd M Y') ?></span>
                  <?php elseif ($key === 'full_name'): ?>
                    <span class="cell-strong"><?= e((string)$val) ?></span>
                  <?php else: ?>
                    <?= e((string)$val) ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<p class="tiny text-center mt-3 no-print">Reports are generated live from the database. Use <strong>Export CSV</strong> for spreadsheet analysis or <strong>Print</strong> for an office copy.</p>

<?php page_footer(); ?>
