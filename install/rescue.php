<?php

/*
 * email_ratelimit - standalone rescue / force uninstall.
 *
 * Use this when the normal `deploy.php uninstall` cannot run: the install died
 * halfway, the extension's PHP is broken, the ISPConfig bootstrap fails, or the
 * database is unreachable. It needs nothing but PHP and root on the mail server
 * (no app.inc.php, no ISPConfig classes).
 *
 *   php install/rescue.php                  # preview only (default)
 *   php install/rescue.php --yes            # perform the cleanup
 *   php install/rescue.php --yes --no-db --no-reload
 *
 * Options:
 *   --yes           actually change anything (without it: dry run)
 *   --no-db         leave the database alone
 *   --no-reload     do not reload rspamd
 *   --root=DIR      alternate filesystem root (default "/", for tests)
 *
 * Order matters: the rspamd include block is removed first and the rest is
 * skipped if that fails, because deleting the generated files while rspamd
 * still dofile()s them would leave rspamd unable to start.
 */

if(PHP_SAPI !== 'cli') {
	http_response_code(403);
	die("This script must be run from the command line.\n");
}

require_once __DIR__ . '/rspamd_block.inc.php';

//* shared MySQL superuser helper (optional, rescue must stay usable alone)
if(is_file(__DIR__ . '/root_client.inc.php')) require_once __DIR__ . '/root_client.inc.php';

define('ERL_RESCUE_EXT', 'email_ratelimit');

//* ---------------------------------------------------------------- file lists

/** Files this extension deploys, used when install/file.list is unavailable. */
function erl_rescue_builtin_targets() {
	return array(
		'server/lua/email_ratelimit.lua',
		'server/lib/classes/email_ratelimit_sync.inc.php',
		'server/lib/classes/cron.d/100-email_ratelimit.inc.php',
		'server/plugins-available/email_ratelimit_plugin.inc.php',
		'server/plugins-enabled/email_ratelimit_plugin.inc.php',
		'server/mods-available/email_ratelimit_module.inc.php',
		'server/mods-enabled/email_ratelimit_module.inc.php',
		'interface/web/mail/email_ratelimit_list.php',
		'interface/web/mail/email_ratelimit_edit.php',
		'interface/web/mail/email_ratelimit_del.php',
		'interface/web/mail/form/email_ratelimit.tform.php',
		'interface/web/mail/list/email_ratelimit.list.php',
		'interface/web/mail/templates/email_ratelimit_list.htm',
		'interface/web/mail/templates/email_ratelimit_edit.htm',
		'interface/web/mail/lib/menu.d/email_ratelimit.menu.php',
		'interface/web/mail/lib/plugin.d/email_ratelimit_domain.inc.php',
		'interface/web/mail/lib/plugin.d/email_ratelimit_user.inc.php',
		'interface/web/admin/lib/plugin.d/email_ratelimit_server.inc.php',
		'interface/web/mail/lib/lang/en_email_ratelimit.lng',
		'interface/web/mail/lib/lang/en_email_ratelimit_list.lng',
		'interface/web/themes/default/templates/admin/server_config_mail_edit.htm',
		'interface/web/themes/default/templates/mail/mail_domain_edit.htm',
		'interface/web/themes/default/templates/mail/mail_user_mailbox_edit.htm',
	);
}

/** Targets from install/file.list when present, otherwise the built-in list. */
function erl_rescue_targets($ispconfig_dir) {
	$list = $ispconfig_dir . '/extensions/' . ERL_RESCUE_EXT . '/install/file.list';
	$targets = array();

	if(is_file($list)) {
		foreach(file($list, FILE_IGNORE_NEW_LINES) as $line) {
			$line = trim($line);
			if($line === '' || $line[0] === '#') continue;
			$parts = explode(':', $line);
			if(count($parts) !== 3) continue;
			list($action, $source, $target) = $parts;
			//* directory entries are handled by rmdir-if-empty below
			if($target === '' || strpos($target, '..') !== false) continue;
			if($action === 'd') continue;
			$targets[] = $target;
		}
	}

	if(empty($targets)) $targets = erl_rescue_builtin_targets();
	return $targets;
}

//* ---------------------------------------------------------------- operations

function erl_rescue_local_lua($root) {
	return rtrim($root, '/') . '/etc/rspamd/rspamd.local.lua';
}

/**
 * Remove the include block. Returns false when the file exists but could not
 * be rewritten - the caller must then skip every destructive step.
 */
function erl_rescue_strip_include($root, $dry, &$log) {
	$path = erl_rescue_local_lua($root);
	if(!file_exists($path)) return true;

	$current = @file_get_contents($path);
	if($current === false) {
		$log[] = "ERROR: cannot read $path";
		return false;
	}
	if(strpos($current, 'email_ratelimit') === false) return true;

	$clean = erl_rspamd_strip($current);
	if($clean === $current) return true;

	if($dry) {
		$log[] = "[dry] would remove the email_ratelimit block from $path";
		return true;
	}
	if(!erl_write_atomic($path, $clean, 0644)) {
		$log[] = "ERROR: cannot write $path";
		return false;
	}
	$log[] = "removed the email_ratelimit block from $path";
	return true;
}

function erl_rescue_remove_generated($root, $dry, &$log) {
	$ok = true;
	$base = rtrim($root, '/') . '/etc/rspamd/';

	$files = array(
		$base . 'email_ratelimit.conf.lua',
		$base . 'email_ratelimit.lua',
	);
	//* leftovers of an interrupted atomic write
	foreach(glob($base . 'email_ratelimit*.lua.tmp.*') ?: array() as $f) {
		$files[] = $f;
	}

	foreach($files as $f) {
		if(!file_exists($f)) continue;
		if($dry) {
			$log[] = '[dry] would delete ' . $f;
			continue;
		}
		if(@unlink($f)) {
			$log[] = 'deleted ' . $f;
		} else {
			$log[] = 'ERROR: cannot delete ' . $f;
			$ok = false;
		}
	}
	return $ok;
}

/**
 * Remove (or restore) one deployed file.
 * A `.email_ratelimit.bak` left by an install that shadow-copied an existing
 * file is restored, never deleted: uninstalling must not eat customisations.
 */
function erl_rescue_remove_file($ispconfig_dir, $target, $dry, &$log) {
	$abs = rtrim($ispconfig_dir, '/') . '/' . $target;
	$bak = $abs . '.email_ratelimit.bak';

	if(file_exists($bak)) {
		if($dry) {
			$log[] = "[dry] would restore $target from its backup";
			return true;
		}
		if(@rename($bak, $abs)) {
			$log[] = "restored $target from backup";
			return true;
		}
		$log[] = "ERROR: cannot restore $target from backup";
		return false;
	}

	if(!is_link($abs) && !is_file($abs)) return true;

	if($dry) {
		$log[] = '[dry] would remove ' . $target;
		return true;
	}
	if(@unlink($abs)) {
		$log[] = 'removed ' . $target;
		return true;
	}
	$log[] = 'ERROR: cannot remove ' . $target;
	return false;
}

function erl_rescue_remove_deployed($ispconfig_dir, $dry, &$log) {
	$ok = true;
	foreach(erl_rescue_targets($ispconfig_dir) as $target) {
		if(!erl_rescue_remove_file($ispconfig_dir, $target, $dry, $log)) $ok = false;
	}

	//* directories that were created for the extension, only if now empty
	foreach(array(
		$ispconfig_dir . '/interface/web/mail/lib/plugin.d',
		$ispconfig_dir . '/interface/web/admin/lib/plugin.d',
		$ispconfig_dir . '/interface/web/themes/default/templates/mail',
		$ispconfig_dir . '/interface/web/themes/default/templates/admin',
	) as $dir) {
		if(!is_dir($dir)) continue;
		if($dry) {
			if(count(scandir($dir)) <= 2) $log[] = "[dry] would remove empty directory $dir";
			continue;
		}
		if(count(scandir($dir)) <= 2 && @rmdir($dir)) {
			$log[] = "removed empty directory $dir";
		}
	}

	//* copies the admin made into a non-default theme are theirs to handle
	foreach(glob($ispconfig_dir . '/interface/web/themes/*/templates/{mail,admin}/*.htm', GLOB_BRACE) ?: array() as $f) {
		if(strpos((string)@file_get_contents($f), 'erl_') !== false) {
			$log[] = 'NOTE: theme copy left in place, remove it by hand: ' . $f;
		}
	}

	return $ok;
}

//* ---------------------------------------------------------------- database

function erl_rescue_connect($conf, $prefix, &$log) {
	$host = $prefix . 'host';
	$port = $prefix . 'port';
	$dbname = $prefix . 'database';
	$user = $prefix . 'user';
	$pass = $prefix . 'password';

	$h = isset($conf[$host]) ? $conf[$host] : '';
	$d = isset($conf[$dbname]) ? $conf[$dbname] : '';
	$u = isset($conf[$user]) ? $conf[$user] : '';
	$p = isset($conf[$pass]) ? $conf[$pass] : '';
	$pn = isset($conf[$port]) ? intval($conf[$port]) : 3306;

	if($h === '' || $d === '' || $u === '') return null;

	//* PHP >= 8.1 makes mysqli throw by default, and "@" does not suppress
	//  exceptions - a refused connection would otherwise abort the rescue.
	if(function_exists('mysqli_report')) @mysqli_report(MYSQLI_REPORT_OFF);

	try {
		$m = @new mysqli($h, $u, $p, $d, $pn);
	} catch(Exception $e) {
		$log[] = "WARN: cannot connect to $d: " . $e->getMessage();
		return null;
	}
	if($m->connect_errno) {
		$log[] = "WARN: cannot connect to $d: " . $m->connect_error;
		return null;
	}
	return $m;
}

/**
 * Run SQL through the root MySQL client (unix_socket auth on Debian/Ubuntu).
 * true = ok, false = failed, null = no client installed.
 */
function erl_rescue_cli_sql($sql, $dbname) {
	//* The shared helper knows where ISPConfig keeps the MySQL superuser
	//  credentials (mysql_clientdb.conf, debian.cnf, ...).
	if(function_exists('erl_root_sql')) {
		return erl_root_sql($sql, $dbname) ? true : false;
	}

	$bin = null;
	foreach(array('/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mysql') as $b) {
		if(is_executable($b)) {
			$bin = $b;
			break;
		}
	}
	if($bin === null) return null;

	$cmd = escapeshellarg($bin) . ' --protocol=socket --user=root --batch ' . escapeshellarg($dbname);
	$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$proc = @proc_open($cmd, $desc, $pipes);
	if(!is_resource($proc)) return null;

	fwrite($pipes[0], rtrim($sql, "; \n\t\r") . ";\n");
	fclose($pipes[0]);
	stream_get_contents($pipes[1]);
	$err = (string)stream_get_contents($pipes[2]);
	@fclose($pipes[1]);
	@fclose($pipes[2]);
	$rc = proc_close($proc);
	if($rc !== 0) return trim($err) !== '' ? trim($err) : false;
	return true;
}

/**
 * Idempotent DDL. "Unknown column"/"unknown table" mean the goal is already
 * met, and a hardened `ispconfig` DB user that may not ALTER falls back to the
 * root client - the same privilege the core installer had.
 */
function erl_rescue_sql($link, $sql, $dbname, $label, &$log) {
	if($link !== null) {
		try {
			$done = @$link->query($sql);
		} catch(Exception $e) {
			$done = false;
			$log[] = 'WARN: ' . $e->getMessage();
		}
		if($done) {
			$log[] = $label;
			return true;
		}
		$code = $link->errno;
		if($code === 1091 || $code === 1051 || $code === 1146) {
			$log[] = $label . ' (already gone)';
			return true;
		}
		$app_err = $link->error;
	} else {
		$app_err = 'no database connection';
	}

	$cli = erl_rescue_cli_sql($sql, $dbname);
	if($cli === true) {
		$log[] = $label . ' (via root client)';
		return true;
	}

	//* the root client reports "already gone" as an error too (1051 unknown
	//  table, 1091 can't drop, 1146 table missing); that is still success
	if(is_string($cli) && preg_match('/\b(1051|1091|1146)\b/', $cli)) {
		$log[] = $label . ' (already gone)';
		return true;
	}

	if($cli === null) {
		$log[] = 'ERROR: ' . $label . ': ' . $app_err . ' (and no root MySQL client installed)';
	} else {
		$log[] = 'ERROR: ' . $label . ': ' . ($cli === false ? $app_err : $cli);
	}
	return false;
}

function erl_rescue_db($ispconfig_dir, $dry, &$log) {
	$cfg = $ispconfig_dir . '/server/lib/config.inc.php';
	if(!is_file($cfg)) {
		$log[] = 'note: no config.inc.php found, skipping database cleanup';
		return true;
	}

	//* config.inc.php only assigns $conf - include it in local scope
	$conf = array();
	include $cfg;
	if(!is_array($conf) || empty($conf)) {
		$log[] = 'WARN: could not read database credentials, skipping database cleanup';
		return true;
	}

	$dbname = isset($conf['db_database']) ? $conf['db_database'] : '';
	if($dbname === '') {
		$log[] = 'WARN: no database name in config.inc.php, skipping database cleanup';
		return true;
	}

	$has_mysqli = class_exists('mysqli');
	$local = $has_mysqli ? erl_rescue_connect($conf, 'db_', $log) : null;
	$master = $has_mysqli ? erl_rescue_connect($conf, 'dbmaster_', $log) : null;
	if($master === null) $master = $local;

	$columns = array(
		'server'      => array('erl_enabled', 'erl_out_limit', 'erl_out_interval', 'erl_in_limit', 'erl_in_interval', 'erl_default_user_out'),
		'mail_domain' => array('erl_limit_mode', 'erl_out_limit', 'erl_out_interval', 'erl_in_limit', 'erl_in_interval'),
		'mail_user'   => array('erl_limit_mode', 'erl_out_limit', 'erl_out_interval', 'erl_in_limit', 'erl_in_interval'),
	);

	$ok = true;
	foreach($columns as $table => $cols) {
		foreach($cols as $col) {
			$sql = "ALTER TABLE `" . $table . "` DROP COLUMN `" . $col . "`";
			if($dry) {
				$log[] = "[dry] would drop column $table.$col";
				continue;
			}
			if(!erl_rescue_sql($local, $sql, $dbname, "dropped column $table.$col", $log)) $ok = false;
		}
	}

	$sql = 'DROP TABLE IF EXISTS `email_ratelimit`';
	if($dry) {
		$log[] = '[dry] would drop table email_ratelimit (if present)';
	} elseif(!erl_rescue_sql($master, $sql, $dbname, 'dropped table email_ratelimit', $log)) {
		$ok = false;
	}

	if($local !== null) @$local->close();
	if($master !== null && $master !== $local) @$master->close();

	if(!$dry && !$ok) {
		$log[] = 'note: database artifacts remain; drop them by hand once the DB is reachable';
	}
	return $ok;
}

//* ---------------------------------------------------------------- reload

function erl_rescue_reload($dry, &$log) {
	if($dry) {
		$log[] = '[dry] would reload rspamd';
		return true;
	}
	foreach(array('systemctl reload-or-restart rspamd', 'service rspamd reload') as $cmd) {
		$out = array();
		$rc = 1;
		@exec($cmd . ' 2>&1', $out, $rc);
		if($rc === 0) {
			$log[] = 'reloaded rspamd';
			return true;
		}
	}
	$log[] = 'WARN: could not reload rspamd, run: service rspamd reload';
	return false;
}

//* ---------------------------------------------------------------- main

function erl_rescue_main($argv) {
	$dry = true;
	$db = true;
	$reload = true;
	$root = '/';

	foreach(array_slice($argv, 1) as $arg) {
		if($arg === '--yes') $dry = false;
		elseif($arg === '--no-db') $db = false;
		elseif($arg === '--no-reload') $reload = false;
		elseif(strpos($arg, '--root=') === 0) $root = substr($arg, 7);
		elseif($arg === '--help' || $arg === '-h') {
			echo "usage: php install/rescue.php [--yes] [--no-db] [--no-reload] [--root=DIR]\n";
			return 0;
		} else {
			fwrite(STDERR, "unknown option: $arg\n");
			return 2;
		}
	}

	if(!function_exists('posix_getuid') || posix_getuid() !== 0) {
		fwrite(STDERR, "This script must be run as root.\n");
		return 2;
	}

	$ispconfig_dir = rtrim($root, '/') . '/usr/local/ispconfig';

	echo "email_ratelimit rescue\n";
	echo str_repeat('-', 60) . "\n";
	echo 'mode   : ' . ($dry ? "DRY RUN (pass --yes to apply)\n" : "APPLY\n");
	echo 'root   : ' . $root . "\n";
	echo 'ispconfig: ' . $ispconfig_dir . ($db ? "\n" : " (database cleanup disabled)\n");

	$log = array();
	$ok = true;

	//* 1. the include must go first; if it cannot, touch nothing else
	if(!erl_rescue_strip_include($root, $dry, $log)) {
		foreach($log as $l) echo '  ' . $l . "\n";
		echo "ABORTED: could not remove the rspamd include. Fix that first;\n";
		echo "         deleting the generated files now would stop rspamd from starting.\n";
		return 1;
	}

	$ok = erl_rescue_remove_generated($root, $dry, $log) && $ok;
	$ok = erl_rescue_remove_deployed($ispconfig_dir, $dry, $log) && $ok;
	if($db) $ok = erl_rescue_db($ispconfig_dir, $dry, $log) && $ok;
	if($reload) $ok = erl_rescue_reload($dry, $log) && $ok;

	foreach($log as $l) echo '  ' . $l . "\n";

	echo str_repeat('-', 60) . "\n";
	if($dry) {
		echo "dry run: nothing was changed. Re-run with --yes to apply.\n\n";
	} else {
		echo ($ok ? "done.\n" : "done, with problems (see above).\n");
		echo "The extension directory can be removed once you are satisfied:\n";
		echo "  rm -rf $ispconfig_dir/extensions/" . ERL_RESCUE_EXT . "\n\n";
	}
	return $ok ? 0 : 1;
}

//* run only when executed directly, so tests can include this file
if(isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
	exit(erl_rescue_main($argv));
}
