<?php
/**
 * student/search-rooms.php - Search & filter rooms by hostel block, room type,
 *                            price, capacity or availability.
 */
require_once __DIR__ . '/../includes/header.php';
$me = require_student();

// ---- Filter inputs (all optional) -----------------------------------------
$q         = get_str('q');
$hostelId  = get_int('hostel');
$typeId    = get_int('type');
$minPrice  = get_int('min_price');
$maxPrice  = get_int('max_price');
$capacity  = get_int('capacity');
$avail     = get_str('avail');                       // ''|vacant|full|maintenance
$floor     = get_int('floor');
$sortBy    = safe_choice(get_str('sort'), ['price_asc', 'price_desc', 'capacity_asc', 'vacancy_desc', 'room_asc'], 'vacancy_desc');
$perPage   = 12;
$page      = max(1, get_int('page', 1));

// ---- Build the WHERE clause with bound parameters -------------------------
$where  = ['1=1'];
$types  = '';
$params = [];

if ($q !== '') {
    $where[] = '(r.room_number LIKE ? OR h.name LIKE ? OR h.block_code LIKE ? OR rt.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($hostelId > 0)  { $where[] = 'r.hostel_id = ?';    $params[] = $hostelId;  $types .= 'i'; }
if ($typeId > 0)    { $where[] = 'r.room_type_id = ?'; $params[] = $typeId;    $types .= 'i'; }
if ($minPrice > 0)  { $where[] = 'rt.price >= ?';      $params[] = $minPrice;  $types .= 'd'; }
if ($maxPrice > 0)  { $where[] = 'rt.price <= ?';      $params[] = $maxPrice;  $types .= 'd'; }
if ($capacity > 0)  { $where[] = 'rt.capacity = ?';    $params[] = $capacity;  $types .= 'i'; }
if ($floor > 0)     { $where[] = 'r.floor = ?';        $params[] = $floor;     $types .= 'i'; }
if ($avail === 'vacant')      { $where[] = "r.status = 'available' AND r.occupied < rt.capacity"; }
elseif ($avail === 'full')    { $where[] = "(r.status = 'full' OR r.occupied >= rt.capacity)"; }
elseif ($avail === 'maintenance') { $where[] = "r.status = 'maintenance'"; }

$whereSql = implode(' AND ', $where);

$orderSql = match ($sortBy) {
    'price_asc'    => 'rt.price ASC, r.room_number ASC',
    'price_desc'   => 'rt.price DESC, r.room_number ASC',
    'capacity_asc' => 'rt.capacity ASC, rt.price ASC',
    'room_asc'     => 'h.block_code ASC, r.floor ASC, LENGTH(r.room_number) ASC, r.room_number ASC',
    default        => '(rt.capacity - r.occupied) DESC, rt.price ASC',
};

$baseSql = "FROM rooms r
            JOIN hostels h     ON h.id  = r.hostel_id
            JOIN room_types rt ON rt.id = r.room_type_id
            WHERE h.is_active = 1 AND $whereSql";

$total = (int)(db_one($conn, "SELECT COUNT(*) c $baseSql", $types, $params)['c'] ?? 0);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = db_all($conn, "SELECT r.*, h.name hostel_name, h.block_code, h.location, h.gender_policy,
                              rt.name type_name, rt.capacity, rt.price, rt.facilities type_facilities,
                              (rt.capacity - r.occupied) AS vacancy
                       $baseSql
                       ORDER BY $orderSql
                       LIMIT $perPage OFFSET $offset", $types, $params);

$hostels = hostel_options();
$types_  = room_type_options();
$floors  = db_all($conn, 'SELECT DISTINCT floor FROM rooms ORDER BY floor');
$maxP    = (int)(db_one($conn, 'SELECT COALESCE(MAX(price),0) p FROM room_types')['p'] ?? 0);

// Preserve the query string for pagination + reset links.
$queryBase = http_build_query(array_filter([
    'q' => $q, 'hostel' => $hostelId ?: '', 'type' => $typeId ?: '', 'min_price' => $minPrice ?: '',
    'max_price' => $maxPrice ?: '', 'capacity' => $capacity ?: '', 'avail' => $avail, 'floor' => $floor ?: '', 'sort' => $sortBy,
], fn($v) => $v !== '' && $v !== null));

$activeFilters = array_filter([
    $q !== ''            ? 'Search: "' . $q . '"' : '',
    $hostelId            ? 'Block: ' . (db_one($conn, 'SELECT block_code FROM hostels WHERE id=?', 'i', [$hostelId])['block_code'] ?? '') : '',
    $typeId              ? 'Type: ' . (db_one($conn, 'SELECT name FROM room_types WHERE id=?', 'i', [$typeId])['name'] ?? '') : '',
    $minPrice || $maxPrice ? 'Price: ' . ($minPrice ?: 0) . ' – ' . ($maxPrice ?: $maxP) : '',
    $capacity            ? 'Capacity: ' . $capacity . ' beds' : '',
    $avail               ? 'Availability: ' . ucfirst($avail) : '',
    $floor               ? 'Floor: ' . $floor : '',
]);

page_header('Find a Room', 'search-rooms', $total . ' room' . ($total === 1 ? '' : 's') . ' match your filters');
?>

<!-- Search hero -->
<section class="hero mb-3" style="padding:32px 34px">
  <span class="blur-shape blur-a" aria-hidden="true"></span>
  <span class="blur-shape blur-b" aria-hidden="true"></span>
  <div style="position:relative;z-index:1">
    <h2 class="title-l mb-1">Search hostel rooms</h2>
    <p class="tiny mb-2">Filter by hostel block, room type, price range, capacity or availability. Results update instantly.</p>

    <form method="get" action="search-rooms.php" class="stack" style="gap:16px">
      <div class="search-bar">
        <?= icon('search') ?>
        <input type="text" name="q" value="<?= e($q) ?>" data-live-search
               placeholder="Search by room number, block code, hostel or room type&hellip;" aria-label="Search rooms">
        <button type="submit" class="btn">Search</button>
      </div>

      <div class="filter-bar">
        <div class="field">
          <label class="field-label" for="hostel">Hostel block</label>
          <select class="select" id="hostel" name="hostel">
            <option value="">All blocks</option>
            <?php foreach ($hostels as $h): ?>
              <option value="<?= (int)$h['id'] ?>" <?= $hostelId === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['block_code']) ?> — <?= e($h['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="type">Room type</label>
          <select class="select" id="type" name="type">
            <option value="">Any type</option>
            <?php foreach ($types_ as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= $typeId === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="capacity">Capacity</label>
          <select class="select" id="capacity" name="capacity">
            <option value="">Any capacity</option>
            <?php for ($c = 1; $c <= 4; $c++): ?>
              <option value="<?= $c ?>" <?= $capacity === $c ? 'selected' : '' ?>><?= $c ?> bed<?= $c === 1 ? '' : 's' ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="min_price">Min price</label>
          <input class="input" type="number" id="min_price" name="min_price" min="0" step="500" value="<?= $minPrice ?: '' ?>" placeholder="0">
        </div>

        <div class="field">
          <label class="field-label" for="max_price">Max price</label>
          <input class="input" type="number" id="max_price" name="max_price" min="0" step="500" value="<?= $maxPrice ?: '' ?>" placeholder="<?= $maxP ?>">
        </div>

        <div class="field">
          <label class="field-label" for="floor">Floor</label>
          <select class="select" id="floor" name="floor">
            <option value="">Any floor</option>
            <?php foreach ($floors as $f): ?>
              <option value="<?= (int)$f['floor'] ?>" <?= $floor === (int)$f['floor'] ? 'selected' : '' ?>>Floor <?= (int)$f['floor'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="avail">Availability</label>
          <select class="select" id="avail" name="avail">
            <option value="">Any status</option>
            <?php foreach (['vacant' => 'Vacant only', 'full' => 'Fully occupied', 'maintenance' => 'Under maintenance'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $avail === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="field">
          <label class="field-label" for="sort">Sort by</label>
          <select class="select" id="sort" name="sort">
            <?php foreach (['vacancy_desc' => 'Most vacant first', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'capacity_asc' => 'Capacity: small to large', 'room_asc' => 'Block / room number'] as $v => $l): ?>
              <option value="<?= $v ?>" <?= $sortBy === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row wrap" style="gap:8px">
        <button type="submit" class="btn"><?= icon('filter') ?> Apply filters</button>
        <a class="btn btn-outlined" href="search-rooms.php"><?= icon('close') ?> Clear all</a>
        <span class="grow"></span>
        <span class="tiny"><?= $total ?> result<?= $total === 1 ? '' : 's' ?> &middot; page <?= $page ?> of <?= $pages ?></span>
      </div>
    </form>

    <?php if ($activeFilters): ?>
      <div class="row wrap mt-2" style="gap:6px">
        <span class="tiny">Active:</span>
        <?php foreach ($activeFilters as $f): ?>
          <span class="chip chip-primary"><?= e($f) ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Results -->
<?php if (!$rows): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= icon('search') ?></div>
      <h3 class="title-m mb-0">No rooms match your filters</h3>
      <p class="tiny mb-2">Try widening the price range, choosing another block, or clearing the availability filter.</p>
      <a class="btn btn-tonal" href="search-rooms.php"><?= icon('close') ?> Clear all filters</a>
    </div>
  </div>
<?php else: ?>
  <div class="grid g-3">
    <?php foreach ($rows as $i => $r):
        $cap   = (int)$r['capacity'];
        $free  = (int)$r['vacancy'];
        $vacant = $r['status'] === 'available' && $free > 0;
    ?>
      <article class="card card-hover hostel-card group anim-rise anim-rise-<?= ($i % 4) + 1 ?>">
        <div class="hostel-visual" style="height:104px">
          <span class="glow" aria-hidden="true"></span>
          <?= icon('bed', 'icon icon-lg') ?>
          <span class="chip chip-primary hostel-code"><?= e($r['block_code']) ?></span>
          <span class="chip <?= $vacant ? 'chip-success' : ($r['status'] === 'maintenance' ? 'chip-warning' : 'chip-neutral') ?>"
                style="position:absolute;top:14px;right:14px">
            <span class="chip-dot"></span><?= $vacant ? $free . ' free' : ($r['status'] === 'maintenance' ? 'Maintenance' : 'Full') ?>
          </span>
        </div>
        <div class="hostel-body">
          <div>
            <h3 class="title-m mb-0">Room <?= e($r['room_number']) ?> &middot; <?= e($r['type_name']) ?></h3>
            <p class="tiny mb-0"><?= e($r['hostel_name']) ?> &middot; Floor <?= (int)$r['floor'] ?> &middot; <?= e(ucfirst($r['gender_policy'])) ?></p>
          </div>

          <div class="row-between">
            <div class="beds" title="<?= (int)$r['occupied'] ?> of <?= $cap ?> beds taken">
              <?php for ($b = 1; $b <= $cap; $b++): ?>
                <span class="bed<?= $b <= (int)$r['occupied'] ? ' taken' : '' ?>"></span>
              <?php endfor; ?>
            </div>
            <span class="title-m mb-0" style="color:var(--md-primary)"><?= money($r['price']) ?></span>
          </div>
          <div class="tiny" style="margin-top:-6px">per semester &middot; <?= $cap ?> bed<?= $cap === 1 ? '' : 's' ?></div>

          <?= occupancy_bar((int)$r['occupied'], $cap) ?>

          <div class="row wrap" style="gap:5px">
            <?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string)$r['type_facilities']))), 0, 3) as $f): ?>
              <span class="chip chip-outline"><?= icon_for_facility($f) ?><?= e($f) ?></span>
            <?php endforeach; ?>
          </div>

          <div class="row" style="gap:8px;margin-top:auto">
            <a class="btn btn-sm grow" <?= $vacant ? '' : 'disabled aria-disabled="true"' ?>
               href="apply.php?hostel=<?= (int)$r['hostel_id'] ?>&type=<?= (int)$r['room_type_id'] ?>&room=<?= (int)$r['id'] ?>">
              <?= $vacant ? 'Apply' : 'Unavailable' ?>
            </a>
            <a class="btn btn-sm btn-text" href="hostel-detail.php?id=<?= (int)$r['hostel_id'] ?>">Block</a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
    <nav class="row mt-3" style="justify-content:center;gap:6px" aria-label="Pagination">
      <?php if ($page > 1): ?>
        <a class="btn btn-outlined btn-sm" href="?<?= e($queryBase) ?>&page=<?= $page - 1 ?>"><?= icon('arrow_back', 'icon icon-sm') ?> Previous</a>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $pages; $p++):
          if ($pages > 7 && abs($p - $page) > 2 && $p !== 1 && $p !== $pages) {
              if ($p === 2 || $p === $pages - 1) echo '<span class="tiny">&hellip;</span>';
              continue;
          } ?>
        <a class="btn btn-sm <?= $p === $page ? '' : 'btn-text' ?>" href="?<?= e($queryBase) ?>&page=<?= $p ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a class="btn btn-outlined btn-sm" href="?<?= e($queryBase) ?>&page=<?= $page + 1 ?>">Next</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php page_footer(); ?>
