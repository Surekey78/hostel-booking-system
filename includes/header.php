<?php
/**
 * header.php - Shared page chrome (navigation rail, top bar, notification sheet).
 * Usage:  require_once __DIR__.'/../includes/header.php';
 *         page_header('Dashboard', 'dashboard');
 *         ... content ...
 *         page_footer();
 */

require_once __DIR__ . '/functions.php';

$__USER = current_user();

function initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string)$name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    return $out ?: '?';
}

/** Navigation definition per role. */
function nav_items(string $role): array
{
    if ($role === 'admin') {
        return [
            'main' => [
                ['dashboard.php',        'dashboard',   'Dashboard'],
                ['applications.php',     'assignment',  'Applications',  'pending_badge'],
                ['assignments.php',      'bed',         'Room Assignments'],
                ['rooms.php',            'layers',      'Rooms & Blocks'],
                ['hostels.php',          'apartment',   'Hostels'],
                ['room-types.php',       'money',       'Room Types'],
                ['students.php',         'people',      'Students'],
            ],
            'insights' => [
                ['reports.php',          'chart',       'Reports'],
                ['../notifications.php', 'notifications', 'Notifications'],
            ],
        ];
    }
    return [
        'main' => [
            ['dashboard.php',      'dashboard',   'My Dashboard'],
            ['hostels.php',        'apartment',   'Browse Hostels'],
            ['search-rooms.php',   'search',      'Find a Room'],
            ['apply.php',          'add',         'New Application'],
            ['my-bookings.php',    'assignment',  'My Applications'],
            ['my-assignment.php',  'key',         'My Room'],
        ],
        'insights' => [
            ['../notifications.php', 'notifications', 'Notifications', 'unread_badge'],
            ['../profile.php',     'person',      'My Profile'],
        ],
    ];
}

function pending_applications_count(): int
{
    global $conn;
    $res = mysqli_query($conn, "SELECT COUNT(*) c FROM bookings WHERE status = 'pending'");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return (int)($row['c'] ?? 0);
}

/**
 * Render everything above the page content.
 *
 * @param string      $title  Page title
 * @param string      $active Active nav key (file name without .php)
 * @param string      $subtitle Optional page subtitle
 * @param array       $actions Optional ['label'=>..,'href'=>..,'class'=>..,'icon'=>..]
 */
function page_header(string $title, string $active = '', string $subtitle = '', array $actions = []): void
{
    global $__USER;
    $user = $__USER ?: current_user();
    $role = $user['role'] ?? 'guest';
    $base = $role === 'admin' ? 'admin/' : ($role === 'student' ? 'student/' : '');
    $unread = $user ? unread_notification_count((int)$user['id']) : 0;
    $pending = $role === 'admin' ? pending_applications_count() : 0;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#6750A4">
<title><?= e($title) ?> &middot; <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= app_url('assets/css/fonts.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/material-you.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='7' fill='%236750A4'/><path d='M21 10H7V7a2 2 0 0 0-2-2H3v14h2v-3h16a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Z' fill='white'/></svg>">
</head>
<body>
<div class="app">
  <!-- ============ Navigation rail ============ -->
  <aside class="rail" id="rail" aria-label="Main navigation">
    <div class="brand">
      <div class="brand-mark"><?= icon('apartment') ?></div>
      <div class="brand-text">
        <span class="brand-name"><?= APP_NAME ?></span>
        <span class="brand-sub"><?= e($role === 'admin' ? 'Admin Console' : 'Student Portal') ?></span>
      </div>
    </div>

    <?php foreach (nav_items($role) as $section => $items): ?>
      <div class="rail-section"><?= e($section) ?></div>
      <?php foreach ($items as $it):
          [$file, $ico, $label] = $it;
          // "../x.php" in the nav table means a root-level page shared by both
          // roles; anything else lives inside the role's own directory.
          $isRootPage = str_starts_with($file, '../');
          $file = preg_replace('#^(\.\./)+#', '', $file);
          $badge = $it[3] ?? null;
          $isActive = ($active === basename($file, '.php'));
          $count = null;
          if ($badge === 'unread_badge' && $unread > 0) $count = $unread;
          if ($badge === 'pending_badge' && $pending > 0) $count = $pending;
      ?>
        <a class="nav-item<?= $isActive ? ' active' : '' ?>" href="<?= app_url(($isRootPage ? '' : $base) . $file) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
          <?= icon($ico) ?><span><?= e($label) ?></span>
          <?php if ($count !== null): ?><span class="nav-badge"><?= $count ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="rail-footer">
      <?php if ($user): ?>
        <div class="rail-user mb-1">
          <div class="avatar sm"><?= e(initials($user['full_name'])) ?></div>
          <div class="grow" style="min-width:0">
            <div class="label-m" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($user['full_name']) ?></div>
            <div class="tiny"><?= e($role === 'admin' ? 'Hostel Administrator' : ($user['student_id'] ?: 'Student')) ?></div>
          </div>
        </div>
      <?php endif; ?>
      <a class="nav-item" href="<?= app_url('logout.php') ?>">
        <?= icon('logout') ?><span>Sign out</span>
      </a>
    </div>
  </aside>

  <!-- ============ Main column ============ -->
  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" data-rail-toggle aria-label="Open navigation menu"><?= icon('menu') ?></button>
      <div class="grow" style="min-width:0">
        <h1 class="title-m mb-0" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($title) ?></h1>
        <?php if ($subtitle): ?><p class="tiny mb-0"><?= $subtitle ?></p><?php endif; ?>
      </div>

      <?php foreach ($actions as $a): ?>
        <a class="btn <?= e($a['class'] ?? '') ?>" href="<?= e($a['href']) ?>">
          <?php if (!empty($a['icon'])): ?><?= icon($a['icon']) ?><?php endif; ?>
          <?= e($a['label']) ?>
        </a>
      <?php endforeach; ?>

      <button class="icon-btn" data-notif-toggle aria-label="Notifications (<?= $unread ?> unread)">
        <?= icon('notifications') ?>
        <?php if ($unread > 0): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
      </button>
      <?php if ($user): ?>
        <a class="avatar" href="<?= app_url('profile.php') ?>" title="<?= e($user['full_name']) ?>" aria-label="My profile"><?= e(initials($user['full_name'])) ?></a>
      <?php endif; ?>
    </header>

    <main class="content" id="main">
      <?= flash_render() ?>
    <?php
}

/** Close the page: footer, notification sheet, scrim, scripts. */
function page_footer(string $fab = ''): void
{
    global $__USER;
    $user = $__USER ?: current_user();
    $notifs = [];
    if ($user) {
        $notifs = db_all(
            $GLOBALS['conn'],
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15',
            'i',
            [(int)$user['id']]
        );
    }
    ?>
    </main>

    <footer class="footer">
      <div class="row-between">
        <span>&copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; <?= e(APP_TAGLINE) ?></span>
        <span>PHP &middot; MySQL &middot; mysqli &middot; Material Design 3</span>
      </div>
    </footer>
  </div><!-- /.main -->
</div><!-- /.app -->

<!-- ============ Notification side sheet ============ -->
<div class="notif-panel" data-notif-panel aria-label="Notifications">
  <div class="notif-head">
    <div>
      <div class="title-m">Notifications</div>
      <div class="tiny">Status updates for your applications</div>
    </div>
    <button class="icon-btn" data-notif-toggle aria-label="Close notifications"><?= icon('close') ?></button>
  </div>
  <div class="notif-list">
    <?php if (!$notifs): ?>
      <div class="empty">
        <div class="empty-icon"><?= icon('notifications') ?></div>
        <p class="body-m mb-0">Nothing here yet</p>
        <p class="tiny">You will see booking updates as they happen.</p>
      </div>
    <?php else: foreach ($notifs as $n): ?>
      <a class="notif-item<?= $n['is_read'] ? '' : ' unread' ?>" href="<?= $n['link'] ? e(app_url($n['link'])) : e(app_url('notifications.php')) ?>">
        <span class="notif-icon <?= e($n['type']) ?>"><?= icon($n['type'] === 'success' ? 'check_circle' : ($n['type'] === 'danger' ? 'error' : ($n['type'] === 'warning' ? 'warning' : 'info'))) ?></span>
        <span class="grow">
          <span class="label-m" style="display:block"><?= e($n['title']) ?></span>
          <span class="tiny" style="display:block;margin-top:2px"><?= e($n['message']) ?></span>
          <span class="tiny" style="display:block;margin-top:4px;opacity:.75"><?= e(time_ago($n['created_at'])) ?></span>
        </span>
      </a>
    <?php endforeach; endif; ?>
  </div>
  <div style="padding:14px;border-top:1px solid var(--md-outline-variant)">
    <a class="btn btn-tonal btn-block" href="<?= app_url('notifications.php') ?>">View all</a>
  </div>
</div>

<div class="scrim" data-scrim></div>
<script src="<?= app_url('assets/js/app.js') ?>"></script>
</body>
</html>
    <?php
}
