<?php
/**
 * index.php - Public landing page (Material You marketing surface).
 */
require_once __DIR__ . '/includes/public_header.php';

$hostels = db_all($conn, 'SELECT * FROM v_hostel_occupancy ORDER BY block_code');
$types   = db_all($conn, 'SELECT * FROM room_types ORDER BY price');
$stats   = db_one(
    $conn,
    "SELECT (SELECT COUNT(*) FROM hostels) hostels,
            (SELECT COUNT(*) FROM rooms) rooms,
            (SELECT COALESCE(SUM(rt.capacity),0) FROM rooms r JOIN room_types rt ON rt.id=r.room_type_id) beds,
            (SELECT COUNT(*) FROM users WHERE role='student') students"
);
$vacant = total_vacancy();

public_head('Hostel Accommodation, Simplified');
landing_nav();
?>

<!-- ================= HERO ================= -->
<section class="section" style="padding-top:44px">
  <div class="container">
    <div class="hero hero-primary anim-rise">
      <span class="blur-shape" style="width:340px;height:340px;background:rgba(255,255,255,.20);top:-110px;right:-80px" aria-hidden="true"></span>
      <span class="blur-shape" style="width:280px;height:280px;background:rgba(232,222,248,.28);bottom:-120px;left:10%" aria-hidden="true"></span>
      <span class="blur-shape" style="width:180px;height:180px;background:rgba(125,82,96,.35);top:30%;right:26%" aria-hidden="true"></span>

      <div class="grid g-2" style="align-items:center;gap:40px">
        <div>
          <span class="chip glass chip-lg mb-2"><?= icon('bolt', 'icon icon-xs') ?> <?= (int)$stats['hostels'] ?> hostel blocks &middot; <?= number_format((int)$stats['beds']) ?> beds</span>
          <h1 class="display-l">Find your room.<br>Apply in minutes.</h1>
          <p class="body-l muted mb-3" style="max-width:52ch">
            <?= APP_NAME ?> lets students browse hostel blocks, compare room types and prices,
            check live vacancy, and submit accommodation applications &mdash; while administrators
            approve, reject and assign rooms from one console.
          </p>
          <div class="row wrap">
            <a class="btn btn-lg" style="--btn-bg:#fff;--btn-fg:#6750A4" href="register.php"><?= icon('person') ?> Create student account</a>
            <a class="btn btn-lg glass" href="login.php"><?= icon('lock') ?> Sign in</a>
          </div>
          <p class="tiny mt-2" style="color:rgba(255,255,255,.75)">
            <?= number_format($vacant) ?> beds currently vacant &middot; No paperwork, no queues at the hostel office.
          </p>
        </div>

        <div class="glass card-pad-lg" style="color:#fff">
          <div class="row-between mb-2">
            <span class="label-s" style="color:rgba(255,255,255,.8)">Live vacancy snapshot</span>
            <span class="chip" style="background:rgba(255,255,255,.18);color:#fff"><span class="chip-dot"></span> Real time</span>
          </div>
          <?php if (!$hostels): ?>
            <p class="muted">No hostel data yet &mdash; import <code>database/seed_data.sql</code>.</p>
          <?php else: foreach (array_slice($hostels, 0, 4) as $h):
              $rate = (float)$h['occupancy_rate'];
          ?>
            <div style="margin-bottom:16px">
              <div class="row-between" style="gap:8px">
                <span class="label-m"><?= e($h['block_code']) ?> &middot; <?= e($h['name']) ?></span>
                <span class="tiny" style="color:rgba(255,255,255,.8)"><?= (int)$h['vacant_beds'] ?> vacant</span>
              </div>
              <div class="bar mt-1" style="background:rgba(255,255,255,.22)">
                <span class="bar-fill" style="width:<?= $rate ?>%;background:#fff"></span>
              </div>
            </div>
          <?php endforeach; endif; ?>
          <hr class="divider" style="background:rgba(255,255,255,.25)">
          <div class="grid g-3" style="gap:12px;text-align:center">
            <div><div class="title-l"><?= (int)$stats['rooms'] ?></div><div class="tiny" style="color:rgba(255,255,255,.8)">Rooms</div></div>
            <div><div class="title-l"><?= (int)$stats['students'] ?></div><div class="tiny" style="color:rgba(255,255,255,.8)">Students</div></div>
            <div><div class="title-l"><?= number_format($vacant) ?></div><div class="tiny" style="color:rgba(255,255,255,.8)">Free beds</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================= HOSTELS ================= -->
<section class="section" id="hostels">
  <div class="container">
    <div class="row-between mb-3">
      <div>
        <span class="label-s" style="color:var(--md-primary)">Accommodation</span>
        <h2 class="headline-l mb-0">Hostel blocks &amp; facilities</h2>
      </div>
      <a class="btn btn-outlined" href="login.php"><?= icon('search') ?> Browse as a student</a>
    </div>

    <div class="grid g-3">
      <?php foreach ($hostels as $i => $h):
          $full = db_one($conn, 'SELECT * FROM hostels WHERE id = ?', 'i', [(int)$h['id']]);
      ?>
        <article class="card card-hover hostel-card group anim-rise anim-rise-<?= ($i % 4) + 1 ?>">
          <div class="hostel-visual">
            <span class="glow" aria-hidden="true"></span>
            <?= icon('apartment', 'icon icon-xl') ?>
            <span class="chip chip-primary hostel-code"><?= e($h['block_code']) ?></span>
          </div>
          <div class="hostel-body">
            <div>
              <h3 class="title-m mb-0"><?= e($h['name']) ?></h3>
              <p class="tiny mb-0"><?= e($full['location'] ?: 'On campus') ?> &middot; <?= e(ucfirst($h['gender_policy'])) ?></p>
            </div>
            <div class="row wrap" style="gap:6px">
              <?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string)$full['facilities']))), 0, 4) as $f): ?>
                <span class="chip chip-outline"><?= icon_for_facility($f) ?><?= e($f) ?></span>
              <?php endforeach; ?>
            </div>
            <div>
              <div class="row-between mb-1">
                <span class="label-m"><?= (int)$h['occupied_beds'] ?> / <?= (int)$h['total_beds'] ?> beds occupied</span>
                <span class="tiny"><?= (float)$h['occupancy_rate'] ?>%</span>
              </div>
              <?= occupancy_bar((int)$h['occupied_beds'], (int)$h['total_beds']) ?>
            </div>
            <div class="row-between mt-1">
              <span class="chip <?= (int)$h['vacant_beds'] > 0 ? 'chip-success' : 'chip-danger' ?>">
                <span class="chip-dot"></span><?= (int)$h['vacant_beds'] > 0 ? (int)$h['vacant_beds'] . ' beds vacant' : 'Fully occupied' ?>
              </span>
              <span class="tiny"><?= (int)$h['total_rooms'] ?> rooms</span>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= HOW IT WORKS ================= -->
<section class="section" id="how" style="background:var(--md-surface-container)">
  <div class="container">
    <div class="text-center mb-4">
      <span class="label-s" style="color:var(--md-tertiary)">Process</span>
      <h2 class="headline-l">Four steps to a confirmed room</h2>
      <p class="body-l muted" style="max-width:62ch;margin:0 auto">Everything is tracked inside the system, so you always know exactly where your application stands.</p>
    </div>
    <div class="grid g-4">
      <?php
      $steps = [
          ['1', 'person',   'Create your account', 'Register with your student ID, programme and emergency contact details.'],
          ['2', 'search',   'Search &amp; filter', 'Filter rooms by hostel block, room type, price, capacity or availability.'],
          ['3', 'assignment','Submit application', 'Choose your intake period and upload any required supporting documents.'],
          ['4', 'key',      'Get your assignment', 'Once approved, the system converts your application into a room + bed record.'],
      ];
      foreach ($steps as $i => [$num, $ico, $t, $d]): ?>
        <div class="card card-elevated group anim-rise anim-rise-<?= $i + 1 ?>">
          <div class="step-badge mb-2">
            <span class="glow" aria-hidden="true"></span>
            <?= e($num) ?>
          </div>
          <h3 class="title-m"><?= $t ?></h3>
          <p class="tiny mb-0"><?= $d ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= ROOM TYPES / PRICING ================= -->
<section class="section" id="pricing">
  <div class="container">
    <div class="text-center mb-4">
      <span class="label-s" style="color:var(--md-primary)">Room types</span>
      <h2 class="headline-l">Choose the space that fits you</h2>
      <p class="body-l muted" style="max-width:58ch;margin:0 auto">Rates are charged per semester and include the listed facilities.</p>
    </div>

    <div class="grid g-<?= count($types) >= 4 ? 4 : max(2, count($types)) ?>" style="align-items:start">
      <?php foreach ($types as $i => $t):
          $featured = ($i === 1);
      ?>
        <article class="card pricing-card card-hover<?= $featured ? ' featured' : '' ?>"
                 style="<?= $featured ? 'box-shadow:0 0 0 2px var(--md-primary), var(--e3);transform:translateY(-10px);' : '' ?>">
          <?php if ($featured): ?>
            <span class="chip chip-primary mb-2"><?= icon('bolt', 'icon icon-xs') ?> Most requested</span>
          <?php endif; ?>
          <h3 class="title-m mb-0"><?= e($t['name']) ?></h3>
          <p class="tiny mb-2"><?= e($t['description'] ?: 'Shared student accommodation') ?></p>
          <div class="row" style="align-items:baseline;gap:6px">
            <span class="display-l mb-0" style="font-size:2.25rem"><?= money($t['price']) ?></span>
            <span class="tiny">/ semester</span>
          </div>
          <div class="row mb-2" style="gap:8px">
            <span class="chip chip-outline"><?= icon('people', 'icon icon-xs') ?> <?= (int)$t['capacity'] ?> <?= (int)$t['capacity'] === 1 ? 'bed' : 'beds' ?></span>
            <span class="chip chip-outline"><?= icon('bed', 'icon icon-xs') ?> <?= (int)$t['capacity'] === 1 ? 'Private' : 'Shared' ?></span>
          </div>
          <ul class="pricing-list">
            <?php foreach (array_filter(array_map('trim', explode(',', (string)$t['facilities']))) as $f): ?>
              <li><span style="color:var(--md-primary);display:flex"><?= icon('check', 'icon icon-sm') ?></span><span class="tiny" style="align-self:center"><?= e($f) ?></span></li>
            <?php endforeach; ?>
          </ul>
          <a class="btn <?= $featured ? '' : 'btn-tonal' ?> btn-block mt-2" href="login.php">Apply for this room</a>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= FEATURES ================= -->
<section class="section" style="background:linear-gradient(135deg,var(--md-primary) 0%,#533F8C 50%,var(--md-tertiary) 100%);color:#fff;position:relative;overflow:hidden">
  <span class="blur-shape" style="width:400px;height:400px;background:rgba(255,255,255,.14);top:-140px;left:-100px" aria-hidden="true"></span>
  <span class="blur-shape" style="width:320px;height:320px;background:rgba(232,222,248,.20);bottom:-140px;right:-60px" aria-hidden="true"></span>
  <div class="container" style="position:relative;z-index:1">
    <h2 class="headline-l">Built for students <em style="font-style:normal;opacity:.7">and</em> administrators</h2>
    <div class="grid g-3 mt-3">
      <?php
      $features = [
          ['search',   'Smart room search',   'Filter by block, room type, price range, capacity and live availability.'],
          ['upload',   'Document uploads',    'Attach ID cards, guarantor letters or medical notes to any application.'],
          ['notifications','Status updates',  'Get notified the moment your application is approved, rejected or assigned.'],
          ['assignment','One-click assignment','Convert an approved application into a room and bed record instantly.'],
          ['chart',    'Occupancy reports',   'Vacancy, occupancy, booking and accommodation reports with CSV export.'],
          ['shield',   'Secure by design',    'Prepared statements, hashed passwords, CSRF tokens and role-based access.'],
      ];
      foreach ($features as [$ico, $t, $d]): ?>
        <div class="glass card-pad-lg" style="color:#fff">
          <span style="display:grid;place-items:center;width:44px;height:44px;border-radius:14px;background:rgba(255,255,255,.2);margin-bottom:12px"><?= icon($ico) ?></span>
          <h3 class="title-m"><?= e($t) ?></h3>
          <p class="muted mb-0" style="font-size:.9375rem"><?= e($d) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= FAQ ================= -->
<section class="section" id="faq">
  <div class="container" style="max-width:860px">
    <h2 class="headline-l text-center">Frequently asked questions</h2>
    <div class="stack mt-3">
      <?php
      $faqs = [
          ['Who can submit a hostel application?', 'Any student with an active account. Register with your student ID number, then browse hostels and submit an application for your chosen intake period.'],
          ['Can I choose my exact room?', 'You apply for a hostel block and a room type. Administrators assign the specific room and bed, though they can honour a preferred room if it is still vacant.'],
          ['What documents do I need to upload?', 'This depends on the hostel. Common requirements are a student ID copy, a guarantor letter, or a medical certificate. Supported formats are PDF, JPG, PNG, DOC and DOCX up to 3 MB.'],
          ['How do I know my application status?', 'Your dashboard and the "My Applications" page always show the latest status, and the system pushes a notification whenever an administrator takes action.'],
          ['What happens after approval?', 'An administrator converts the approved application into a room-assignment record. You then see your block, room number and bed number under "My Room".'],
      ];
      foreach ($faqs as [$q, $a]): ?>
        <details class="card card-hover" style="padding:0">
          <summary style="cursor:pointer;padding:20px 24px;list-style:none;display:flex;align-items:center;gap:12px" class="label-m">
            <span class="grow" style="font-size:1.0625rem;font-weight:500"><?= e($q) ?></span>
            <span style="color:var(--md-primary);display:flex"><?= icon('add') ?></span>
          </summary>
          <div style="padding:0 24px 22px" class="body-m muted"><?= e($a) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ================= FINAL CTA ================= -->
<section class="section-tight">
  <div class="container">
    <div class="hero" style="border-radius:var(--r-3xl);background:var(--md-surface-container);position:relative;overflow:hidden">
      <span class="blur-shape blur-a" aria-hidden="true"></span>
      <span class="blur-shape blur-b" aria-hidden="true"></span>
      <div class="text-center" style="position:relative;z-index:1">
        <h2 class="headline-l"><?= number_format($vacant) ?> beds are still vacant</h2>
        <p class="body-l muted mb-3">Applications close once blocks are full. Secure your accommodation for the upcoming intake today.</p>
        <div class="row wrap" style="justify-content:center">
          <a class="btn btn-lg" href="register.php"><?= icon('add') ?> Apply now</a>
          <a class="btn btn-lg btn-outlined" href="login.php"><?= icon('lock') ?> Administrator sign in</a>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="footer">
  <div class="container row-between">
    <span>&copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; <?= e(APP_TAGLINE) ?></span>
    <span>PHP &middot; MySQL &middot; mysqli &middot; Material Design 3</span>
  </div>
</footer>

<?php public_foot(); ?>
