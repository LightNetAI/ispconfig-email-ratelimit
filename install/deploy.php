<?php

/*
 * email_ratelimit deploy helper
 *
 * The ISPConfig extension installer only installs extensions published in its
 * online catalogue ("ispc extension install <name>" resolves the name against
 * that catalogue). A privately built extension is therefore deployed from the
 * directory it already sits in:
 *
 *   cp -a email_ratelimit /usr/local/ispconfig/extensions/
 *   cd /usr/local/ispconfig/extensions/email_ratelimit
 *   php install/deploy.php install     # or: update | enable | disable | uninstall
 *
 * The script builds a minimal ISPConfig server-side environment, loads the
 * installer class, drives it, and runs the SQL files the same way the core
 * would. Run as root.
 */

if(PHP_SAPI !== 'cli') die("This script must be run from the command line.\n");

function erl_say($msg) { echo '  ' . $msg . "\n"; }

/**
 * Run a .sql file through the ISPConfig db handle.
 * The files contain plain DDL, one or more statements separated by ';'.
 */
function erl_sql($app, $file) {
	if(!file_exists($file)) return true;
	$sql = trim(file_get_contents($file));
	if($sql === '') return true;

	foreach(preg_split('/;\s*(?:\n|$)/', $sql) as $stmt) {
		$stmt = trim($stmt);
		if($stmt === '' || strpos($stmt, '--') === 0) continue;
		$app->db->query($stmt);
	}
	return true;
}

$extension_name = 'email_ratelimit';
$action = isset($argv[1]) ? $argv[1] : '';
$ispconfig_dir = '/usr/local/ispconfig';

if(!in_array($action, array('install', 'update', 'enable', 'disable', 'uninstall'), true)) {
	fwrite(STDERR, "Usage: php install/deploy.php install|update|enable|disable|uninstall\n");
	exit(2);
}

if(posix_getuid() !== 0) {
	fwrite(STDERR, "This script must be run as root.\n");
	exit(2);
}

if(!is_dir($ispconfig_dir . '/server/lib')) {
	fwrite(STDERR, "ERROR: ISPConfig not found at $ispconfig_dir\n");
	fwrite(STDERR, "Copy the extension to $ispconfig_dir/extensions/ first.\n");
	exit(2);
}

//* ---------------------------------------------------------------- bootstrap
// Replicate the ISPConfig server-side bootstrap (see server/server.php):
// SCRIPT_PATH must point at the server/lib directory so that
// lib/config.inc.php can be found, because app.inc.php loads the database
// configuration from there. Without this the app object would have no
// database handle.
$server_lib = $ispconfig_dir . '/server/lib';

if(!is_file($server_lib . '/config.inc.php')) {
	fwrite(STDERR, "ERROR: $server_lib/config.inc.php not found.\n");
	fwrite(STDERR, "Is ISPConfig installed at $ispconfig_dir?\n");
	exit(2);
}

$_SERVER['SCRIPT_FILENAME'] = $server_lib . '/server.php';
define('SCRIPT_PATH', $server_lib);

global $app, $conf;
$conf = array();

chdir($ispconfig_dir . '/server');
require_once $server_lib . '/config.inc.php';
require_once $server_lib . '/app.inc.php';

if(!isset($app) || !is_object($app)) {
	fwrite(STDERR, "ERROR: could not initialise the ISPConfig application object.\n");
	exit(1);
}

$app->setCaller('server');

if(!is_object($app->db) || !$app->db->testConnection()) {
	fwrite(STDERR, "ERROR: cannot connect to the ISPConfig database.\n");
	fwrite(STDERR, "Check the credentials in $server_lib/config.inc.php\n");
	exit(1);
}

if(!is_object($app->dbmaster) || !$app->dbmaster->testConnection()) {
	fwrite(STDERR, "ERROR: cannot connect to the master database.\n");
	exit(1);
}

//* server_id: read from the local config when the installer already stored it,
//* otherwise from the single mail server record.
if(empty($conf['server_id'])) {
	$row = $app->db->queryOneRecord("SELECT `server_id` FROM `server` ORDER BY `server_id` LIMIT 1");
	if(!is_array($row) || empty($row['server_id'])) {
		fwrite(STDERR, "ERROR: no server record found; cannot determine server_id.\n");
		exit(1);
	}
	$conf['server_id'] = intval($row['server_id']);
}
$conf['server_id'] = intval($conf['server_id']);

echo "  server_id = {$conf['server_id']}\n";
echo "  database  = {$conf['db_database']}\n";
echo "  master db = " . ($app->running_on_masterserver() ? 'same as local' : 'separate (slave)') . "\n";

require_once $server_lib . '/classes/extension_installer_base.inc.php';
require_once __DIR__ . '/installer.php';

$classname = $extension_name . '_installer';
$installer = new $classname();

echo "\nemail_ratelimit: $action\n";
echo str_repeat('-', 60) . "\n";

switch($action) {
	case 'install':
	case 'update':
		erl_say("installer->$action()");
		if(!$installer->$action()) {
			echo "FAILED during installer->$action()\n";
			foreach($installer->messages as $m) erl_say($m);
			exit(1);
		}

		if($action === 'install') {
			erl_say('install_master.sql');
			erl_sql($app, __DIR__ . '/install_master.sql');
			erl_say('install_node.sql');
			erl_sql($app, __DIR__ . '/install_node.sql');
		}

		$version = 0;
		if(is_dir(__DIR__ . '/incremental')) {
			foreach(scandir(__DIR__ . '/incremental') as $f) {
				if(preg_match('/^upd_(\d{4})_/', $f, $m)) $version = max($version, intval($m[1]));
			}
		}
		file_put_contents($ispconfig_dir . '/extensions/' . $extension_name . '/dbversion', (string)$version);
		erl_say("schema version = $version");
		break;

	case 'enable':
		erl_say('installer->enable()');
		if(!$installer->enable()) { echo "FAILED\n"; exit(1); }
		break;

	case 'disable':
		erl_say('installer->disable()');
		if(!$installer->disable()) { echo "FAILED\n"; exit(1); }
		break;

	case 'uninstall':
		erl_say('installer->uninstall()');
		if(!$installer->uninstall()) { echo "FAILED\n"; exit(1); }
		erl_say('uninstall_master.sql');
		erl_sql($app, __DIR__ . '/uninstall_master.sql');
		erl_say('uninstall_node.sql');
		erl_sql($app, __DIR__ . '/uninstall_node.sql');
		break;
}

foreach($installer->messages as $m) erl_say($m);

echo str_repeat('-', 60) . "\n";
echo "done: $action\n\n";

if($action === 'install' || $action === 'enable') {
	echo "Next steps:\n";
	echo "  1. System > Server Config > Mail: enable 'Ratelimit' and set the limits.\n";
	echo "  2. Mail > Domain / Mail > Email Mailbox: optional per-domain / per-mailbox limits.\n";
	echo "  3. Mail > Ratelimit: optional limits for external addresses or domains.\n";
	echo "  4. Watch it work: redis-cli --scan --pattern 'erl:*'\n";
	echo "                    tail -f /var/log/rspamd/rspamd.log | grep erl\n\n";
}
