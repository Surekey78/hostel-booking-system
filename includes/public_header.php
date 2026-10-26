<?php
/**
 * Public-facing shared header/footer for the landing + auth pages.
 * These pages do not use the navigation rail.
 */
require_once __DIR__ . '/functions.php';

function public_head(string $title, string $bodyClass = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#6750A4">
<title><?= e($title) ?> &middot; <?= APP_NAME ?></title>
<meta name="description" content="HostelEase — online hostel room booking, application and assignment system.">
<link rel="stylesheet" href="<?= app_url('assets/css/fonts.css') ?>">
<link rel="stylesheet" href="<?= app_url('assets/css/material-you.css') ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='7' fill='%236750A4'/><path d='M21 10H7V7a2 2 0 0 0-2-2H3v14h2v-3h16a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2Z' fill='white'/></svg>">
</head>
<body class="<?= e($bodyClass) ?>">
    <?php
}

function public_foot(): void
{
    ?>
<div class="scrim" data-scrim></div>
<script src="<?= app_url('assets/js/app.js') ?>"></script>
</body>
</html>
    <?php
}

/** The marketing top navigation bar. */
function landing_nav(): void
{
    $user = current_user();
    ?>
<nav class="nav-landing">
  <div class="container nav-landing-inner">
    <a class="brand" href="<?= app_url('index.php') ?>" style="padding:0">
      <span class="brand-mark"><?= icon('apartment') ?></span>
      <span class="brand-text">
        <span class="brand-name"><?= APP_NAME ?></span>
        <span class="brand-sub"><?= e(APP_TAGLINE) ?></span>
      </span>
    </a>
    <div class="nav-links">
      <a class="nav-link" href="index.php#hostels">Hostels</a>
      <a class="nav-link" href="index.php#how">How it works</a>
      <a class="nav-link" href="index.php#pricing">Room types</a>
      <a class="nav-link" href="index.php#faq">FAQ</a>
    </div>
    <?php if ($user): ?>
      <a class="btn" href="<?= app_url(($user['role'] === 'admin' ? 'admin/' : 'student/') . 'dashboard.php') ?>">
        <?= icon('dashboard') ?> Go to <?= e($user['role'] === 'admin' ? 'console' : 'portal') ?>
      </a>
    <?php else: ?>
      <a class="btn btn-text" href="<?= app_url('login.php') ?>">Sign in</a>
      <a class="btn" href="<?= app_url('register.php') ?>">Create account</a>
    <?php endif; ?>
  </div>
</nav>
    <?php
}
