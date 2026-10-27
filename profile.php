<?php



require_once __DIR__ . '/includes/header.php';
$me   = require_login();
$uid  = (int)$me['id'];
$role = $me['role'];
$errors = [];

$old = [
    'full_name' => $me['full_name'], 'email' => $me['email'], 'phone' => (string)$me['phone'],
    'gender' => (string)$me['gender'], 'programme' => (string)$me['programme'], 'year_level' => (string)$me['year_level'],
    'address' => (string)$me['address'], 'emergency_contact_name' => (string)$me['emergency_contact_name'],
    'emergency_contact_phone' => (string)$me['emergency_contact_phone'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form = $_POST['form'] ?? 'profile';

    if ($form === 'profile') {
        foreach ($old as $k => $_) {
            $old[$k] = trim((string)($_POST[$k] ?? ''));
        }
        if (mb_strlen($old['full_name']) < 3) {
            $errors['full_name'] = 'Enter your full name.';
        }
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        } else {
            $dup = db_one($conn, 'SELECT id FROM users WHERE email = ? AND id <> ?', 'si', [$old['email'], $uid]);
            if ($dup) {
                $errors['email'] = 'That email address is already used by another account.';
            }
        }
        if ($role === 'student') {
            if (!in_array($old['gender'], ['male', 'female', 'other'], true)) {
                $errors['gender'] = 'Please select a gender — it is used to match hostel block eligibility.';
            }
            if ($old['programme'] === '') {
                $errors['programme'] = 'Enter your programme.';
            }
        }

        if (!$errors) {
            db_exec(
                $conn,
                'UPDATE users SET full_name=?, email=?, phone=?, gender=?, programme=?, year_level=?, address=?,
                                  emergency_contact_name=?, emergency_contact_phone=? WHERE id=?',
                'sssssssssi',
                [
                    $old['full_name'], $old['email'], $old['phone'] ?: null, $old['gender'] ?: null,
                    $old['programme'] ?: null, $old['year_level'] ?: null, $old['address'] ?: null,
                    $old['emergency_contact_name'] ?: null, $old['emergency_contact_phone'] ?: null, $uid,
                ]
            );
            $_SESSION['full_name'] = $old['full_name'];
            flash_set('success', 'Profile updated successfully.');
            redirect('profile.php');
        }
    }

    if ($form === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            $errors['current_password'] = 'Your current password is incorrect.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'The new password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $errors['new_password'] = 'Use a mix of letters and numbers.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if ($new === $current && !$errors) {
            $errors['new_password'] = 'The new password must be different from the current one.';
        }
        if (!$errors) {
            db_exec($conn, 'UPDATE users SET password_hash=? WHERE id=?', 'si', [password_hash($new, PASSWORD_DEFAULT), $uid]);
            notify($uid, 'Password changed', 'Your account password was changed on ' . date('d M Y, g:i A') . '. If this was not you, contact the hostel office immediately.', 'warning');
            flash_set('success', 'Password changed successfully.');
            redirect('profile.php');
        }
        $old['_pw_errors'] = true;
    }
}

$summary = null;
if ($role === 'student') {
    $summary = db_one(
        $conn,
        "SELECT COUNT(*) total, SUM(status='assigned') assigned, SUM(status='pending') pending FROM bookings WHERE student_id = ?",
        'i',
        [$uid]
    );
} else {
    $summary = db_one(
        $conn,
        "SELECT (SELECT COUNT(*) FROM bookings WHERE processed_by = ?) processed,
                (SELECT COUNT(*) FROM assignments WHERE assigned_by = ?) assigned,
                (SELECT COUNT(*) FROM bookings WHERE status='pending') pending"
    , 'ii', [$uid, $uid]);
}

page_header('My Profile', 'profile', e($role === 'admin' ? 'Hostel administrator account' : 'Student account'));
?>

<div class="grid" style="grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:24px;align-items:start">
  <div class="stack">
    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Personal information</h3>
          <p class="card-sub">Keep this accurate — hostel eligibility depends on it.</p>
        </div>
        <span class="chip chip-primary chip-lg"><?= e(ucfirst($role)) ?></span>
      </div>

      <form method="post" action="profile.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="profile">

        <div class="row mb-3" style="gap:16px">
          <span class="avatar lg"><?= e(initials($me['full_name'])) ?></span>
          <div>
            <div class="title-m mb-0"><?= e($me['full_name']) ?></div>
            <div class="tiny"><?= e($role === 'admin' ? ($me['student_id'] ?: 'Administrator') : $me['student_id']) ?> &middot; member since <?= fmt_date($me['created_at']) ?></div>
          </div>
        </div>

        <div class="form-grid">
          <div class="field <?= isset($errors['full_name']) ? 'field-error' : '' ?>">
            <label class="field-label" for="full_name">Full name <span class="req">*</span></label>
            <input class="input" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required>
            <?php if (isset($errors['full_name'])): ?><span class="error-text"><?= e($errors['full_name']) ?></span><?php endif; ?>
          </div>

          <div class="field <?= isset($errors['email']) ? 'field-error' : '' ?>">
            <label class="field-label" for="email">Email address <span class="req">*</span></label>
            <input class="input" type="email" id="email" name="email" value="<?= e($old['email']) ?>" required>
            <?php if (isset($errors['email'])): ?><span class="error-text"><?= e($errors['email']) ?></span><?php endif; ?>
          </div>

          <div class="field">
            <label class="field-label" for="phone">Phone number</label>
            <input class="input" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="0917 000 0000">
          </div>

          <?php if ($role === 'student'): ?>
            <div class="field <?= isset($errors['gender']) ? 'field-error' : '' ?>">
              <label class="field-label" for="gender">Gender <span class="req">*</span></label>
              <select class="select" id="gender" name="gender" required>
                <option value="">Select&hellip;</option>
                <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Prefer not to say'] as $v => $l): ?>
                  <option value="<?= $v ?>" <?= $old['gender'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['gender'])): ?><span class="error-text"><?= e($errors['gender']) ?></span>
              <?php else: ?><span class="help-text">Used to match gender-specific hostel blocks.</span><?php endif; ?>
            </div>

            <div class="field">
              <label class="field-label" for="student_id_ro">Student / Matric no.</label>
              <input class="input" id="student_id_ro" value="<?= e((string)$me['student_id']) ?>" readonly>
              <span class="help-text">Contact the hostel office to correct your student ID.</span>
            </div>

            <div class="field <?= isset($errors['programme']) ? 'field-error' : '' ?>">
              <label class="field-label" for="programme">Programme / Course <span class="req">*</span></label>
              <input class="input" id="programme" name="programme" value="<?= e($old['programme']) ?>" required>
              <?php if (isset($errors['programme'])): ?><span class="error-text"><?= e($errors['programme']) ?></span><?php endif; ?>
            </div>

            <div class="field">
              <label class="field-label" for="year_level">Year level</label>
              <select class="select" id="year_level" name="year_level">
                <option value="">Select&hellip;</option>
                <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year', 'Postgraduate'] as $y): ?>
                  <option value="<?= $y ?>" <?= $old['year_level'] === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <div class="field" style="grid-column:1/-1">
            <label class="field-label" for="address">Home address</label>
            <textarea class="textarea" id="address" name="address" rows="2" style="min-height:72px"><?= e($old['address']) ?></textarea>
          </div>
        </div>

        <?php if ($role === 'student'): ?>
          <div class="card card-flat card-pad-sm mt-2">
            <div class="label-s mb-1" style="color:var(--md-primary)">Emergency contact</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label" for="emergency_contact_name">Contact name</label>
                <input class="input" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($old['emergency_contact_name']) ?>">
              </div>
              <div class="field">
                <label class="field-label" for="emergency_contact_phone">Contact phone</label>
                <input class="input" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($old['emergency_contact_phone']) ?>">
              </div>
            </div>
          </div>
        <?php endif; ?>

        <div class="row mt-3" style="gap:10px">
          <button type="submit" class="btn"><?= icon('check') ?> Save changes</button>
          <a class="btn btn-text" href="profile.php">Discard</a>
        </div>
      </form>
    </div>

    <div class="card card-pad-lg">
      <div class="card-header">
        <div>
          <h3 class="card-title">Change password</h3>
          <p class="card-sub">Minimum 8 characters with letters and numbers.</p>
        </div>
        <span class="stat-icon tone-6"><?= icon('lock') ?></span>
      </div>
      <form method="post" action="profile.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="password">
        <div class="form-grid">
          <div class="field <?= isset($errors['current_password']) ? 'field-error' : '' ?>">
            <label class="field-label" for="current_password">Current password <span class="req">*</span></label>
            <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            <?php if (isset($errors['current_password'])): ?><span class="error-text"><?= e($errors['current_password']) ?></span><?php endif; ?>
          </div>
          <div class="field <?= isset($errors['new_password']) ? 'field-error' : '' ?>">
            <label class="field-label" for="new_password">New password <span class="req">*</span></label>
            <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" required>
            <?php if (isset($errors['new_password'])): ?><span class="error-text"><?= e($errors['new_password']) ?></span><?php endif; ?>
          </div>
          <div class="field <?= isset($errors['confirm_password']) ? 'field-error' : '' ?>">
            <label class="field-label" for="confirm_password">Confirm new password <span class="req">*</span></label>
            <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
            <?php if (isset($errors['confirm_password'])): ?><span class="error-text"><?= e($errors['confirm_password']) ?></span><?php endif; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-tonal mt-2"><?= icon('key') ?> Update password</button>
      </form>
    </div>
  </div>

  <aside class="stack" style="position:sticky;top:96px">
    <div class="card">
      <h3 class="card-title mb-2">Account summary</h3>
      <?php if ($role === 'student'): ?>
        <div class="grid g-3" style="gap:12px;text-align:center">
          <div><div class="stat-value"><?= (int)$summary['total'] ?></div><div class="stat-label">Applications</div></div>
          <div><div class="stat-value"><?= (int)$summary['pending'] ?></div><div class="stat-label">Pending</div></div>
          <div><div class="stat-value"><?= (int)$summary['assigned'] ?></div><div class="stat-label">Rooms</div></div>
        </div>
        <a class="btn btn-tonal btn-block mt-2" href="student/my-bookings.php"><?= icon('assignment') ?> My applications</a>
        <a class="btn btn-outlined btn-block mt-1" href="student/my-assignment.php"><?= icon('key') ?> My room</a>
      <?php else: ?>
        <div class="grid g-3" style="gap:12px;text-align:center">
          <div><div class="stat-value"><?= (int)$summary['processed'] ?></div><div class="stat-label">Processed</div></div>
          <div><div class="stat-value"><?= (int)$summary['assigned'] ?></div><div class="stat-label">Assigned</div></div>
          <div><div class="stat-value"><?= (int)$summary['pending'] ?></div><div class="stat-label">In queue</div></div>
        </div>
        <a class="btn btn-tonal btn-block mt-2" href="admin/applications.php"><?= icon('assignment') ?> Review applications</a>
        <a class="btn btn-outlined btn-block mt-1" href="admin/reports.php"><?= icon('chart') ?> Reports</a>
      <?php endif; ?>
    </div>

    <div class="card card-flat">
      <h3 class="card-title mb-2">Account details</h3>
      <dl class="dl">
        <dt>User ID</dt><dd>#<?= str_pad((string)$uid, 5, '0', STR_PAD_LEFT) ?></dd>
        <dt>Role</dt><dd><?= e(ucfirst($role)) ?></dd>
        <dt>Status</dt><dd><span class="chip chip-success"><span class="chip-dot"></span><?= e(ucfirst($me['status'])) ?></span></dd>
        <dt>Registered</dt><dd><?= fmt_datetime($me['created_at']) ?></dd>
        <dt>Last update</dt><dd><?= fmt_datetime($me['updated_at']) ?></dd>
      </dl>
    </div>

    <div class="card card-flat">
      <div class="row" style="gap:12px">
        <span class="stat-icon"><?= icon('shield') ?></span>
        <div class="tiny">Your password is stored as a bcrypt hash. All forms are protected with CSRF tokens and every database query uses prepared statements.</div>
      </div>
    </div>
  </aside>
</div>

<?php page_footer(); ?>
