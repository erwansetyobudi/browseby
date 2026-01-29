<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : browse_coll_type.inc.php
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
 * File Cache (files/cache/browse_coll_type)
 * ---------------------------------------------------------
 */
function bct_root_dir(): string {
  if (defined('SB')) return rtrim((string)SB, "/\\") . DIRECTORY_SEPARATOR;
  $fallback = realpath(__DIR__ . '/../../../');
  return rtrim((string)$fallback, "/\\") . DIRECTORY_SEPARATOR;
}
function bct_cache_dir(): string {
  $dir = bct_root_dir() . 'files' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'browse_coll_type' . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function bct_cache_key(string $name, array $params = []): string {
  return $name . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function bct_cache_get(string $key, int $ttlSeconds): mixed {
  $file = bct_cache_dir() . $key . '.cache.php';
  if (!is_file($file)) return null;
  $mtime = filemtime($file);
  if (!$mtime || (time() - $mtime) > $ttlSeconds) return null;
  return include $file;
}
function bct_cache_set(string $key, mixed $value): void {
  $file = bct_cache_dir() . $key . '.cache.php';
  $export = var_export($value, true);
  @file_put_contents($file, "<?php\nreturn {$export};\n", LOCK_EX);
}

/**
 * ---------------------------------------------------------
 * Utils
 * ---------------------------------------------------------
 */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bct_url(array $qs = []): string {
  $base = defined('SWB') ? SWB : './';
  return $base . 'index.php?' . http_build_query($qs);
}
function bct_letter(mixed $l): string {
  $l = strtoupper(trim((string)$l));
  return preg_match('/^[A-Z]$/', $l) ? $l : '';
}
function bct_int(mixed $v): int { return max(0, (int)$v); }

/**
 * Format:
 * Nama Koleksi Tipe. Nama Pengarang. (Tahun). Nama GMD. Judul. Tempat : Penerbit. [Lokasi] [Status] [Eksemplar: n]
 */
function bct_format_line(string $collTypeName, array $r): string {
  $author = (string)($r['author_name'] ?? '');
  $year   = (string)($r['publish_year'] ?? '');
  $gmd    = (string)($r['gmd_name'] ?? '');
  $title  = (string)($r['title'] ?? '');
  $place  = (string)($r['place_name'] ?? '');
  $pub    = (string)($r['publisher_name'] ?? '');

  $loc    = (string)($r['location_name'] ?? '');
  $status = (string)($r['item_status_name'] ?? '');
  $copies = (int)($r['copies'] ?? 0);

  $parts = [];

  $parts[] = rtrim($collTypeName, '.') . '.';
  if ($author !== '') $parts[] = rtrim($author, '.') . '.';
  if ($year !== '')   $parts[] = '(' . $year . ').';
  if ($gmd !== '')    $parts[] = rtrim($gmd, '.') . '.';

  // Title (nanti di-link)
  $parts[] = '<em>' . h($title) . '</em>.';

  $pubLine = trim($place);
  if ($pubLine !== '' && $pub !== '') $pubLine .= ' : ' . $pub;
  else if ($pubLine === '' && $pub !== '') $pubLine = $pub;
  if ($pubLine !== '') $parts[] = h($pubLine) . '.';

  // info dari item (ringkas)
  $meta = [];
  if ($loc !== '') $meta[] = 'Lokasi: ' . $loc;
  if ($status !== '') $meta[] = 'Status: ' . $status;
  if ($copies > 0) $meta[] = 'Eksemplar: ' . $copies;
  if ($meta) $parts[] = '<span style="color:#6b7280;font-size:12px">[' . h(implode(' • ', $meta)) . ']</span>';

  return implode(' ', $parts);
}

/**
 * ---------------------------------------------------------
 * Params
 * ---------------------------------------------------------
 */
$ttl = 6 * 60 * 60; // 6 jam cache

$letter = bct_letter($_GET['letter'] ?? '');
$ctid   = bct_int($_GET['ctid'] ?? 0);

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
$letterCountsKey = bct_cache_key('letter_counts', []);
$letterCounts = bct_cache_get($letterCountsKey, $ttl);

if (!is_array($letterCounts)) {
  $stmt = $db->query("
    SELECT UPPER(LEFT(ct.coll_type_name, 1)) AS letter, COUNT(*) AS total
    FROM mst_coll_type ct
    WHERE ct.coll_type_name IS NOT NULL AND ct.coll_type_name <> ''
    GROUP BY UPPER(LEFT(ct.coll_type_name, 1))
  ");
  $letterCounts = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $L = strtoupper((string)($r['letter'] ?? ''));
    if (preg_match('/^[A-Z]$/', $L)) $letterCounts[$L] = (int)($r['total'] ?? 0);
  }
  bct_cache_set($letterCountsKey, $letterCounts);
}

/**
 * ---------------------------------------------------------
 * Data: coll types by letter (cache)
 * - coll_type + total judul (distinct biblio_id via item)
 * ---------------------------------------------------------
 */
$collTypes = [];
if ($letter !== '') {
  $ctKey = bct_cache_key('colltypes_by_letter', ['letter'=>$letter]);
  $collTypes = bct_cache_get($ctKey, $ttl);

  if (!is_array($collTypes)) {
    $stmt = $db->prepare("
      SELECT
        ct.coll_type_id,
        ct.coll_type_name,
        COUNT(DISTINCT b.biblio_id) AS total_titles
      FROM mst_coll_type ct
      LEFT JOIN item i ON i.coll_type_id = ct.coll_type_id
      LEFT JOIN biblio b ON b.biblio_id = i.biblio_id
      WHERE UPPER(LEFT(ct.coll_type_name, 1)) = :letter
      GROUP BY ct.coll_type_id, ct.coll_type_name
      ORDER BY ct.coll_type_name ASC
    ");
    $stmt->bindValue(':letter', $letter, PDO::PARAM_STR);
    $stmt->execute();
    $collTypes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bct_cache_set($ctKey, $collTypes);
  }
}

/**
 * ---------------------------------------------------------
 * Data: items by coll_type (cache)
 * ---------------------------------------------------------
 */
$selectedCT = null;
$items = [];
$totalItems = 0;

if ($ctid > 0) {
  $ctInfoKey = bct_cache_key('ct_info', ['ctid'=>$ctid]);
  $selectedCT = bct_cache_get($ctInfoKey, $ttl);

  if (!is_array($selectedCT) || empty($selectedCT['coll_type_name'])) {
    $stmt = $db->prepare("SELECT coll_type_id, coll_type_name FROM mst_coll_type WHERE coll_type_id = :ctid LIMIT 1");
    $stmt->bindValue(':ctid', $ctid, PDO::PARAM_INT);
    $stmt->execute();
    $selectedCT = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    bct_cache_set($ctInfoKey, $selectedCT);
  }

  $ctName = (string)($selectedCT['coll_type_name'] ?? '');

  // total judul (distinct biblio) untuk pager
  $countKey = bct_cache_key('items_count_by_ct', ['ctid'=>$ctid]);
  $totalItems = bct_cache_get($countKey, $ttl);

  if (!is_int($totalItems)) {
    $stmtCount = $db->prepare("
      SELECT COUNT(DISTINCT b.biblio_id) AS cnt
      FROM item i
      JOIN biblio b ON b.biblio_id = i.biblio_id
      WHERE i.coll_type_id = :ctid
    ");
    $stmtCount->bindValue(':ctid', $ctid, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalItems = (int)($stmtCount->fetchColumn() ?: 0);
    bct_cache_set($countKey, $totalItems);
  }

  // items per page
  $itemsKey = bct_cache_key('items_by_ct', ['ctid'=>$ctid,'offset'=>$offset,'per_page'=>$perPage]);
  $items = bct_cache_get($itemsKey, $ttl);

  if (!is_array($items)) {
    $stmtItems = $db->prepare("
      SELECT
        b.biblio_id,
        b.title,
        b.publish_year,
        g.gmd_name,
        p.publisher_name,
        pl.place_name,

        -- author gabung
        GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR '; ') AS author_name,

        -- info item (ringkas)
        ml.location_name,
        mis.item_status_name,
        COUNT(i.item_id) AS copies

      FROM item i
      JOIN biblio b ON b.biblio_id = i.biblio_id
      LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
      LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
      LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id

      LEFT JOIN mst_location ml ON ml.location_id = i.location_id
      LEFT JOIN mst_item_status mis ON mis.item_status_id = i.item_status_id

      LEFT JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
      LEFT JOIN mst_author a ON a.author_id = ba.author_id

      WHERE i.coll_type_id = :ctid
      GROUP BY b.biblio_id
      ORDER BY b.title ASC
      LIMIT :limit OFFSET :offset
    ");
    $stmtItems->bindValue(':ctid', $ctid, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bct_cache_set($itemsKey, $items);
  }
}

/**
 * ---------------------------------------------------------
 * UI
 * ---------------------------------------------------------
 */
?>
<style>
  .bct-wrap{max-width:1100px;margin:0 auto;padding:16px}
  .bct-card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;margin-bottom:14px}
  .bct-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .bct-title{font-size:20px;font-weight:700;margin:0}
  .bct-sub{color:#6b7280;margin:2px 0 0;font-size:13px}

  .bct-az{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .bct-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bct-chip:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bct-chip.active{border-color:rgba(0,0,0,.35);background:rgba(0,0,0,.03)}
  .bct-chip.off{opacity:.45;pointer-events:none}
  .bct-badge{font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(0,0,0,.06)}

  .bct-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
  @media (max-width: 900px){.bct-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
  @media (max-width: 640px){.bct-grid{grid-template-columns:repeat(1,minmax(0,1fr));}}

  .bct-item{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bct-item:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bct-item strong{font-size:14px}

  .bct-list{margin:0;padding-left:18px}
  .bct-list li{margin:8px 0;line-height:1.5}
  .bct-list a{font-weight:700;text-decoration:none}
  .bct-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bct-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}
</style>

<div class="bct-wrap">

  <div class="bct-card">
    <?php include __DIR__ . '/_browse_nav.php'; ?>
    <div class="bct-head">
      <div>
        <h2 class="bct-title">Browse by Koleksi Tipe</h2>
        <div class="bct-sub">Pilih huruf A–Z untuk menampilkan daftar koleksi tipe, lalu klik untuk melihat koleksi.</div>
      </div>
      <div>
        <a class="bct-btn" href="<?php echo h(bct_url(['p'=>'browse_coll_type'])); ?>">Reset</a>
      </div>
    </div>

    <div class="bct-az">
      <?php foreach (range('A','Z') as $L): ?>
        <?php
          $cnt = (int)($letterCounts[$L] ?? 0);
          $cls = 'bct-chip';
          if ($letter === $L) $cls .= ' active';
          if ($cnt === 0) $cls .= ' off';
          $href = bct_url(['p'=>'browse_coll_type','letter'=>$L]);
        ?>
        <a class="<?php echo h($cls); ?>" href="<?php echo h($href); ?>">
          <strong><?php echo h($L); ?></strong>
          <span class="bct-badge"><?php echo $cnt; ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($letter !== ''): ?>
      <div style="margin-top:12px" class="bct-sub">Koleksi tipe diawali huruf <strong><?php echo h($letter); ?></strong>:</div>

      <?php if (!$collTypes): ?>
        <p>Tidak ada koleksi tipe pada huruf ini.</p>
      <?php else: ?>
        <div class="bct-grid">
          <?php foreach ($collTypes as $c): ?>
            <?php
              $id = (int)($c['coll_type_id'] ?? 0);
              $nm = (string)($c['coll_type_name'] ?? '');
              $tt = (int)($c['total_titles'] ?? 0);
              $href = bct_url(['p'=>'browse_coll_type','letter'=>$letter,'ctid'=>$id,'page'=>1]);
            ?>
            <a class="bct-item" href="<?php echo h($href); ?>">
              <strong><?php echo h($nm); ?></strong>
              <span class="bct-badge"><?php echo $tt; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($ctid > 0 && is_array($selectedCT) && !empty($selectedCT['coll_type_name'])): ?>
    <?php $ctName = (string)$selectedCT['coll_type_name']; ?>
    <div class="bct-card">
      <div class="bct-head">
        <div>
          <h3 class="bct-title" style="font-size:18px;margin:0"><?php echo h($ctName); ?></h3>
          <div class="bct-sub">Total <?php echo (int)$totalItems; ?> judul • Halaman <?php echo (int)$page; ?></div>
        </div>
        <?php if ($letter !== ''): ?>
          <div><a class="bct-btn" href="<?php echo h(bct_url(['p'=>'browse_coll_type','letter'=>$letter])); ?>">← Kembali</a></div>
        <?php endif; ?>
      </div>

      <?php if (!$items): ?>
        <p>Tidak ada judul pada koleksi tipe ini.</p>
      <?php else: ?>
        <ol class="bct-list">
          <?php foreach ($items as $r): ?>
            <li>
              <?php
                $detailUrl = bct_url(['p'=>'show_detail','id'=>(int)$r['biblio_id']]);
                $line = bct_format_line($ctName, $r);

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
        <div class="bct-pager">
          <div>
            <?php if ($page > 1): ?>
              <a class="bct-btn" href="<?php echo h(bct_url(['p'=>'browse_coll_type','letter'=>$letter,'ctid'=>$ctid,'page'=>$prev,'per_page'=>$perPage])); ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="bct-btn" href="<?php echo h(bct_url(['p'=>'browse_coll_type','letter'=>$letter,'ctid'=>$ctid,'page'=>$next,'per_page'=>$perPage])); ?>">Next →</a>
            <?php endif; ?>
          </div>
          <div class="bct-sub">
            Per page:
            <?php foreach ([20,50,100] as $pp): ?>
              <a class="bct-btn" href="<?php echo h(bct_url(['p'=>'browse_coll_type','letter'=>$letter,'ctid'=>$ctid,'page'=>1,'per_page'=>$pp])); ?>"><?php echo (int)$pp; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
