<?php



require_once __DIR__ . '/../includes/header.php';
$me = require_student();

$hostels = db_all($conn, 'SELECT * FROM v_hostel_occupancy ORDER BY block_code');
$types   = room_type_options();

page_header('Browse Hostels', 'hostels', number_format((float)total_vacancy()) . ' beds currently vacant across ' . count($hostels) . ' blocks');
?>

<div class="row-between mb-3 wrap">
  <div>
    <h2 class="headline-m mb-0">Available hostels</h2>
    <p class="tiny mb-0">Compare blocks, facilities, room types and live vacancy before you apply.</p>
  </div>
  <div class="row wrap">
    <a class="btn btn-outlined" href="search-rooms.php"><?= icon('search') ?> Search rooms</a>
    <a class="btn" href="apply.php"><?= icon('add') ?> Apply</a>
  </div>
</div>

<div class="stack">
<?php foreach ($hostels as $i => $h):
    $full  = db_one($conn, 'SELECT * FROM hostels WHERE id = ?', 'i', [(int)$h['id']]);
    $vacant = (int)$h['vacant_beds'];
    $rate   = (float)$h['occupancy_rate'];
?>
  <article class="card card-pad-lg anim-rise anim-rise-<?= ($i % 4) + 1 ?>" style="padding:0;overflow:hidden">
    <div style="display:grid;grid-template-columns:220px minmax(0,1fr);gap:0" class="hostel-row">
      
      <div class="hostel-visual" style="height:100%;min-height:220px;border-radius:0">
        <span class="glow" aria-hidden="true"></span>
        <?= icon('apartment', 'icon icon-xl') ?>
        <span class="chip chip-primary hostel-code"><?= e($h['block_code']) ?></span>
        <span class="chip <?= $vacant > 0 ? 'chip-success' : 'chip-danger' ?>" style="position:absolute;bottom:14px;left:14px">
          <span class="chip-dot"></span><?= $vacant > 0 ? $vacant . ' beds vacant' : 'Full' ?>
        </span>
      </div>

      
      <div style="padding:26px 28px">
        <div class="row-between wrap mb-1">
          <div>
            <h3 class="title-l mb-0"><?= e($h['name']) ?></h3>
            <p class="tiny mb-0">
              <?= icon('home', 'icon icon-xs') ?> <?= e($full['location']) ?>
              &nbsp;&middot;&nbsp; <?= icon('people', 'icon icon-xs') ?> <?= e(ucfirst($h['gender_policy'])) ?> residence
            </p>
          </div>
          <a class="btn btn-tonal btn-sm" href="hostel-detail.php?id=<?= (int)$h['id'] ?>">View details <?= icon('arrow_back', 'icon icon-sm') ?></a>
        </div>

        <p class="body-m muted mb-2" style="font-size:.9375rem"><?= e($full['description']) ?></p>

        <div class="row wrap mb-2" style="gap:6px"><?= facility_chips($full['facilities']) ?></div>

        
        <div class="table-wrap mb-2">
          <table class="data" style="min-width:520px">
            <thead>
              <tr><th>Room type</th><th class="num">Capacity</th><th class="num">Price / semester</th><th class="num">Vacancy</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($types as $t):
                $v = vacancy_for((int)$h['id'], (int)$t['id']);
            ?>
              <tr>
                <td class="cell-strong"><?= e($t['name']) ?></td>
                <td class="num"><?= (int)$t['capacity'] ?> bed<?= (int)$t['capacity'] === 1 ? '' : 's' ?></td>
                <td class="num cell-strong"><?= money($t['price']) ?></td>
                <td class="num">
                  <span class="chip <?= $v > 0 ? 'chip-success' : 'chip-neutral' ?>"><?= $v > 0 ? $v . ' free' : 'None' ?></span>
                </td>
                <td style="text-align:right">
                  <a class="btn btn-sm <?= $v > 0 ? '' : 'btn-outlined' ?>" <?= $v > 0 ? '' : 'disabled aria-disabled="true"' ?>
                     href="apply.php?hostel=<?= (int)$h['id'] ?>&type=<?= (int)$t['id'] ?>">Apply</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="row-between wrap" style="gap:12px">
          <div class="grow" style="min-width:200px">
            <div class="row-between mb-1">
              <span class="label-m"><?= (int)$h['occupied_beds'] ?> of <?= (int)$h['total_beds'] ?> beds occupied</span>
              <span class="tiny"><?= $rate ?>% occupancy &middot; <?= (int)$h['total_rooms'] ?> rooms</span>
            </div>
            <?= occupancy_bar((int)$h['occupied_beds'], (int)$h['total_beds']) ?>
          </div>
          <div class="tiny" style="text-align:right">
            Warden: <strong><?= e($full['warden_name'] ?: '—') ?></strong><br><?= e($full['warden_phone'] ?: '') ?>
          </div>
        </div>
      </div>
    </div>
  </article>
<?php endforeach; ?>

<?php if (!$hostels): ?>
  <div class="card"><div class="empty">
    <div class="empty-icon"><?= icon('apartment') ?></div>
    <p class="body-m mb-0">No hostel blocks are published yet</p>
    <p class="tiny">Please check back later or contact the hostel office.</p>
  </div></div>
<?php endif; ?>
</div>

<style>
@media (max-width: 820px) { .hostel-row { grid-template-columns: 1fr !important; } }
</style>

<?php page_footer(); ?>
