<?php
/**
 * register.php - Student self-registration (accommodation account).
 */
require_once __DIR__ . '/includes/public_header.php';

if (is_logged_in()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
}

$errors = [];
$old = [
    'full_name' => '', 'student_id' => '', 'email' => '', 'phone' => '',
    'gender' => '', 'programme' => '', 'year_level' => '', 'address' => '',
    'emergency_contact_name' => '', 'emergency_contact_phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($old as $k => $_) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $password  = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password_confirm'] ?? '');

    // ---- Validation ----
    if (mb_strlen($old['full_name']) < 3) {
        $errors['full_name'] = 'Enter your full name (at least 3 characters).';
    }
    if (!preg_match('/^[A-Za-z0-9\-\/]{4,20}$/', $old['student_id'])) {
        $errors['student_id'] = 'Student ID must be 4-20 letters, numbers, hyphens or slashes.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($old['phone'] !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $old['phone'])) {
        $errors['phone'] = 'Enter a valid phone number.';
    }
    if (!in_array($old['gender'], ['male', 'female', 'other'], true)) {
        $errors['gender'] = 'Please select a gender.';
    }
    if ($old['programme'] === '') {
        $errors['programme'] = 'Enter your programme / course.';
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain both letters and numbers.';
    }
    if ($password !== $password2) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }
    if (empty($_POST['agree'])) {
        $errors['agree'] = 'You must accept the hostel accommodation policy.';
    }

    // ---- Uniqueness ----
    if (!$errors) {
        $dup = db_one($conn, 'SELECT id, email, student_id FROM users WHERE email = ? OR student_id = ? LIMIT 1', 'ss', [$old['email'], $old['student_id']]);
        if ($dup) {
            if (strcasecmp($dup['email'], $old['email']) === 0) {
                $errors['email'] = 'An account with this email already exists.';
            } else {
                $errors['student_id'] = 'This student ID is already registered.';
            }
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'student';
        $status = 'active';
        db_exec(
            $conn,
            'INSERT INTO users (role, student_id, full_name, email, phone, password_hash, gender, programme, year_level, address,
                                emergency_contact_name, emergency_contact_phone, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            'sssssssssssss',
            [
                $role, $old['student_id'], $old['full_name'], $old['email'], $old['phone'] ?: null, $hash,
                $old['gender'], $old['programme'], $old['year_level'] ?: null, $old['address'] ?: null,
                $old['emergency_contact_name'] ?: null, $old['emergency_contact_phone'] ?: null, $status,
            ]
        );
        $newId = mysqli_insert_id($conn);

        notify(
            $newId,
            'Welcome to ' . APP_NAME,
            'Your student account has been created. Browse the available hostel blocks and submit your first accommodation application.',
            'success',
            'student/hostels.php'
        );

        // Let administrators know a new student registered.
        foreach (db_all($conn, "SELECT id FROM users WHERE role = 'admin'") as $adm) {
            notify((int)$adm['id'], 'New student registered', $old['full_name'] . ' (' . $old['student_id'] . ') created a student account.', 'info', 'admin/students.php');
        }

        $user = db_one($conn, 'SELECT * FROM users WHERE id = ?', 'i', [$newId]);
        login_user($user);
        flash_set('success', 'Account created successfully. Welcome, ' . $user['full_name'] . '!');
        redirect('student/dashboard.php');
    }
}

public_head('Create student account');
?>

<div class="auth-wrap">
  <aside class="auth-side">
    <span class="blur-shape" style="width:340px;height:340px;background:rgba(255,255,255,.16);top:-110px;right:-90px" aria-hidden="true"></span>
    <span class="blur-shape" style="width:280px;height:280px;background:rgba(232,222,248,.22);bottom:-100px;left:-70px" aria-hidden="true"></span>
    <div style="position:relative;z-index:1">
      <a class="brand" href="index.php" style="padding:0 0 36px">
        <span class="brand-mark" style="background:rgba(255,255,255,.18);box-shadow:none"><?= icon('apartment') ?></span>
        <span class="brand-text">
          <span class="brand-name" style="color:#fff"><?= APP_NAME ?></span>
          <span class="brand-sub" style="color:rgba(255,255,255,.75)">Student registration</span>
        </span>
      </a>
      <h2 class="headline-l" style="color:#fff">One account.<br>Every hostel application.</h2>
      <p class="body-l muted mb-4" style="max-width:44ch">
        Your details are used to verify eligibility, match gender-specific blocks and contact your next of kin if needed.
      </p>
      <div class="stack" style="gap:12px;max-width:400px">
        <?php
        $vacant = total_vacancy();
        $items = [
            ['bed',   number_format($vacant) . ' beds currently vacant'],
            ['apartment', count(hostel_options()) . ' hostel blocks open for application'],
            ['shield', 'Your password is hashed with bcrypt'],
        ];
        foreach ($items as [$ico, $t]): ?>
          <div class="glass row" style="padding:14px 18px;color:#fff">
            <span style="display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.2)"><?= icon($ico, 'icon icon-sm') ?></span>
            <span class="label-m"><?= e($t) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  <section class="auth-panel" style="align-items:start;padding-top:48px">
    <div class="auth-card" style="max-width:560px">
      <div class="row-between mb-2">
        <h1 class="headline-m mb-0">Create your account</h1>
        <a class="btn btn-text btn-sm" href="login.php">Sign in instead</a>
      </div>
      <p class="tiny mb-3">Fields marked <span style="color:var(--md-error)">*</span> are required.</p>

      <?= flash_render() ?>

      <form method="post" action="register.php" novalidate>
        <?= csrf_field() ?>
        <div class="stack">
          <div class="field <?= isset($errors['full_name']) ? 'field-error' : '' ?>">
            <label class="field-label" for="full_name">Full name <span class="req">*</span></label>
            <input class="input" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" placeholder="e.g. Juan Dela Cruz" required>
            <?php if (isset($errors['full_name'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['full_name']) ?></span><?php endif; ?>
          </div>

          <div class="form-grid">
            <div class="field <?= isset($errors['student_id']) ? 'field-error' : '' ?>">
              <label class="field-label" for="student_id">Student / Matric no. <span class="req">*</span></label>
              <input class="input" id="student_id" name="student_id" value="<?= e($old['student_id']) ?>" placeholder="e.g. 2026-00412" required>
              <?php if (isset($errors['student_id'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['student_id']) ?></span><?php endif; ?>
            </div>
            <div class="field <?= isset($errors['gender']) ? 'field-error' : '' ?>">
              <label class="field-label" for="gender">Gender <span class="req">*</span></label>
              <select class="select" id="gender" name="gender" required>
                <option value="">Select&hellip;</option>
                <?php foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Prefer not to say'] as $v => $l): ?>
                  <option value="<?= $v ?>" <?= $old['gender'] === $v ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['gender'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['gender']) ?></span><?php endif; ?>
            </div>
          </div>

          <div class="field <?= isset($errors['email']) ? 'field-error' : '' ?>">
            <label class="field-label" for="email">Email address <span class="req">*</span></label>
            <input class="input" type="email" id="email" name="email" value="<?= e($old['email']) ?>" placeholder="you@university.edu" autocomplete="email" required>
            <?php if (isset($errors['email'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['email']) ?></span><?php endif; ?>
          </div>

          <div class="field <?= isset($errors['phone']) ? 'field-error' : '' ?>">
            <label class="field-label" for="phone">Phone number</label>
            <input class="input" id="phone" name="phone" value="<?= e($old['phone']) ?>" placeholder="e.g. 0917 123 4567" autocomplete="tel">
            <?php if (isset($errors['phone'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['phone']) ?></span><?php endif; ?>
          </div>

          <div class="form-grid">
            <div class="field <?= isset($errors['programme']) ? 'field-error' : '' ?>">
              <label class="field-label" for="programme">Programme / Course <span class="req">*</span></label>
              <input class="input" id="programme" name="programme" value="<?= e($old['programme']) ?>" placeholder="e.g. BS Information Technology" required>
              <?php if (isset($errors['programme'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['programme']) ?></span><?php endif; ?>
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
          </div>

          <div class="field">
            <label class="field-label" for="address">Home address</label>
            <textarea class="textarea" id="address" name="address" rows="2" placeholder="Street, city, province" style="min-height:76px"><?= e($old['address']) ?></textarea>
          </div>

          <div class="card card-flat card-pad-sm">
            <div class="label-s mb-1" style="color:var(--md-primary)">Emergency contact</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label" for="emergency_contact_name">Contact name</label>
                <input class="input" id="emergency_contact_name" name="emergency_contact_name" value="<?= e($old['emergency_contact_name']) ?>" placeholder="Parent / guardian">
              </div>
              <div class="field">
                <label class="field-label" for="emergency_contact_phone">Contact phone</label>
                <input class="input" id="emergency_contact_phone" name="emergency_contact_phone" value="<?= e($old['emergency_contact_phone']) ?>" placeholder="0917 000 0000">
              </div>
            </div>
          </div>

          <div class="form-grid">
            <div class="field <?= isset($errors['password']) ? 'field-error' : '' ?>">
              <label class="field-label" for="password">Password <span class="req">*</span></label>
              <input class="input" type="password" id="password" name="password" placeholder="Min. 8 characters" autocomplete="new-password" required style="padding-right:48px">
              <button type="button" class="icon-btn field-icon" data-toggle-password="password" aria-label="Show password" style="width:32px;height:32px;bottom:10px;opacity:.55"><?= icon('visibility', 'icon icon-sm') ?></button>
              <?php if (isset($errors['password'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['password']) ?></span>
              <?php else: ?><span class="help-text">At least 8 characters, with letters and numbers.</span><?php endif; ?>
            </div>
            <div class="field <?= isset($errors['password_confirm']) ? 'field-error' : '' ?>">
              <label class="field-label" for="password_confirm">Confirm password <span class="req">*</span></label>
              <input class="input" type="password" id="password_confirm" name="password_confirm" placeholder="Repeat password" autocomplete="new-password" required style="padding-right:48px">
              <button type="button" class="icon-btn field-icon" data-toggle-password="password_confirm" aria-label="Show password" style="width:32px;height:32px;bottom:10px;opacity:.55"><?= icon('visibility', 'icon icon-sm') ?></button>
              <?php if (isset($errors['password_confirm'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['password_confirm']) ?></span><?php endif; ?>
            </div>
          </div>

          <label class="check">
            <input type="checkbox" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
            <span class="check-box"><?= icon('check') ?></span>
            <span class="check-text">I confirm the information above is accurate and I accept the hostel accommodation policy, including the rules on visitors, curfew and damage liability.</span>
          </label>
          <?php if (isset($errors['agree'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['agree']) ?></span><?php endif; ?>

          <button type="submit" class="btn btn-lg btn-block"><?= icon('person') ?> Create account</button>
        </div>
      </form>
    </div>
  </section>
</div>

<?php public_foot(); ?>
