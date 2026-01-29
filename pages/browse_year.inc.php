<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:030
 * @File name           : browse_year.inc.php
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

declare(strict_types=1);

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
  die('can not access this file directly');
}

use SLiMS\DB;

header("Content-Type: text/html; charset=UTF-8");

do_checkIP('opac');
do_checkIP('opac-member');

$db = DB::getInstance();

/**
 * ---------------------------------------------------------
 * Cache helper (file cache di /files/cache/browse_year)
 * ---------------------------------------------------------
 */
function by_root_dir(): string {
  if (defined('SB')) return rtrim((string)SB, "/\\") . DIRECTORY_SEPARATOR;
  $fallback = realpath(__DIR__ . '/../../../');
  return rtrim((string)$fallback, "/\\") . DIRECTORY_SEPARATOR;
}

function by_cache_dir(): string {
  $dir = by_root_dir() . 'files' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'browse_year' . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function by_cache_key(string $name, array $params = []): string {
  return $name . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function by_cache_get(string $key, int $ttlSeconds): mixed {
  $file = by_cache_dir() . $key . '.cache.php';
  if (!is_file($file)) return null;
  $mtime = filemtime($file);
  if (!$mtime || (time() - $mtime) > $ttlSeconds) return null;
  $data = include $file;
  return $data ?? null;
}

function by_cache_set(string $key, mixed $value): void {
  $file = by_cache_dir() . $key . '.cache.php';
  $export = var_export($value, true);
  @file_put_contents($file, "<?php\nreturn {$export};\n", LOCK_EX);
}

/**
 * ---------------------------------------------------------
 * Util
 * ---------------------------------------------------------
 */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function by_url(array $qs = []): string {
  $base = defined('SWB') ? SWB : './';
  return $base . 'index.php?' . http_build_query($qs);
}

function by_valid_year(mixed $y): int {
  $y = (int)$y;
  if ($y < 1000 || $y > 3000) return 0;
  return $y;
}

/**
 * Format: (Year). Author. GMD. Title. Place : Publisher.
 * - Title akan dijadikan link show_detail
 */
function by_format_citation(array $r): string {
  $year   = $r['publish_year'] ?? '';
  $author = $r['author_name'] ?? '';
  $gmd    = $r['gmd_name'] ?? '';
  $title  = $r['title'] ?? '';
  $place  = $r['place_name'] ?? '';
  $pub    = $r['publisher_name'] ?? '';

  $parts = [];
  if ($year !== '') $parts[] = '(' . $year . ').';
  $parts[] = rtrim((string)$author, '.') . '.';
  if ($gmd !== '') $parts[] = $gmd . '.';

  $parts[] = '<em>' . h((string)$title) . '</em>.';

  $pubLine = trim((string)$place);
  if ($pubLine !== '' && $pub !== '') $pubLine .= ' : ' . $pub;
  else if ($pubLine === '' && $pub !== '') $pubLine = $pub;

  if ($pubLine !== '') $parts[] = h($pubLine) . '.';

  return implode(' ', $parts);
}

/**
 * ---------------------------------------------------------
 * Config & Params
 * ---------------------------------------------------------
 */
$ttl = 6 * 60 * 60; // 6 jam cache

$year = by_valid_year($_GET['year'] ?? 0);

// paging
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage < 10) $perPage = 20;
if ($perPage > 100) $perPage = 100;
$offset = ($page - 1) * $perPage;

/**
 * ---------------------------------------------------------
 * Data: minYear & maxYear (cache)
 * ---------------------------------------------------------
 */
$rangeKey = by_cache_key('year_range', []);
$range = by_cache_get($rangeKey, $ttl);

if (!is_array($range) || !isset($range['min'], $range['max'])) {
  // hanya publish_year 4 digit
  $stmt = $db->query("
    SELECT
      MIN(CAST(b.publish_year AS UNSIGNED)) AS min_year,
      MAX(CAST(b.publish_year AS UNSIGNED)) AS max_year
    FROM biblio b
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
  ");
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $minY = (int)($row['min_year'] ?? 0);
  $maxY = (int)($row['max_year'] ?? 0);

  $range = ['min' => $minY, 'max' => $maxY];
  by_cache_set($rangeKey, $range);
}

$minYear = (int)($range['min'] ?? 0);
$maxYear = (int)($range['max'] ?? 0);

/**
 * ---------------------------------------------------------
 * Data: daftar tahun + jumlah item (cache)
 * ---------------------------------------------------------
 */
$yearsKey = by_cache_key('years_list', []);
$years = by_cache_get($yearsKey, $ttl);

if (!is_array($years)) {
  $stmt = $db->query("
    SELECT b.publish_year AS y, COUNT(DISTINCT b.biblio_id) AS total
    FROM biblio b
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
    GROUP BY b.publish_year
    ORDER BY b.publish_year DESC
  ");
  $years = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  by_cache_set($yearsKey, $years);
}

/**
 * ---------------------------------------------------------
 * Data: items by year (cache)
 * ---------------------------------------------------------
 */
$items = [];
$totalItems = 0;

if ($year > 0) {
  // total count (cache)
  $countKey = by_cache_key('items_count_by_year', ['year' => $year]);
  $totalItems = by_cache_get($countKey, $ttl);

  if (!is_int($totalItems)) {
    $stmtCount = $db->prepare("
      SELECT COUNT(DISTINCT b.biblio_id) AS cnt
      FROM biblio b
      WHERE b.publish_year = :y
    ");
    $stmtCount->bindValue(':y', (string)$year, PDO::PARAM_STR);
    $stmtCount->execute();
    $totalItems = (int)($stmtCount->fetchColumn() ?: 0);
    by_cache_set($countKey, $totalItems);
  }

  // items page (cache)
  $itemsKey = by_cache_key('items_by_year', ['year'=>$year,'offset'=>$offset,'per_page'=>$perPage]);
  $items = by_cache_get($itemsKey, $ttl);

  if (!is_array($items)) {
    /**
     * Query
     */
    $stmtItems = $db->prepare("
      SELECT
        b.biblio_id,
        b.title,
        b.publish_year,
        g.gmd_name,
        p.publisher_name,
        pl.place_name,
        GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR '; ') AS author_name
      FROM biblio b
      LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
      LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
      LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
      LEFT JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
      LEFT JOIN mst_author a ON a.author_id = ba.author_id
      WHERE b.publish_year = :y
      GROUP BY b.biblio_id
      ORDER BY b.title ASC
      LIMIT :limit OFFSET :offset
    ");
    $stmtItems->bindValue(':y', (string)$year, PDO::PARAM_STR);
    $stmtItems->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();

    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    by_cache_set($itemsKey, $items);
  }
}

/**
 * ---------------------------------------------------------
 * UI
 * ---------------------------------------------------------
 */
?>
<style>
  .by-wrap{max-width:1100px;margin:0 auto;padding:16px}
  .by-card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;margin-bottom:14px}
  .by-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .by-title{font-size:20px;font-weight:700;margin:0}
  .by-sub{color:#6b7280;margin:2px 0 0;font-size:13px}
  .by-years{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:12px}
  @media (max-width: 980px){.by-years{grid-template-columns:repeat(4,minmax(0,1fr));}}
  @media (max-width: 640px){.by-years{grid-template-columns:repeat(2,minmax(0,1fr));}}
  .by-year{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:12px;
    border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .by-year:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .by-year.active{border-color:rgba(0,0,0,.35);background:rgba(0,0,0,.03)}
  .by-badge{font-size:12px;padding:3px 8px;border-radius:999px;background:rgba(0,0,0,.06)}
  .by-list{margin:0;padding-left:18px}
  .by-list li{margin:8px 0;line-height:1.5}
  .by-list a{font-weight:700;text-decoration:none}
  .by-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}
  .by-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid rgba(0,0,0,.10);
    text-decoration:none}
</style>

<div class="by-wrap">

  <div class="by-card">
    <?php include __DIR__ . '/_browse_nav.php'; ?>
    <div class="by-head">
      <div>
        <h2 class="by-title">Browse by Year</h2>
        <div class="by-sub">Pilih tahun terbit untuk melihat daftar koleksi. (Range: <?php echo (int)$minYear; ?>–<?php echo (int)$maxYear; ?>)</div>
      </div>
      <div>
        <a class="by-btn" href="<?php echo h(by_url(['p'=>'browse_year'])); ?>">Reset</a>
      </div>
    </div>

    <?php if (!$years): ?>
      <p>Tidak ada data tahun terbit (publish_year 4 digit) ditemukan.</p>
    <?php else: ?>
      <div class="by-years">
        <?php foreach ($years as $yRow): ?>
          <?php
            $y = by_valid_year($yRow['y'] ?? 0);
            if ($y === 0) continue;
            $url = by_url(['p'=>'browse_year','year'=>$y]);
            $cls = ($year === $y) ? 'by-year active' : 'by-year';
          ?>
          <a class="<?php echo h($cls); ?>" href="<?php echo h($url); ?>">
            <strong><?php echo (int)$y; ?></strong>
            <span class="by-badge"><?php echo (int)$yRow['total']; ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($year > 0): ?>
    <div class="by-card">
      <div class="by-head">
        <div>
          <h3 class="by-title" style="font-size:18px;margin:0">Koleksi tahun <?php echo (int)$year; ?></h3>
          <div class="by-sub">Total <?php echo (int)$totalItems; ?> judul • Halaman <?php echo (int)$page; ?></div>
        </div>
      </div>

      <?php if (!$items): ?>
        <p>Tidak ada judul pada tahun ini.</p>
      <?php else: ?>
        <ol class="by-list">
          <?php foreach ($items as $r): ?>
            <li>
              <?php
                $detailUrl = by_url(['p'=>'show_detail','id'=>(int)$r['biblio_id']]);
                $citation = by_format_citation($r);

                // link-kan judul
                $linked = str_replace(
                  '<em>' . h($r['title']) . '</em>',
                  '<a href="'.h($detailUrl).'"><em>'.h($r['title']).'</em></a>',
                  $citation
                );
                echo $linked;
              ?>
            </li>
          <?php endforeach; ?>
        </ol>

        <?php
          $totalPages = (int)ceil($totalItems / $perPage);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <div class="by-pager">
          <div>
            <?php if ($page > 1): ?>
              <a class="by-btn" href="<?php echo h(by_url(['p'=>'browse_year','year'=>$year,'page'=>$prev,'per_page'=>$perPage])); ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="by-btn" href="<?php echo h(by_url(['p'=>'browse_year','year'=>$year,'page'=>$next,'per_page'=>$perPage])); ?>">Next →</a>
            <?php endif; ?>
          </div>

          <div class="by-sub">
            Per page:
            <?php foreach ([20,50,100] as $pp): ?>
              <a class="by-btn" href="<?php echo h(by_url(['p'=>'browse_year','year'=>$year,'page'=>1,'per_page'=>$pp])); ?>"><?php echo (int)$pp; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
