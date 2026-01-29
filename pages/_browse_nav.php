<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : _browse_nav.inc.php
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
// Guard (biar tidak bisa dipanggil langsung)
if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
  die('can not access this file directly');
}

function bb_h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function bb_base(): string {
  // SWB biasanya sudah ada di SLiMS (base web path)
  return defined('SWB') ? SWB : './';
}

function bb_url(string $p): string {
  return bb_base() . 'index.php?p=' . rawurlencode($p);
}

// Tentukan halaman aktif dari parameter p
$current = $_GET['p'] ?? '';

// Daftar menu BrowseBy
$menus = [
  ['id' => 'browse_author',    'label' => 'Author'],
  ['id' => 'browse_year',      'label' => 'Year'],
  ['id' => 'browse_topic',     'label' => 'Topic'],
  ['id' => 'browse_gmd',       'label' => 'GMD'],
  ['id' => 'browse_coll_type', 'label' => 'Koleksi Tipe'],
];
?>

<style>
  .bb-nav-wrap{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:10px 0 12px}
  .bb-nav-title{font-size:12px;color:#6b7280;margin-right:6px}
  .bb-nav-btn{
    display:inline-flex;align-items:center;gap:6px;
    padding:7px 10px;border-radius:999px;
    border:1px solid rgba(0,0,0,.12);
    text-decoration:none;
    background:#fff;
    transition:.15s;
    font-size:13px;
  }
  .bb-nav-btn:hover{border-color:rgba(0,0,0,.28);background:rgba(0,0,0,.02)}
  .bb-nav-btn.active{border-color:rgba(0,0,0,.40);background:rgba(0,0,0,.05);font-weight:700}
</style>

<div class="bb-nav-wrap">
  <span class="bb-nav-title">Browse by:</span>
  <?php foreach ($menus as $m): ?>
    <?php $active = ($current === $m['id']) ? 'active' : ''; ?>
    <a class="bb-nav-btn <?php echo bb_h($active); ?>" href="<?php echo bb_h(bb_url($m['id'])); ?>">
      <?php echo bb_h($m['label']); ?>
    </a>
  <?php endforeach; ?>
</div>
