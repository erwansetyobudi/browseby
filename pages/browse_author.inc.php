<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : browse_author.inc.php
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
 * Cache helper (file cache di /files/cache/browse_author)
 * ---------------------------------------------------------
 */
function ba_root_dir(): string {
  // SB biasanya root SLiMS (mis. C:/laragon/www/slims/)
  if (defined('SB')) return rtrim((string)SB, "/\\") . DIRECTORY_SEPARATOR;

  // fallback (plugin ada di /plugins/<name>/pages/)
  $fallback = realpath(__DIR__ . '/../../../');
  return rtrim((string)$fallback, "/\\") . DIRECTORY_SEPARATOR;
}

function ba_cache_dir(): string {
  $dir = ba_root_dir() . 'files' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'browse_author' . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function ba_cache_key(string $name, array $params = []): string {
  return $name . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function ba_cache_get(string $key, int $ttlSeconds): mixed {
  $file = ba_cache_dir() . $key . '.cache.php';
  if (!is_file($file)) return null;

  $mtime = filemtime($file);
  if (!$mtime || (time() - $mtime) > $ttlSeconds) return null;

  $data = include $file;
  return $data ?? null;
}

function ba_cache_set(string $key, mixed $value): void {
  $file = ba_cache_dir() . $key . '.cache.php';
  $export = var_export($value, true);
  @file_put_contents($file, "<?php\nreturn {$export};\n", LOCK_EX);
}

/**
 * ---------------------------------------------------------
 * Util
 * ---------------------------------------------------------
 */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function ba_url(array $qs = []): string {
  // SWB umumnya base web path SLiMS, mis. http://host/slims/
  $base = defined('SWB') ? SWB : './';
  return $base . 'index.php?' . http_build_query($qs);
}

function ba_valid_letter(?string $letter): string {
  $letter = strtoupper(trim((string)$letter));
  if (!preg_match('/^[A-Z]$/', $letter)) return '';
  return $letter;
}

function ba_format_citation(array $r): string {
  $author = $r['author_name'] ?? '';
  $year   = $r['publish_year'] ?? '';
  $gmd    = $r['gmd_name'] ?? '';
  $title  = $r['title'] ?? '';
  $place  = $r['place_name'] ?? '';
  $pub    = $r['publisher_name'] ?? '';

  $parts = [];
  $parts[] = rtrim($author, '.') . '.';
  if ($year !== '') $parts[] = '(' . $year . ').';
  if ($gmd !== '') $parts[] = $gmd . '.';

  // judul dibuat italic via <em>
  $mainTitle = '<em>' . h($title) . '</em>.';
  $parts[] = $mainTitle;

  $pubLine = trim($place);
  if ($pubLine !== '' && $pub !== '') $pubLine .= ' : ' . $pub;
  else if ($pubLine === '' && $pub !== '') $pubLine = $pub;

  if ($pubLine !== '') $parts[] = h($pubLine) . '.';

  return implode(' ', $parts);
}

/**
 * ---------------------------------------------------------
 * Config
 * ---------------------------------------------------------
 */
$ttl = 6 * 60 * 60; // 6 jam (silakan ubah: 1 jam, 12 jam, dst.)

$letter = ba_valid_letter($_GET['letter'] ?? '');
$authorId = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;

// paging untuk daftar item (opsional)
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($perPage < 10) $perPage = 20;
if ($perPage > 100) $perPage = 100;
$offset = ($page - 1) * $perPage;

/**
 * ---------------------------------------------------------
 * Data: counts A-Z (cache)
 * ---------------------------------------------------------
 */
$countsKey = ba_cache_key('letter_counts', []);
$letterCounts = ba_cache_get($countsKey, $ttl);

if (!is_array($letterCounts)) {
  // Ambil 1 huruf pertama dari author_name lalu count biblio distinct
  // Catatan: MySQL default collation umumnya case-insensitive.
  $sql = "
    SELECT UPPER(LEFT(a.author_name, 1)) AS letter,
           COUNT(DISTINCT ba.biblio_id) AS total
    FROM mst_author a
    JOIN biblio_author ba ON ba.author_id = a.author_id
    WHERE a.author_name IS NOT NULL AND a.author_name <> ''
    GROUP BY UPPER(LEFT(a.author_name, 1))
  ";
  $stmt = $db->query($sql);

  $letterCounts = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $L = strtoupper(trim((string)$row['letter']));
    if (preg_match('/^[A-Z]$/', $L)) {
      $letterCounts[$L] = (int)$row['total'];
    }
  }
  ba_cache_set($countsKey, $letterCounts);
}

/**
 * ---------------------------------------------------------
 * Data: authors by letter (cache)
 * ---------------------------------------------------------
 */
$authors = [];
if ($letter !== '') {
  $authorsKey = ba_cache_key('authors_by_letter', ['letter' => $letter]);
  $authors = ba_cache_get($authorsKey, $ttl);

  if (!is_array($authors)) {
    $stmt = $db->prepare("
      SELECT a.author_id, a.author_name, COUNT(DISTINCT ba.biblio_id) AS total
      FROM mst_author a
      JOIN biblio_author ba ON ba.author_id = a.author_id
      WHERE a.author_name LIKE :prefix
      GROUP BY a.author_id, a.author_name
      ORDER BY a.author_name ASC
    ");
    $stmt->bindValue(':prefix', $letter . '%', PDO::PARAM_STR);
    $stmt->execute();

    $authors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ba_cache_set($authorsKey, $authors);
  }
}

/**
 * ---------------------------------------------------------
 * Data: items by author (cache)
 * ---------------------------------------------------------
 */
$items = [];
$totalItems = 0;

if ($authorId > 0) {
  // total count (cache)
  $countKey = ba_cache_key('items_count_by_author', ['author_id' => $authorId]);
  $totalItems = ba_cache_get($countKey, $ttl);

  if (!is_int($totalItems)) {
    $stmtCount = $db->prepare("
      SELECT COUNT(DISTINCT b.biblio_id) AS cnt
      FROM biblio_author ba
      JOIN biblio b ON b.biblio_id = ba.biblio_id
      WHERE ba.author_id = :aid
    ");
    $stmtCount->bindValue(':aid', $authorId, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalItems = (int)($stmtCount->fetchColumn() ?: 0);
    ba_cache_set($countKey, $totalItems);
  }

  // item list (cache per page)
  $itemsKey = ba_cache_key('items_by_author', [
    'author_id' => $authorId,
    'offset' => $offset,
    'per_page' => $perPage
  ]);
  $items = ba_cache_get($itemsKey, $ttl);

  if (!is_array($items)) {
    // Query
    $stmtItems = $db->prepare("
      SELECT
        b.biblio_id,
        b.title,
        b.publish_year,
        g.gmd_name,
        p.publisher_name,
        pl.place_name,
        a.author_name
      FROM biblio_author ba
      JOIN biblio b ON b.biblio_id = ba.biblio_id
      JOIN mst_author a ON a.author_id = ba.author_id
      LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
      LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
      LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
      WHERE ba.author_id = :aid
      GROUP BY b.biblio_id
      ORDER BY
        (CASE WHEN b.publish_year REGEXP '^[0-9]{4}$' THEN b.publish_year ELSE '0000' END) DESC,
        b.title ASC
      LIMIT :limit OFFSET :offset
    ");
    $stmtItems->bindValue(':aid', $authorId, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();

    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    ba_cache_set($itemsKey, $items);
  }
}

/**
 * ---------------------------------------------------------
 * UI
 * ---------------------------------------------------------
 */
?>
<style>
  .ba-wrap{max-width:1100px;margin:0 auto;padding:16px}
  .ba-card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;margin-bottom:14px}
  .ba-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .ba-title{font-size:20px;font-weight:700;margin:0}
  .ba-sub{color:#6b7280;margin:2px 0 0;font-size:13px}
  .ba-az{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .ba-az a{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;
    border-radius:10px;border:1px solid rgba(0,0,0,.10);text-decoration:none;font-weight:700}
  .ba-az a:hover{border-color:rgba(0,0,0,.25)}
  .ba-az a.active{border-color:rgba(0,0,0,.35);background:rgba(0,0,0,.03)}
  .ba-az a.muted{opacity:.35;pointer-events:none}
  .ba-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  @media (max-width: 980px){.ba-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
  @media (max-width: 640px){.ba-grid{grid-template-columns:repeat(1,minmax(0,1fr));}}
  .ba-author{display:flex;gap:10px;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:12px;
    border:1px solid rgba(0,0,0,.08);text-decoration:none}
  .ba-author:hover{border-color:rgba(0,0,0,.20);background:rgba(0,0,0,.02)}
  .ba-author-name{font-weight:700}
  .ba-badge{font-size:12px;padding:3px 8px;border-radius:999px;background:rgba(0,0,0,.06)}
  .ba-list{margin:0;padding-left:18px}
  .ba-list li{margin:8px 0;line-height:1.5}
  .ba-list a{font-weight:700;text-decoration:none}
  .ba-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}
  .ba-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid rgba(0,0,0,.10);
    text-decoration:none}
</style>

<div class="ba-wrap">

  <div class="ba-card">
    <?php include __DIR__ . '/_browse_nav.php'; ?>
    <div class="ba-head">
      <div>
        <h2 class="ba-title">Browse by Author</h2>
        <div class="ba-sub">Klik huruf untuk melihat daftar pengarang, lalu klik pengarang untuk melihat koleksi.</div>
      </div>
      <div>
        <a class="ba-btn" href="<?php echo h(ba_url(['p' => 'browse_author'])); ?>">Reset</a>
      </div>
    </div>

    <div class="ba-az">
      <?php
        foreach (range('A','Z') as $L) {
          $cnt = (int)($letterCounts[$L] ?? 0);
          $class = [];
          if ($letter === $L) $class[] = 'active';
          if ($cnt === 0) $class[] = 'muted';
          $cls = implode(' ', $class);

          $url = ba_url(['p' => 'browse_author', 'letter' => $L]);
          echo '<a class="'.h($cls).'" href="'.h($url).'" title="'.h($cnt.' item').'">'.$L.'</a>';
        }
      ?>
    </div>
  </div>

  <?php if ($letter !== '' && $authorId === 0): ?>
    <div class="ba-card">
      <div class="ba-head">
        <div>
          <h3 class="ba-title" style="font-size:18px;margin:0">Pengarang huruf “<?php echo h($letter); ?>”</h3>
          <div class="ba-sub"><?php echo count($authors); ?> pengarang ditemukan</div>
        </div>
      </div>

      <?php if (!$authors): ?>
        <p>Tidak ada data pengarang untuk huruf ini.</p>
      <?php else: ?>
        <div class="ba-grid">
          <?php foreach ($authors as $a): ?>
            <a class="ba-author"
               href="<?php echo h(ba_url(['p'=>'browse_author','letter'=>$letter,'author_id'=>(int)$a['author_id']])); ?>">
              <span class="ba-author-name"><?php echo h($a['author_name']); ?></span>
              <span class="ba-badge"><?php echo (int)$a['total']; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($authorId > 0): ?>
    <div class="ba-card">
      <div class="ba-head">
        <div>
          <h3 class="ba-title" style="font-size:18px;margin:0">Daftar koleksi</h3>
          <div class="ba-sub">Total <?php echo (int)$totalItems; ?> judul • Halaman <?php echo (int)$page; ?></div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($letter !== ''): ?>
            <a class="ba-btn" href="<?php echo h(ba_url(['p'=>'browse_author','letter'=>$letter])); ?>">← Kembali ke daftar pengarang</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$items): ?>
        <p>Tidak ada judul untuk pengarang ini.</p>
      <?php else: ?>
        <ol class="ba-list">
          <?php foreach ($items as $r): ?>
            <li>
              <?php
                $detailUrl = ba_url(['p' => 'show_detail', 'id' => (int)$r['biblio_id']]);
                // Tampilkan format: Author. (Year). GMD. Judul. Place : Publisher.
                // Judul jadi link ke show_detail.
                $citation = ba_format_citation($r);
                // Replace judul italic jadi link
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
        <div class="ba-pager">
          <div>
            <?php if ($page > 1): ?>
              <a class="ba-btn" href="<?php echo h(ba_url(['p'=>'browse_author','letter'=>$letter,'author_id'=>$authorId,'page'=>$prev,'per_page'=>$perPage])); ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="ba-btn" href="<?php echo h(ba_url(['p'=>'browse_author','letter'=>$letter,'author_id'=>$authorId,'page'=>$next,'per_page'=>$perPage])); ?>">Next →</a>
            <?php endif; ?>
          </div>

          <div class="ba-sub">
            Per page:
            <?php foreach ([20,50,100] as $pp): ?>
              <a class="ba-btn" href="<?php echo h(ba_url(['p'=>'browse_author','letter'=>$letter,'author_id'=>$authorId,'page'=>1,'per_page'=>$pp])); ?>"><?php echo (int)$pp; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
