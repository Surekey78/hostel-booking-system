<?php



require_once __DIR__ . '/../includes/header.php';
$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $target = db_one($conn, "SELECT * FROM users WHERE id = ? AND role = 'student'", 'i', [$id]);

    if (!$target) {
        flash_set('danger', 'Student not found.');
    } elseif ($action === 'toggle_status') {
        $new = $target['status'] === 'active' ? 'suspended' : 'active';
        db_exec($conn, 'UPDATE users SET status = ? WHERE id = ?', 'si', [$new, $id]);
        notify($id, $new === 'active' ? 'Account reactivated' : 'Account suspended',
            $new === 'active' ? 'Your hostel account has been reactivated by the hostel office.'
                              : 'Your hostel account was suspended by the hostel office. Contact the warden for details.',
            $new === 'active' ? 'success' : 'danger', 'student/dashboard.php');
        flash_set('success', 'Account marked as ' . $new . '.');
    } elseif ($action === 'reset_password') {
        $temp = 'Hostel' . random_int(1000, 9999);
        db_exec($conn, 'UPDATE users SET password_hash = ? WHERE id = ?', 'si', [password_hash($temp, PASSWORD_DEFAULT), $id]);
        notify($id, 'Password reset by hostel office', 'Your password was reset. Temporary password: ' . $temp . ' — please change it after signing in.', 'warning', 'profile.php');
        flash_set('success', 'Temporary password for ' . $target['full_name'] . ': ' . $temp . ' (also sent as an in-system notification).');
    }
    redirect('admin/students.php' . (isset($_POST['return_query']) && $_POST['return_query'] ? '?' . $_POST['return_query'] : ''));
}

$q      = get_str('q');
$status = safe_choice(get_str('status'), ['', 'active', 'suspended'], '');
$prog   = get_str('programme');
$hasRoom = get_str('room');

$where  = ["u.role = 'student'"];
$types  = '';
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.student_id LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($status !== '') { $where[] = 'u.status = ?'; $params[] = $status; $types .= 's'; }
if ($prog !== '')   { $where[] = 'u.programme = ?'; $params[] = $prog; $types .= 's'; }
if ($hasRoom === 'yes') { $where[] = 'EXISTS (SELECT 1 FROM assignments a WHERE a.student_id = u.id AND a.status = "active")'; }
if ($hasRoom === 'no')  { $where[] = 'NOT EXISTS (SELECT 1 FROM assignments a WHERE a.student_id = u.id AND a.status = "active")'; }

$students = db_all(
    $conn,
    "SELECT u.*,
            (SELECT COUNT(*) FROM bookings b WHERE b.student_id = u.id) applications,
            (SELECT COUNT(*) FROM bookings b WHERE b.student_id = u.id AND b.status='pending') pending_apps,
            (SELECT r.room_number FROM assignments a JOIN rooms r ON r.id=a.room_id WHERE a.student_id=u.id AND a.status='active' ORDER BY a.created_at DESC LIMIT 1) room_number,
            (SELECT h.name FROM assignments a JOIN rooms r ON r.id=a.room_id JOIN hostels h ON h.id=r.hostel_id WHERE a.student_id=u.id AND a.status='active' ORDER BY a.created_at DESC LIMIT 1) hostel_name
       FROM users u
      WHERE " . implode(' AND ', $where) . "
      ORDER BY u.full_name
      LIMIT 500",
    $types,
    $params
);

$programmes = db_all($conn, "SELECT DISTINCT programme FROM users WHERE role='student' AND programme IS NOT NULL ORDER BY programme");
$kpi = db_one(
    $conn,
    "SELECT COUNT(*) students, SUM(status='active') active, SUM(status='suspended') suspended,
            SUM(gender='male') males, SUM(gender='female') females
       FROM users WHERE role='student'"
);
$residing = (int)(db_one($conn, "SELECT COUNT(*) c FROM assignments WHERE status='active'")['c'] ?? 0);
$queryBase = http_build_query(array_filter(['q' => $q, 'status' => $status, 'programme' => $prog, 'room' => $hasRoom], fn($v) => $v !== ''));

if (get_str('export') === 'csv') {
    $csv = [];
    foreach ($students as $s) {
        $csv[] = [
            'student_id' => $s['student_id'], 'full_name' => $s['full_name'], 'email' => $s['email'], 'phone' => $s['phone'],
            'gender' => $s['gender'], 'programme' => $s['programme'], 'year_level' => $s['year_level'],
            'applications' => $s['applications'], 'room' => $s['room_number'] ?: '', 'hostel' => $s['hostel_name'] ?: '',
            'status' => $s['status'], 'registered' => $s['created_at'],
        ];
    }
    csv_export('students-' . date('Ymd-His') . '.csv', $csv, [
        'student_id' => 'Student ID', 'full_name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'gender' => 'Gender',
        'programme' => 'Programme', 'year_level' => 'Year', 'applications' => 'Applications', 'room' => 'Room',
        'hostel' => 'Hostel', 'status' => 'Status', 'registered' => 'Registered',
    ]);
}

page_header('Students', 'students', (int)$kpi['students'] . ' registered students &middot; ' . $residing . ' currently residing', [
    ['label' => 'Export CSV', 'href' => 'students.php?' . e($queryBase) . '&export=csv', 'class' => 'btn-outlined', 'icon' => 'download'],
]);
?>

<div class="grid g-4 mb-3">
  <?php
  $tiles = [
      ['people', 'tone-1', (int)$kpi['students'], 'Registered students'],
      ['key', 'tone-4', $residing, 'Currently residing'],
      ['check_circle', 'tone-3', (int)$kpi['active'], 'Active accounts'],
      ['lock', 'tone-6', (int)$kpi['suspended'], 'Suspended'],
  ];
  foreach ($tiles as $i => [$ico, $tone, $v, $l]): ?>
    <div class="card anim-rise anim-rise-<?= $i + 1 ?>"><div class="stat">
      <span class="stat-icon <?= $tone ?>"><?= icon($ico) ?></span>
      <span class="stat-value"><?= $v ?></span><span class="stat-label"><?= e($l) ?></span>
    </div></div>
  <?php endforeach; ?>
</div>

<form method="get" action="students.php" class="card mb-3">
  <div class="filter-bar">
    <div class="field" style="grid-column:span 2">
      <label class="field-label" for="q">Search</label>
      <input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, student ID, email or phone&hellip;">
    </div>
    <div class="field">
      <label class="field-label" for="programme">Programme</label>
      <select class="select" id="programme" name="programme">
        <option value="">All programmes</option>
        <?php foreach ($programmes as $p): ?>
          <option value="<?= e($p['programme']) ?>" <?= $prog === $p['programme'] ? 'selected' : '' ?>><?= e($p['programme']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="room">Accommodation</label>
      <select class="select" id="room" name="room">
        <option value="">All students</option>
        <option value="yes" <?= $hasRoom === 'yes' ? 'selected' : '' ?>>Has a room</option>
        <option value="no" <?= $hasRoom === 'no' ? 'selected' : '' ?>>No room yet</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="status">Account status</label>
      <select class="select" id="status" name="status">
        <option value="">Any status</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
      </select>
    </div>
  </div>
  <div class="row wrap mt-2" style="gap:8px">
    <button type="submit" class="btn btn-tonal"><?= icon('filter') ?> Filter</button>
    <a class="btn btn-text" href="students.php">Reset</a>
    <span class="grow"></span>
    <span class="tiny"><?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></span>
  </div>
</form>

<?php if (!$students): ?>
  <div class="card"><div class="empty">
    <div class="empty-icon"><?= icon('people') ?></div>
    <h3 class="title-m mb-0">No students found</h3>
    <p class="tiny">Adjust the filters above.</p>
  </div></div>
<?php else: ?>
  <div class="card" style="padding:0;overflow:hidden">
    <div class="table-wrap">
      <table class="data" style="min-width:1080px">
        <thead>
          <tr><th>Student</th><th>Student ID</th><th>Programme</th><th>Contact</th><th class="num">Applications</th><th>Room</th><th>Status</th><th style="text-align:right">Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
            <tr>
              <td>
                <div class="row" style="gap:10px">
                  <span class="avatar sm"><?= e(initials($s['full_name'])) ?></span>
                  <span>
                    <span class="cell-strong"><?= e($s['full_name']) ?></span>
                    <span class="cell-sub" style="display:block"><?= e(ucfirst((string)$s['gender'] ?: '—')) ?><?= $s['year_level'] ? ' &middot; ' . e($s['year_level']) : '' ?></span>
                  </span>
                </div>
              </td>
              <td class="cell-strong"><?= e($s['student_id']) ?></td>
              <td><?= e($s['programme'] ?: '—') ?></td>
              <td class="tiny"><?= e($s['email']) ?><div class="cell-sub"><?= e($s['phone'] ?: '—') ?></div></td>
              <td class="num">
                <?= (int)$s['applications'] ?>
                <?php if ((int)$s['pending_apps'] > 0): ?><div class="cell-sub"><?= (int)$s['pending_apps'] ?> pending</div><?php endif; ?>
              </td>
              <td>
                <?php if ($s['room_number']): ?>
                  <span class="chip chip-success"><?= icon('bed', 'icon icon-xs') ?> <?= e($s['room_number']) ?></span>
                  <div class="cell-sub"><?= e($s['hostel_name']) ?></div>
                <?php else: ?>
                  <span class="tiny">Not assigned</span>
                <?php endif; ?>
              </td>
              <td><span class="chip <?= $s['status'] === 'active' ? 'chip-success' : 'chip-danger' ?>"><span class="chip-dot"></span><?= e(ucfirst($s['status'])) ?></span></td>
              <td>
                <div class="table-actions" style="justify-content:flex-end">
                  <a class="btn btn-sm btn-text" href="applications.php?q=<?= e(urlencode($s['student_id'])) ?>"><?= icon('assignment', 'icon icon-sm') ?> Applications</a>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="return_query" value="<?= e($queryBase) ?>">
                    <button class="btn btn-sm btn-outlined" data-confirm="Reset the password for <?= e($s['full_name']) ?>? A temporary password will be generated."><?= icon('key', 'icon icon-sm') ?> Reset password</button>
                  </form>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                    <input type="hidden" name="return_query" value="<?= e($queryBase) ?>">
                    <button class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-text' : 'btn-tonal' ?>" style="<?= $s['status'] === 'active' ? '--btn-fg:var(--md-error)' : '' ?>"
                            data-confirm="<?= $s['status'] === 'active' ? 'Suspend' : 'Reactivate' ?> the account of <?= e($s['full_name']) ?>?">
                      <?= icon($s['status'] === 'active' ? 'lock' : 'check_circle', 'icon icon-sm') ?> <?= $s['status'] === 'active' ? 'Suspend' : 'Activate' ?>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
