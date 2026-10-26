<?php
/**
 * notifications.php - Full notification inbox (used by both students and admins).
 */
require_once __DIR__ . '/includes/header.php';
$me   = require_login();
$uid  = (int)$me['id'];
$role = $me['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all') {
        mark_notifications_read($uid);
        flash_set('success', 'All notifications marked as read.');
    } elseif ($action === 'delete') {
        db_exec($conn, 'DELETE FROM notifications WHERE id = ? AND user_id = ?', 'ii', [(int)($_POST['id'] ?? 0), $uid]);
        flash_set('success', 'Notification removed.');
    } elseif ($action === 'clear_read') {
        db_exec($conn, 'DELETE FROM notifications WHERE user_id = ? AND is_read = 1', 'i', [$uid]);
        flash_set('success', 'Read notifications cleared.');
    }
    redirect('notifications.php');
}

$filter = safe_choice(get_str('filter'), ['', 'unread', 'success', 'warning', 'danger', 'info'], '');

$sql = 'SELECT * FROM notifications WHERE user_id = ?';
$types = 'i';
$params = [$uid];
if ($filter === 'unread') { $sql .= ' AND is_read = 0'; }
elseif ($filter !== '')   { $sql .= ' AND type = ?'; $types .= 's'; $params[] = $filter; }
$sql .= ' ORDER BY created_at DESC LIMIT 200';

$notifs = db_all($conn, $sql, $types, $params);
$unread = unread_notification_count($uid);
$total  = (int)(db_one($conn, 'SELECT COUNT(*) c FROM notifications WHERE user_id = ?', 'i', [$uid])['c'] ?? 0);

// Opening the inbox marks everything read after rendering (so badges are honest).
page_header('Notifications', 'notifications', $unread . ' unread of ' . $total);

// Mark as read now that the page has been served to the user's screen.
mark_notifications_read($uid);
?>

<div class="card mb-3">
  <div class="row-between wrap" style="gap:12px">
    <div class="row wrap" style="gap:8px">
      <?php foreach (['' => 'All', 'unread' => 'Unread', 'success' => 'Approvals', 'warning' => 'Action needed', 'danger' => 'Rejected', 'info' => 'Information'] as $k => $l): ?>
        <a class="chip chip-selectable chip-lg<?= $filter === $k ? ' selected' : '' ?>" href="?<?= $k === '' ? '' : 'filter=' . $k ?>"><?= e($l) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="row wrap" style="gap:8px">
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all">
        <button class="btn btn-tonal btn-sm"><?= icon('check', 'icon icon-sm') ?> Mark all read</button>
      </form>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_read">
        <button class="btn btn-text btn-sm" data-confirm="Delete all read notifications?"><?= icon('delete', 'icon icon-sm') ?> Clear read</button>
      </form>
    </div>
  </div>
</div>

<?php if (!$notifs): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= icon('notifications') ?></div>
      <h3 class="title-m mb-0">No notifications</h3>
      <p class="tiny"><?= $filter ? 'Nothing matches this filter.' : 'Updates about your applications will appear here.' ?></p>
    </div>
  </div>
<?php else: ?>
  <div class="stack" style="gap:12px">
    <?php foreach ($notifs as $i => $n): ?>
      <div class="card card-hover anim-rise anim-rise-<?= min(4, ($i % 4) + 1) ?>"
           style="display:flex;gap:16px;align-items:flex-start;padding:18px 22px<?= $n['is_read'] ? '' : ';box-shadow:inset 3px 0 0 var(--md-primary), var(--e1)' ?>">
        <span class="notif-icon <?= e($n['type']) ?>" style="width:44px;height:44px;border-radius:14px">
          <?= icon($n['type'] === 'success' ? 'check_circle' : ($n['type'] === 'danger' ? 'error' : ($n['type'] === 'warning' ? 'warning' : 'info'))) ?>
        </span>
        <div class="grow" style="min-width:0">
          <div class="row-between wrap" style="gap:8px">
            <span class="label-m" style="font-size:1rem"><?= e($n['title']) ?></span>
            <span class="tiny"><?= e(time_ago($n['created_at'])) ?> &middot; <?= fmt_datetime($n['created_at']) ?></span>
          </div>
          <p class="body-m muted mb-1" style="font-size:.9375rem"><?= e($n['message']) ?></p>
          <?php if ($n['link']): ?>
            <a class="btn btn-text btn-sm" href="<?= e(app_url($n['link'])) ?>">Open <?= icon('arrow_back', 'icon icon-sm') ?></a>
          <?php endif; ?>
        </div>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
          <button class="icon-btn" aria-label="Delete notification" data-confirm="Delete this notification?"><?= icon('close', 'icon icon-sm') ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php page_footer(); ?>
