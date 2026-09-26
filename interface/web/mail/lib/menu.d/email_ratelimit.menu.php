<?php

/*
 * Menu entry for the extension's "Ratelimit" page.
 *
 * ISPConfig::capp.php scans <module>/lib/menu.d/*.menu.php through
 * include_menu_dir_files(), so dropping this file in gives the mail module an
 * extra menu entry without touching the core module.conf.php.
 *
 * $items already contains the entries built by the core module.conf.php, and
 * $module['nav'] is populated at its end, so appending here adds the entry to
 * the existing "Server Settings" group.
 */

$module['nav'][count($module['nav']) - 1]['items'][] = array(
	'title'   => 'Ratelimit',
	'target'  => 'content',
	'link'    => 'mail/email_ratelimit_list.php',
	'html_id' => 'email_ratelimit_list',
);
