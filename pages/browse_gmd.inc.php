<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : browse_gmd.inc.php
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
 * File Cache Helper (files/cache/browse_gmd)
 * ---------------------------------------------------------
 */
function bg_root_dir(): string {
  if (defined('SB')) return rtrim((string)SB, "/\\") . DIRECTORY_SEPARATOR;
  $fallback = realpath(__DIR__ . '/../../../');
  return rtrim((string)$fallback, "/\\") . DIRECTORY_SEPARATOR;
}
function bg_cache_dir(): string {
  $dir = bg_root_dir() . 'files' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'browse_gmd' . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function bg_cache_key(string $name, array $params = []): string {
  return $name . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function bg_cache_get(string $key, int $ttlSeconds): mixed {
  $file = bg_cache_dir() . $key . '.cache.php';
  if (!is_file($file)) return null;
  $mtime = filemtime($file);
  if (!$mtime || (time() - $mtime) > $ttlSeconds) return null;
  $data = include $file;
  return $data ?? null;
}
function bg_cache_set(string $key, mixed $value): void {
  $file = bg_cache_dir() . $key . '.cache.php';
  $export = var_export($value, true);
  @file_put_contents($file, "<?php\nreturn {$export};\n", LOCK_EX);
}

/**
 * ---------------------------------------------------------
 * Utils
 * ---------------------------------------------------------
 */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bg_url(array $qs = []): string {
  $base = defined('SWB') ? SWB : './';
  return $base . 'index.php?' . http_build_query($qs);
}
function bg_letter(mixed $l): string {
  $l = strtoupper(trim((string)$l));
  return preg_match('/^[A-Z]$/', $l) ? $l : '';
}
function bg_int(mixed $v): int { return max(0, (int)$v); }

function bg_format_line(string $gmdName, array $r): string {
  $author = (string)($r['author_name'] ?? '');
  $year   = (string)($r['publish_year'] ?? '');
  $title  = (string)($r['title'] ?? '');
  $place  = (string)($r['place_name'] ?? '');
  $pub    = (string)($r['publisher_name'] ?? '');

  $parts = [];

  // GMD di depan
  $parts[] = rtrim($gmdName, '.') . '.';

  // Author
  if ($author !== '') $parts[] = rtrim($author, '.') . '.';

  // (Year)
  if ($year !== '') $parts[] = '(' . $year . ').';

  // Title (nanti di-link)
  $parts[] = '<em>' . h($title) . '</em>.';

  // Place : Publisher
  $pubLine = trim($place);
  if ($pubLine !== '' && $pub !== '') $pubLine .= ' : ' . $pub;
  else if ($pubLine === '' && $pub !== '') $pubLine = $pub;

  if ($pubLine !== '') $parts[] = h($pubLine) . '.';

  return implode(' ', $parts);
}

/**
 * ---------------------------------------------------------
 * Params
 * ---------------------------------------------------------
 */
$ttl = 6 * 60 * 60; // 6 jam cache

$letter = bg_letter($_GET['letter'] ?? '');
$gid = bg_int($_GET['gid'] ?? 0);

// paging list judul per gmd
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage < 10) $perPage = 20;
if ($perPage > 100) $perPage = 100;
$offset = ($page - 1) * $perPage;

/**
 * ---------------------------------------------------------
 * Data: counts per letter (cache)
 * ---------------------------------------------------------
 */
$letterCountsKey = bg_cache_key('letter_counts', []);
$letterCounts = bg_cache_get($letterCountsKey, $ttl);

if (!is_array($letterCounts)) {
  $stmt = $db->query("
    SELECT UPPER(LEFT(g.gmd_name, 1)) AS letter, COUNT(*) AS total
    FROM mst_gmd g
    WHERE g.gmd_name IS NOT NULL AND g.gmd_name <> ''
    GROUP BY UPPER(LEFT(g.gmd_name, 1))
  ");
  $letterCounts = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $L = strtoupper((string)($r['letter'] ?? ''));
    if (preg_match('/^[A-Z]$/', $L)) $letterCounts[$L] = (int)($r['total'] ?? 0);
  }
  bg_cache_set($letterCountsKey, $letterCounts);
}

/**
 * ---------------------------------------------------------
 * Data: gmd list by letter (cache)
 * daftar gmd + jumlah judul (biblio.gmd_id)
 * ---------------------------------------------------------
 */
$gmds = [];
if ($letter !== '') {
  $gmdsKey = bg_cache_key('gmds_by_letter', ['letter' => $letter]);
  $gmds = bg_cache_get($gmdsKey, $ttl);

  if (!is_array($gmds)) {
    $stmt = $db->prepare("
      SELECT
        g.gmd_id,
        g.gmd_name,
        COUNT(DISTINCT b.biblio_id) AS total_titles
      FROM mst_gmd g
      LEFT JOIN biblio b ON b.gmd_id = g.gmd_id
      WHERE UPPER(LEFT(g.gmd_name, 1)) = :letter
      GROUP BY g.gmd_id, g.gmd_name
      ORDER BY g.gmd_name ASC
    ");
    $stmt->bindValue(':letter', $letter, PDO::PARAM_STR);
    $stmt->execute();
    $gmds = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bg_cache_set($gmdsKey, $gmds);
  }
}

/**
 * ---------------------------------------------------------
 * Data: items by gmd (cache)
 * ---------------------------------------------------------
 */
$selectedGmd = null;
$items = [];
$totalItems = 0;

if ($gid > 0) {
  // gmd info (cache)
  $gmdInfoKey = bg_cache_key('gmd_info', ['gid'=>$gid]);
  $selectedGmd = bg_cache_get($gmdInfoKey, $ttl);

  if (!is_array($selectedGmd) || empty($selectedGmd['gmd_name'])) {
    $stmt = $db->prepare("SELECT gmd_id, gmd_name FROM mst_gmd WHERE gmd_id = :gid LIMIT 1");
    $stmt->bindValue(':gid', $gid, PDO::PARAM_INT);
    $stmt->execute();
    $selectedGmd = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    bg_cache_set($gmdInfoKey, $selectedGmd);
  }

  $gmdName = (string)($selectedGmd['gmd_name'] ?? '');

  // total count (cache)
  $countKey = bg_cache_key('items_count_by_gmd', ['gid'=>$gid]);
  $totalItems = bg_cache_get($countKey, $ttl);

  if (!is_int($totalItems)) {
    $stmtCount = $db->prepare("
      SELECT COUNT(*) AS cnt
      FROM biblio b
      WHERE b.gmd_id = :gid
    ");
    $stmtCount->bindValue(':gid', $gid, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalItems = (int)($stmtCount->fetchColumn() ?: 0);
    bg_cache_set($countKey, $totalItems);
  }

  // items page (cache)
  $itemsKey = bg_cache_key('items_by_gmd', ['gid'=>$gid,'offset'=>$offset,'per_page'=>$perPage]);
  $items = bg_cache_get($itemsKey, $ttl);

  if (!is_array($items)) {
    $stmtItems = $db->prepare("
      SELECT
        b.biblio_id,
        b.title,
        b.publish_year,
        p.publisher_name,
        pl.place_name,
        GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR '; ') AS author_name
      FROM biblio b
      LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
      LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
      LEFT JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
      LEFT JOIN mst_author a ON a.author_id = ba.author_id
      WHERE b.gmd_id = :gid
      GROUP BY b.biblio_id
      ORDER BY b.title ASC
      LIMIT :limit OFFSET :offset
    ");
    $stmtItems->bindValue(':gid', $gid, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bg_cache_set($itemsKey, $items);
  }
}

/**
 * ---------------------------------------------------------
 * UI
 * ---------------------------------------------------------
 */
?>
<style>
  .bg-wrap{max-width:1100px;margin:0 auto;padding:16px}
  .bg-card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;margin-bottom:14px}
  .bg-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .bg-title{font-size:20px;font-weight:700;margin:0}
  .bg-sub{color:#6b7280;margin:2px 0 0;font-size:13px}

  .bg-az{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .bg-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;
    border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bg-chip:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bg-chip.active{border-color:rgba(0,0,0,.35);background:rgba(0,0,0,.03)}
  .bg-chip.off{opacity:.45;pointer-events:none}
  .bg-badge{font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(0,0,0,.06)}

  .bg-gmds{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
  @media (max-width: 900px){.bg-gmds{grid-template-columns:repeat(2,minmax(0,1fr));}}
  @media (max-width: 640px){.bg-gmds{grid-template-columns:repeat(1,minmax(0,1fr));}}

  .bg-gmd{display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bg-gmd:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bg-gmd strong{font-size:14px}
  .bg-gmd .bg-badge{white-space:nowrap}

  .bg-list{margin:0;padding-left:18px}
  .bg-list li{margin:8px 0;line-height:1.5}
  .bg-list a{font-weight:700;text-decoration:none}
  .bg-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid rgba(0,0,0,.10);
    text-decoration:none}
  .bg-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}
</style>

<div class="bg-wrap">

  <div class="bg-card">
    <?php include __DIR__ . '/_browse_nav.php'; ?>
    <div class="bg-head">
      <div>
        <h2 class="bg-title">Browse by GMD</h2>
        <div class="bg-sub">Pilih huruf A–Z untuk menampilkan daftar GMD, lalu klik GMD untuk melihat koleksi.</div>
      </div>
      <div>
        <a class="bg-btn" href="<?php echo h(bg_url(['p'=>'browse_gmd'])); ?>">Reset</a>
      </div>
    </div>

    <div class="bg-az">
      <?php foreach (range('A','Z') as $L): ?>
        <?php
          $cnt = (int)($letterCounts[$L] ?? 0);
          $cls = 'bg-chip';
          if ($letter === $L) $cls .= ' active';
          if ($cnt === 0) $cls .= ' off';
          $href = bg_url(['p'=>'browse_gmd','letter'=>$L]);
        ?>
        <a class="<?php echo h($cls); ?>" href="<?php echo h($href); ?>">
          <strong><?php echo h($L); ?></strong>
          <span class="bg-badge"><?php echo $cnt; ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($letter !== ''): ?>
      <div style="margin-top:12px" class="bg-sub">GMD diawali huruf <strong><?php echo h($letter); ?></strong>:</div>

      <?php if (!$gmds): ?>
        <p>Tidak ada GMD pada huruf ini.</p>
      <?php else: ?>
        <div class="bg-gmds">
          <?php foreach ($gmds as $g): ?>
            <?php
              $gmdId = (int)($g['gmd_id'] ?? 0);
              $gmdNm = (string)($g['gmd_name'] ?? '');
              $totalT  = (int)($g['total_titles'] ?? 0);
              $href = bg_url(['p'=>'browse_gmd','letter'=>$letter,'gid'=>$gmdId,'page'=>1]);
            ?>
            <a class="bg-gmd" href="<?php echo h($href); ?>">
              <strong><?php echo h($gmdNm); ?></strong>
              <span class="bg-badge"><?php echo $totalT; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($gid > 0 && is_array($selectedGmd) && !empty($selectedGmd['gmd_name'])): ?>
    <?php $gmdName = (string)$selectedGmd['gmd_name']; ?>
    <div class="bg-card">
      <div class="bg-head">
        <div>
          <h3 class="bg-title" style="font-size:18px;margin:0"><?php echo h($gmdName); ?></h3>
          <div class="bg-sub">Total <?php echo (int)$totalItems; ?> judul • Halaman <?php echo (int)$page; ?></div>
        </div>
        <?php if ($letter !== ''): ?>
          <div>
            <a class="bg-btn" href="<?php echo h(bg_url(['p'=>'browse_gmd','letter'=>$letter])); ?>">← Kembali ke daftar GMD</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$items): ?>
        <p>Tidak ada judul untuk GMD ini.</p>
      <?php else: ?>
        <ol class="bg-list">
          <?php foreach ($items as $r): ?>
            <li>
              <?php
                $detailUrl = bg_url(['p'=>'show_detail','id'=>(int)$r['biblio_id']]);
                $line = bg_format_line($gmdName, $r);

                $line = str_replace(
                  '<em>' . h((string)$r['title']) . '</em>',
                  '<a href="'.h($detailUrl).'"><em>'.h((string)$r['title']).'</em></a>',
                  $line
                );

                echo $line;
              ?>
            </li>
          <?php endforeach; ?>
        </ol>

        <?php
          $totalPages = (int)ceil($totalItems / $perPage);
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
        <div class="bg-pager">
          <div>
            <?php if ($page > 1): ?>
              <a class="bg-btn" href="<?php echo h(bg_url(['p'=>'browse_gmd','letter'=>$letter,'gid'=>$gid,'page'=>$prev,'per_page'=>$perPage])); ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="bg-btn" href="<?php echo h(bg_url(['p'=>'browse_gmd','letter'=>$letter,'gid'=>$gid,'page'=>$next,'per_page'=>$perPage])); ?>">Next →</a>
            <?php endif; ?>
          </div>
          <div class="bg-sub">
            Per page:
            <?php foreach ([20,50,100] as $pp): ?>
              <a class="bg-btn" href="<?php echo h(bg_url(['p'=>'browse_gmd','letter'=>$letter,'gid'=>$gid,'page'=>1,'per_page'=>$pp])); ?>"><?php echo (int)$pp; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
