<?php
/**
 * Plugin Name: Browse By
 * Plugin URI: https://github.com/erwansetyobudi/browseby
 * Description: Plugin SLiMS ini dirancang untuk membantu pengguna OPAC menelusuri koleksi secara terstruktur dan cepat tanpa membebani database.
 * Version: 1.0.0
 * Author: Erwan Setyo Budi (erwans818@gmail.com)
 * Author URI: https://github.com/erwansetyobudi/
 */

use SLiMS\Plugins;

$plugins = Plugins::getInstance();

/**
 * Example OPAC path:
 * /index.php?p=browse_author
 */
$plugins->registerMenu('opac', 'browse_author', __DIR__ . '/pages/browse_author.inc.php');
$plugins->registerMenu('opac', 'browse_year', __DIR__ . '/pages/browse_year.inc.php');
$plugins->registerMenu('opac', 'browse_topic', __DIR__ . '/pages/browse_topic.inc.php');
$plugins->registerMenu('opac', 'browse_gmd', __DIR__ . '/pages/browse_gmd.inc.php');
$plugins->registerMenu('opac', 'browse_coll_type', __DIR__ . '/pages/browse_coll_type.inc.php');