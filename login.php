<?php



require_once __DIR__ . '/includes/public_header.php';

if (is_logged_in()) {
    $u = current_user();
    redirect($u['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role     = $_POST['role'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }

    if (!$errors) {
        $user = attempt_login($email, $password);
        if (!$user) {
            $errors['form'] = 'Incorrect email or password. Please try again.';
        } elseif ($role !== '' && $role !== 'any' && $user['role'] !== $role) {
            $errors['form'] = 'That account is registered as ' . ($user['role'] === 'admin' ? 'an administrator' : 'a student')
                            . ', so please use the ' . ucfirst($user['role']) . ' tab.';
        } else {
            login_user($user);
            flash_set('success', 'Welcome back, ' . $user['full_name'] . '!');
            redirect($user['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
        }
    }
}

$tab = ($_GET['role'] ?? $_POST['role'] ?? 'any');
public_head('Sign in');
?>

<div class="auth-wrap">
  
  <aside class="auth-side">
    <span class="blur-shape" style="width:340px;height:340px;background:rgba(255,255,255,.16);top:-110px;right:-90px" aria-hidden="true"></span>
    <span class="blur-shape" style="width:280px;height:280px;background:rgba(232,222,248,.22);bottom:-100px;left:-70px" aria-hidden="true"></span>

    <div style="position:relative;z-index:1">
      <a class="brand" href="index.php" style="padding:0 0 40px">
        <span class="brand-mark" style="background:rgba(255,255,255,.18);box-shadow:none"><?= icon('apartment') ?></span>
        <span class="brand-text">
          <span class="brand-name" style="color:#fff"><?= APP_NAME ?></span>
          <span class="brand-sub" style="color:rgba(255,255,255,.75)"><?= e(APP_TAGLINE) ?></span>
        </span>
      </a>

      <h2 class="headline-l" style="color:#fff">Welcome back to<br>your accommodation portal.</h2>
      <p class="body-l muted mb-4" style="max-width:44ch">
        Track application status, view your room assignment and receive updates &mdash; all in one place.
      </p>

      <div class="stack" style="gap:14px;max-width:420px">
        <?php
        $points = [
            ['assignment', 'Application status at a glance'],
            ['key',        'Room and bed assignment details'],
            ['notifications', 'Instant notification updates'],
        ];
        foreach ($points as [$ico, $t]): ?>
          <div class="glass row" style="padding:14px 18px;color:#fff">
            <span style="display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.2)"><?= icon($ico, 'icon icon-sm') ?></span>
            <span class="label-m"><?= e($t) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  
  <section class="auth-panel">
    <div class="auth-card">
      <div class="row-between mb-3">
        <h1 class="headline-m mb-0">Sign in</h1>
        <a class="btn btn-text btn-sm" href="index.php"><?= icon('arrow_back', 'icon icon-sm') ?> Home</a>
      </div>

      <div class="auth-tabs" role="tablist" aria-label="Account type">
        <a class="auth-tab<?= $tab !== 'admin' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab !== 'admin' ? 'true' : 'false' ?>" href="?role=student">Student</a>
        <a class="auth-tab<?= $tab === 'admin' ? ' active' : '' ?>" role="tab" aria-selected="<?= $tab === 'admin' ? 'true' : 'false' ?>" href="?role=admin">Administrator</a>
      </div>

      <?= flash_render() ?>

      <?php if (!empty($errors['form'])): ?>
        <div class="alert alert-danger mb-2" role="alert">
          <span class="alert-icon"><?= icon('error') ?></span><span><?= e($errors['form']) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" action="login.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="role" value="<?= e($tab === 'admin' ? 'admin' : ($tab === 'student' ? 'student' : 'any')) ?>">

        <div class="stack">
          <div class="field <?= isset($errors['email']) ? 'field-error' : '' ?>">
            <label class="field-label" for="email">Email address <span class="req">*</span></label>
            <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>"
                   placeholder="you@university.edu" autocomplete="email" required>
            <?php if (isset($errors['email'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['email']) ?></span><?php endif; ?>
          </div>

          <div class="field <?= isset($errors['password']) ? 'field-error' : '' ?>">
            <label class="field-label" for="password">Password <span class="req">*</span></label>
            <input class="input" type="password" id="password" name="password" placeholder="Enter your password"
                   autocomplete="current-password" required style="padding-right:48px">
            <button type="button" class="icon-btn field-icon" data-toggle-password="password" aria-label="Show password" style="width:32px;height:32px;bottom:10px;opacity:.55"><?= icon('visibility', 'icon icon-sm') ?></button>
            <?php if (isset($errors['password'])): ?><span class="error-text"><?= icon('error', 'icon icon-xs') ?> <?= e($errors['password']) ?></span><?php endif; ?>
          </div>

          <button type="submit" class="btn btn-lg btn-block"><?= icon('logout') ?> Sign in</button>

          <p class="tiny text-center mb-0">
            Don't have an account? <a href="register.php">Create a student account</a>
          </p>
        </div>
      </form>

      <div class="demo-card mt-3">
        <div class="row-between">
          <span class="label-s" style="color:var(--md-primary)">Demo credentials</span>
          <span class="chip chip-outline">seeded</span>
        </div>
        <div><span class="muted">Admin:</span> <span class="demo-creds">admin@hostel.edu / Admin@123</span></div>
        <div><span class="muted">Student:</span> <span class="demo-creds">maria.santos@university.edu / Student@123</span></div>
      </div>
    </div>
  </section>
</div>

<?php public_foot(); ?>
