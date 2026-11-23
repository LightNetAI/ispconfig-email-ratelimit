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
 * Locate the MySQL/MariaDB command line client.
 */
function erl_mysql_bin() {
	foreach(array('/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mysql') as $bin) {
		if(is_executable($bin)) return $bin;
	}
	foreach(array('mariadb', 'mysql') as $name) {
		$path = trim((string)@shell_exec('command -v ' . $name . ' 2>/dev/null'));
		if($path !== '' && is_executable($path)) return $path;
	}
	return null;
}

/**
 * Credential sources to try for the DDL fallback, in order of likelihood.
 *
 * A hardened ISPConfig install can restrict the `ispconfig` DB user to
 * SELECT/INSERT/UPDATE/DELETE, which makes every ALTER and CREATE fail. This
 * script already runs as root, but on Debian/Ubuntu MySQL root is not always
 * reachable through unix_socket auth: many hosts set a root password, keep the
 * maintenance account in /etc/mysql/debian.cnf, or have /root/.my.cnf.
 */
function erl_root_candidates() {
	global $conf;

	$cands = array();

	$user = getenv('ERL_MYSQL_ROOT_USER');
	if($user === false || $user === '') $user = 'root';

	//* 1./2. the common case: the account over the local socket (unix_socket
	//  auth), first with an explicit protocol and then with whatever the client
	//  resolves by itself - a host can have more than one server/socket.
	$cands[] = array('label' => $user . ' socket auth',     'user' => $user, 'pass' => null, 'defaults' => null, 'protocol' => 'socket');
	$cands[] = array('label' => $user . ' client defaults', 'user' => $user, 'pass' => null, 'defaults' => null, 'protocol' => '');

	//* 3. a root password carried by the ISPConfig config, the environment or --root-password
	$pw = null;
	foreach(array('db_root_password', 'db_root_pass', 'mysql_root_password', 'root_password') as $key) {
		if(isset($conf[$key]) && $conf[$key] !== '') { $pw = $conf[$key]; break; }
	}
	if($pw === null && isset($conf['mysql']) && is_array($conf['mysql']) && !empty($conf['mysql']['root_password'])) {
		$pw = $conf['mysql']['root_password'];
	}
	if($pw === null && getenv('ERL_MYSQL_ROOT_PASSWORD') !== false) {
		$pw = getenv('ERL_MYSQL_ROOT_PASSWORD');
	}
	if($pw !== null && $pw !== '') {
		$cands[] = array('label' => $user . ' with password', 'user' => $user, 'pass' => $pw, 'defaults' => null, 'protocol' => 'socket');
		$cands[] = array('label' => $user . ' with password (client defaults)', 'user' => $user, 'pass' => $pw, 'defaults' => null, 'protocol' => '');
	}

	//* 4. option files that carry credentials (Debian maintenance account,
	//  an admin's own ~/.my.cnf)
	foreach(array('/etc/mysql/debian.cnf', '/root/.my.cnf') as $file) {
		if(is_readable($file) && filesize($file) > 0) {
			$cands[] = array('label' => $file, 'user' => null, 'pass' => null, 'defaults' => $file, 'protocol' => '');
		}
	}

	return $cands;
}

/**
 * Build the client command for one candidate. The password goes through
 * MYSQL_PWD so it never shows up in the process list.
 */
function erl_root_argv($bin, $cand, $dbname = '') {
	$argv = array($bin);

	$protocol = isset($cand['protocol']) ? $cand['protocol'] : 'socket';

	//* --defaults-file has to be the first option
	if($cand['defaults'] !== null) {
		$argv[] = '--defaults-file=' . $cand['defaults'];
	} else {
		if($protocol !== '') $argv[] = '--protocol=' . $protocol;
		if($cand['user'] !== null) $argv[] = '--user=' . $cand['user'];
	}

	$argv[] = '--batch';
	if($dbname !== '') $argv[] = $dbname;

	return $argv;
}

/**
 * Run one statement through one candidate. Returns true on success, fills
 * $err with the client's stderr otherwise.
 */
function erl_root_run($bin, $cand, $sql, $dbname, &$err) {
	$err = '';
	$cmd = implode(' ', array_map('escapeshellarg', erl_root_argv($bin, $cand, $dbname)));

	$env = null;
	if($cand['pass'] !== null) {
		$env = getenv();
		if(!is_array($env)) $env = array();
		$env['MYSQL_PWD'] = $cand['pass'];
	}

	$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$proc = @proc_open($cmd, $desc, $pipes, null, $env);
	if(!is_resource($proc)) { $err = 'cannot execute ' . $bin; return false; }

	fwrite($pipes[0], rtrim($sql, "; \n\t\r\0\x0B") . ";\n");
	fclose($pipes[0]);
	stream_get_contents($pipes[1]);
	$err = trim((string)stream_get_contents($pipes[2]));
	@fclose($pipes[1]);
	@fclose($pipes[2]);

	return proc_close($proc) === 0;
}

/**
 * Run DDL as the database superuser, trying each credential source once and
 * remembering the one that worked for the rest of the run.
 */
function erl_root_sql($sql, $dbname) {
	static $bin = false;
	static $chosen = null;
	static $probed = false;

	if($bin === false) $bin = erl_mysql_bin();
	if($bin === null) {
		fwrite(STDERR, "  root client: no mysql/mariadb client found in PATH\n");
		return false;
	}

	//* fast path: reuse the candidate that already worked
	if($chosen !== null) {
		$err = '';
		if(erl_root_run($bin, $chosen, $sql, $dbname, $err)) return true;
		if($err !== '') fwrite(STDERR, "  root client ({$chosen['label']}): $err\n");
		$chosen = null;
	}

	$cands = erl_root_candidates();
	$last = '';

	foreach($cands as $cand) {
		$probe_err = '';
		if(!erl_root_run($bin, $cand, 'SELECT 1', '', $probe_err)) {
			if($probe_err !== '') $last = $probe_err;
			continue;
		}

		$err = '';
		if(erl_root_run($bin, $cand, $sql, $dbname, $err)) {
			$chosen = $cand;
			if(!$probed) {
				erl_say('root client: using ' . $cand['label']);
				$probed = true;
			}
			return true;
		}

		if($err !== '') $last = $err;
		fwrite(STDERR, "  root client ({$cand['label']}): " . ($err !== '' ? $err : 'statement failed') . "\n");
		return false;
	}

	$tried = array();
	foreach($cands as $cand) $tried[] = $cand['label'];

	fwrite(STDERR, "  root client: could not authenticate as a superuser (tried: " . implode(', ', $tried) . ")\n");
	if($last !== '') fwrite(STDERR, "  root client: last error: $last\n");
	fwrite(STDERR, "  root client: tell it the superuser password and run this again:\n");
	fwrite(STDERR, "    php install/deploy.php $action --root-password='<mysql root password>'\n");
	fwrite(STDERR, "  root client: or grant the DDL rights to the panel user once, as root in mysql:\n");
	fwrite(STDERR, "    GRANT ALTER, CREATE, DROP, INDEX ON `" . $dbname . "`.* TO 'ispconfig'@'localhost'; FLUSH PRIVILEGES;\n");
	fwrite(STDERR, "  root client: (\$action is install/update/uninstall; --root-user=NAME also works)\n");

	return false;
}


/**
 * Point at the path that still works when this script cannot: a standalone
 * cleanup that needs no ISPConfig bootstrap and no database.
 */
function erl_recover_hint() {
	echo "\nThe installation is now in an unknown state. To clean it up without\n";
	echo "relying on this script or the ISPConfig bootstrap, run:\n\n";
	echo "  php " . __DIR__ . "/rescue.php          # preview\n";
	echo "  php " . __DIR__ . "/rescue.php --yes    # apply\n\n";
}

/**
 * Run a .sql file through an ISPConfig db handle.
 *
 * The files contain plain DDL, one or more statements separated by ';'.
 * Comments are stripped BEFORE splitting: splitting first would glue a leading
 * "-- ..." header to the following statement, which was then skipped as a
 * comment and the statement silently lost (that is how the email_ratelimit
 * table used to never get created).
 */
function erl_sql($db, $file) {
	if(!file_exists($file)) {
		fwrite(STDERR, "ERROR: SQL file not found: $file\n");
		return false;
	}
	$sql = file_get_contents($file);
	if($sql === false) {
		fwrite(STDERR, "ERROR: cannot read SQL file: $file\n");
		return false;
	}

	$kept = array();
	foreach(preg_split('/\R/', $sql) as $line) {
		$t = trim($line);
		if($t === '' || strpos($t, '--') === 0 || (isset($t[0]) && $t[0] === '#')) continue;
		$kept[] = $line;
	}
	$sql = trim(implode("\n", $kept));

	foreach(preg_split('/;\s*(?:\n|$)/', $sql) as $stmt) {
		$stmt = trim($stmt);
		if($stmt === '') continue;
		$res = $db->query($stmt);
		if($res === false) {
			$err = (isset($db->errorMessage) && $db->errorMessage !== '') ? ': ' . $db->errorMessage : '';
			fwrite(STDERR, "ERROR: SQL statement failed$err\n");
			fwrite(STDERR, "  " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 120) . "\n");
			return false;
		}
	}
	return true;
}

$extension_name = 'email_ratelimit';
$action = isset($argv[1]) ? $argv[1] : '';
$ispconfig_dir = '/usr/local/ispconfig';

//* Superuser credentials for the DDL fallback. The password is exported
//  (MYSQL_PWD) so it never appears in the process list or in the logs.
for($i = 2; $i < count($argv); $i++) {
	if(strpos($argv[$i], '--root-password=') === 0) {
		putenv('ERL_MYSQL_ROOT_PASSWORD=' . substr($argv[$i], 16));
	} elseif($argv[$i] === '--root-password' && isset($argv[$i + 1])) {
		putenv('ERL_MYSQL_ROOT_PASSWORD=' . $argv[++$i]);
	} elseif(strpos($argv[$i], '--root-user=') === 0) {
		putenv('ERL_MYSQL_ROOT_USER=' . substr($argv[$i], 12));
	} elseif($argv[$i] === '--root-user' && isset($argv[$i + 1])) {
		putenv('ERL_MYSQL_ROOT_USER=' . $argv[++$i]);
	}
}

if(!in_array($action, array('install', 'update', 'enable', 'disable', 'uninstall'), true)) {
	fwrite(STDERR, "Usage: php install/deploy.php install|update|enable|disable|uninstall [--root-password=PW] [--root-user=NAME]\n");
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

//* The app DB user may be denied ALTER/CREATE on a hardened server. The DDL
//  below then goes through the root client instead of failing the install.
$installer->ddl_runner = function($sql) use ($conf) {
	return erl_root_sql($sql, $conf['db_database']);
};

echo "\nemail_ratelimit: $action\n";
echo str_repeat('-', 60) . "\n";

switch($action) {
	case 'install':
	case 'update':
		erl_say("installer->$action()");
		if(!$installer->$action()) {
			echo "FAILED during installer->$action()\n";
			foreach($installer->messages as $m) erl_say($m);
			erl_recover_hint();
			exit(1);
		}

		if($action === 'install') {
			erl_say('install_master.sql (master database)');
			if(!erl_sql($app->dbmaster, __DIR__ . '/install_master.sql')) {
				erl_say('app DB user may not create tables, retrying as root');
				if(!erl_root_sql(file_get_contents(__DIR__ . '/install_master.sql'), $conf['db_database'])) {
					erl_recover_hint();
					exit(1);
				}
			}

			//* Verify the table really exists - "the deployer said OK" is not
			//  evidence, and everything downstream depends on this table.
			$row = $app->dbmaster->queryOneRecord(
				"SELECT COUNT(*) AS cnt FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_ratelimit'");
			if(!is_array($row) || empty($row['cnt'])) {
				fwrite(STDERR, "ERROR: table email_ratelimit was not created\n");
				erl_recover_hint();
				exit(1);
			}
			erl_say('table email_ratelimit created/verified');
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
		$uninstall_ok = $installer->uninstall();

		//* Drop the table even when the installer reported problems: an orphan
		//  table is worse than a partially clean uninstall, and this statement
		//  is idempotent.
		erl_say('uninstall_master.sql (master database)');
		if(!erl_sql($app->dbmaster, __DIR__ . '/uninstall_master.sql')) {
			if(!erl_root_sql(file_get_contents(__DIR__ . '/uninstall_master.sql'), $conf['db_database'])) {
				$uninstall_ok = false;
			}
		}

		if(!$uninstall_ok) {
			echo "FAILED during uninstall\n";
			foreach($installer->messages as $m) erl_say($m);
			erl_recover_hint();
			exit(1);
		}
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
