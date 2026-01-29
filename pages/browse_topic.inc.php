<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : browse_topic.inc.php
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
 * File Cache Helper (files/cache/browse_topic)
 * ---------------------------------------------------------
 */
function bt_root_dir(): string {
  if (defined('SB')) return rtrim((string)SB, "/\\") . DIRECTORY_SEPARATOR;
  $fallback = realpath(__DIR__ . '/../../../');
  return rtrim((string)$fallback, "/\\") . DIRECTORY_SEPARATOR;
}
function bt_cache_dir(): string {
  $dir = bt_root_dir() . 'files' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'browse_topic' . DIRECTORY_SEPARATOR;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}
function bt_cache_key(string $name, array $params = []): string {
  return $name . '_' . md5(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function bt_cache_get(string $key, int $ttlSeconds): mixed {
  $file = bt_cache_dir() . $key . '.cache.php';
  if (!is_file($file)) return null;
  $mtime = filemtime($file);
  if (!$mtime || (time() - $mtime) > $ttlSeconds) return null;
  $data = include $file;
  return $data ?? null;
}
function bt_cache_set(string $key, mixed $value): void {
  $file = bt_cache_dir() . $key . '.cache.php';
  $export = var_export($value, true);
  @file_put_contents($file, "<?php\nreturn {$export};\n", LOCK_EX);
}

/**
 * ---------------------------------------------------------
 * Utils
 * ---------------------------------------------------------
 */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function bt_url(array $qs = []): string {
  $base = defined('SWB') ? SWB : './';
  return $base . 'index.php?' . http_build_query($qs);
}
function bt_letter(mixed $l): string {
  $l = strtoupper(trim((string)$l));
  return preg_match('/^[A-Z]$/', $l) ? $l : '';
}
function bt_int(mixed $v): int { return max(0, (int)$v); }

function bt_format_line(string $topic, array $r): string {
  $author = (string)($r['author_name'] ?? '');
  $year   = (string)($r['publish_year'] ?? '');
  $gmd    = (string)($r['gmd_name'] ?? '');
  $title  = (string)($r['title'] ?? '');
  $place  = (string)($r['place_name'] ?? '');
  $pub    = (string)($r['publisher_name'] ?? '');

  $parts = [];

  // Topic di depan
  $parts[] = rtrim($topic, '.') . '.';

  // Author
  if ($author !== '') $parts[] = rtrim($author, '.') . '.';

  // (Year)
  if ($year !== '') $parts[] = '(' . $year . ').';

  // GMD
  if ($gmd !== '') $parts[] = $gmd . '.';

  // Title (nanti akan di-link)
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

$letter = bt_letter($_GET['letter'] ?? '');
$tid = bt_int($_GET['tid'] ?? 0);

// paging (untuk daftar judul per topic)
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
$letterCountsKey = bt_cache_key('letter_counts', []);
$letterCounts = bt_cache_get($letterCountsKey, $ttl);

if (!is_array($letterCounts)) {
  // Catatan: pakai UPPER(LEFT(topic,1)) untuk grup huruf
  $stmt = $db->query("
    SELECT UPPER(LEFT(t.topic, 1)) AS letter, COUNT(*) AS total
    FROM mst_topic t
    WHERE t.topic IS NOT NULL AND t.topic <> ''
    GROUP BY UPPER(LEFT(t.topic, 1))
  ");
  $letterCounts = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $L = strtoupper((string)($r['letter'] ?? ''));
    if (preg_match('/^[A-Z]$/', $L)) $letterCounts[$L] = (int)($r['total'] ?? 0);
  }
  bt_cache_set($letterCountsKey, $letterCounts);
}

/**
 * ---------------------------------------------------------
 * Data: topics by letter (cache)
 * daftar topik + jumlah judul terkait (biblio_topic)
 * ---------------------------------------------------------
 */
$topics = [];
if ($letter !== '') {
  $topicsKey = bt_cache_key('topics_by_letter', ['letter' => $letter]);
  $topics = bt_cache_get($topicsKey, $ttl);

  if (!is_array($topics)) {
    $stmt = $db->prepare("
      SELECT
        t.topic_id,
        t.topic,
        COUNT(DISTINCT bt.biblio_id) AS total_titles
      FROM mst_topic t
      LEFT JOIN biblio_topic bt ON bt.topic_id = t.topic_id
      WHERE UPPER(LEFT(t.topic, 1)) = :letter
      GROUP BY t.topic_id, t.topic
      ORDER BY t.topic ASC
    ");
    $stmt->bindValue(':letter', $letter, PDO::PARAM_STR);
    $stmt->execute();
    $topics = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bt_cache_set($topicsKey, $topics);
  }
}

/**
 * ---------------------------------------------------------
 * Data: items by topic (cache)
 * - join biblio_topic -> biblio -> author -> gmd -> place -> publisher
 * ---------------------------------------------------------
 */
$selectedTopic = null;
$items = [];
$totalItems = 0;

if ($tid > 0) {
  // ambil nama topic (cache)
  $topicInfoKey = bt_cache_key('topic_info', ['tid'=>$tid]);
  $selectedTopic = bt_cache_get($topicInfoKey, $ttl);

  if (!is_array($selectedTopic) || empty($selectedTopic['topic'])) {
    $stmt = $db->prepare("SELECT topic_id, topic FROM mst_topic WHERE topic_id = :tid LIMIT 1");
    $stmt->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmt->execute();
    $selectedTopic = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    bt_cache_set($topicInfoKey, $selectedTopic);
  }

  $topicName = (string)($selectedTopic['topic'] ?? '');

  // total count (cache)
  $countKey = bt_cache_key('items_count_by_topic', ['tid'=>$tid]);
  $totalItems = bt_cache_get($countKey, $ttl);

  if (!is_int($totalItems)) {
    $stmtCount = $db->prepare("
      SELECT COUNT(DISTINCT bt.biblio_id) AS cnt
      FROM biblio_topic bt
      WHERE bt.topic_id = :tid
    ");
    $stmtCount->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalItems = (int)($stmtCount->fetchColumn() ?: 0);
    bt_cache_set($countKey, $totalItems);
  }

  // items page (cache)
  $itemsKey = bt_cache_key('items_by_topic', ['tid'=>$tid,'offset'=>$offset,'per_page'=>$perPage]);
  $items = bt_cache_get($itemsKey, $ttl);

  if (!is_array($items)) {
    $stmtItems = $db->prepare("
      SELECT
        b.biblio_id,
        b.title,
        b.publish_year,
        g.gmd_name,
        p.publisher_name,
        pl.place_name,
        GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR '; ') AS author_name
      FROM biblio_topic bt
      JOIN biblio b ON b.biblio_id = bt.biblio_id
      LEFT JOIN mst_gmd g ON g.gmd_id = b.gmd_id
      LEFT JOIN mst_publisher p ON p.publisher_id = b.publisher_id
      LEFT JOIN mst_place pl ON pl.place_id = b.publish_place_id
      LEFT JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
      LEFT JOIN mst_author a ON a.author_id = ba.author_id
      WHERE bt.topic_id = :tid
      GROUP BY b.biblio_id
      ORDER BY b.title ASC
      LIMIT :limit OFFSET :offset
    ");
    $stmtItems->bindValue(':tid', $tid, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtItems->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    bt_cache_set($itemsKey, $items);
  }
}

/**
 * ---------------------------------------------------------
 * UI
 * ---------------------------------------------------------
 */
?>
<style>
  .bt-wrap{max-width:1100px;margin:0 auto;padding:16px}
  .bt-card{background:#fff;border-radius:14px;box-shadow:0 6px 20px rgba(0,0,0,.06);padding:16px;margin-bottom:14px}
  .bt-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .bt-title{font-size:20px;font-weight:700;margin:0}
  .bt-sub{color:#6b7280;margin:2px 0 0;font-size:13px}

  .bt-az{display:flex;flex-wrap:wrap;gap:6px;margin-top:12px}
  .bt-chip{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;
    border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bt-chip:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bt-chip.active{border-color:rgba(0,0,0,.35);background:rgba(0,0,0,.03)}
  .bt-chip.off{opacity:.45;pointer-events:none}
  .bt-badge{font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(0,0,0,.06)}

  .bt-topics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}
  @media (max-width: 900px){.bt-topics{grid-template-columns:repeat(2,minmax(0,1fr));}}
  @media (max-width: 640px){.bt-topics{grid-template-columns:repeat(1,minmax(0,1fr));}}

  .bt-topic{display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.10);text-decoration:none}
  .bt-topic:hover{border-color:rgba(0,0,0,.25);background:rgba(0,0,0,.02)}
  .bt-topic strong{font-size:14px}
  .bt-topic .bt-badge{white-space:nowrap}

  .bt-list{margin:0;padding-left:18px}
  .bt-list li{margin:8px 0;line-height:1.5}
  .bt-list a{font-weight:700;text-decoration:none}
  .bt-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:10px;border:1px solid rgba(0,0,0,.10);
    text-decoration:none}
  .bt-pager{display:flex;gap:8px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:10px}
</style>

<div class="bt-wrap">

  <div class="bt-card">
    <?php include __DIR__ . '/_browse_nav.php'; ?>
    <div class="bt-head">
      <div>
        <h2 class="bt-title">Browse by Topic</h2>
        <div class="bt-sub">Pilih huruf A–Z untuk menampilkan daftar topik, lalu klik topik untuk melihat koleksi.</div>
      </div>
      <div>
        <a class="bt-btn" href="<?php echo h(bt_url(['p'=>'browse_topic'])); ?>">Reset</a>
      </div>
    </div>

    <div class="bt-az">
      <?php foreach (range('A','Z') as $L): ?>
        <?php
          $cnt = (int)($letterCounts[$L] ?? 0);
          $cls = 'bt-chip';
          if ($letter === $L) $cls .= ' active';
          if ($cnt === 0) $cls .= ' off';
          $href = bt_url(['p'=>'browse_topic','letter'=>$L]);
        ?>
        <a class="<?php echo h($cls); ?>" href="<?php echo h($href); ?>">
          <strong><?php echo h($L); ?></strong>
          <span class="bt-badge"><?php echo $cnt; ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($letter !== ''): ?>
      <div style="margin-top:12px" class="bt-sub">Topik diawali huruf <strong><?php echo h($letter); ?></strong>:</div>

      <?php if (!$topics): ?>
        <p>Tidak ada topik pada huruf ini.</p>
      <?php else: ?>
        <div class="bt-topics">
          <?php foreach ($topics as $t): ?>
            <?php
              $topicId = (int)($t['topic_id'] ?? 0);
              $topicNm = (string)($t['topic'] ?? '');
              $totalT  = (int)($t['total_titles'] ?? 0);
              $href = bt_url(['p'=>'browse_topic','letter'=>$letter,'tid'=>$topicId,'page'=>1]);
            ?>
            <a class="bt-topic" href="<?php echo h($href); ?>">
              <strong><?php echo h($topicNm); ?></strong>
              <span class="bt-badge"><?php echo $totalT; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($tid > 0 && is_array($selectedTopic) && !empty($selectedTopic['topic'])): ?>
    <?php $topicName = (string)$selectedTopic['topic']; ?>
    <div class="bt-card">
      <div class="bt-head">
        <div>
          <h3 class="bt-title" style="font-size:18px;margin:0"><?php echo h($topicName); ?></h3>
          <div class="bt-sub">Total <?php echo (int)$totalItems; ?> judul • Halaman <?php echo (int)$page; ?></div>
        </div>
        <?php if ($letter !== ''): ?>
          <div>
            <a class="bt-btn" href="<?php echo h(bt_url(['p'=>'browse_topic','letter'=>$letter])); ?>">← Kembali ke daftar topik</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$items): ?>
        <p>Tidak ada judul untuk topik ini.</p>
      <?php else: ?>
        <ol class="bt-list">
          <?php foreach ($items as $r): ?>
            <li>
              <?php
                $detailUrl = bt_url(['p'=>'show_detail','id'=>(int)$r['biblio_id']]);
                $line = bt_format_line($topicName, $r);

                // link-kan judul
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
        <div class="bt-pager">
          <div>
            <?php if ($page > 1): ?>
              <a class="bt-btn" href="<?php echo h(bt_url(['p'=>'browse_topic','letter'=>$letter,'tid'=>$tid,'page'=>$prev,'per_page'=>$perPage])); ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="bt-btn" href="<?php echo h(bt_url(['p'=>'browse_topic','letter'=>$letter,'tid'=>$tid,'page'=>$next,'per_page'=>$perPage])); ?>">Next →</a>
            <?php endif; ?>
          </div>
          <div class="bt-sub">
            Per page:
            <?php foreach ([20,50,100] as $pp): ?>
              <a class="bt-btn" href="<?php echo h(bt_url(['p'=>'browse_topic','letter'=>$letter,'tid'=>$tid,'page'=>1,'per_page'=>$pp])); ?>"><?php echo (int)$pp; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

</div>
