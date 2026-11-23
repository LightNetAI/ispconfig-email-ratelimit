<?php

/**
 * MySQL superuser client helper, shared by deploy.php and rescue.php.
 *
 * ISPConfig sometimes runs with a panel DB user that is restricted to DML, so
 * ALTER/CREATE have to go through a superuser. The core installer knows the
 * password, but this extension has to find it; the candidates are tried in
 * order and the first one that connects is remembered for the rest of the run.
 *
 * Passwords are passed through MYSQL_PWD, never on the command line, so they do
 * not show up in the process list or in a log.
 */

if(!function_exists('erl_root_say')) {
	function erl_root_say($msg) {
		if(function_exists('erl_say')) {
			erl_say($msg);
		} else {
			echo '  ' . $msg . "\n";
		}
	}
}

/**
 * Locate the MySQL/MariaDB command line client.
 */
if(!function_exists('erl_mysql_bin')) {
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
}

/**
 * ISPConfig keeps the MySQL root credentials in server/lib/mysql_clientdb.conf.
 * It is a small PHP file, but it is parsed instead of included so that a broken
 * or hostile file cannot execute code in the installer.
 *
 * The path can be overridden with ERL_CLIENTDB_CONF (tests, unusual layouts).
 */
if(!function_exists('erl_clientdb_conf')) {
	function erl_clientdb_conf($file = null) {
		if($file === null) {
			$file = getenv('ERL_CLIENTDB_CONF');
			if($file === false || $file === '') {
				$file = '/usr/local/ispconfig/server/lib/mysql_clientdb.conf';
			}
		}

		if(!is_readable($file)) return null;

		$txt = @file_get_contents($file);
		if($txt === false || $txt === '') return null;

		$out = array('host' => '', 'user' => 'root', 'password' => '');
		$map = array('host' => 'host', 'user' => 'user', 'password' => 'password');

		foreach($map as $key => $var) {
			if(preg_match('/\$clientdb_' . $var . '\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $txt, $m)) {
				$out[$key] = $m[1];
			}
		}

		if($out['user'] === '') return null;

		return $out;
	}
}

/**
 * Credential sources to try for the DDL fallback, in order of likelihood.
 */
if(!function_exists('erl_root_candidates')) {
	function erl_root_candidates() {
		global $conf;

		$cands = array();

		$user = getenv('ERL_MYSQL_ROOT_USER');
		if($user === false || $user === '') $user = 'root';

		//* 1./2. the account over the local socket (unix_socket auth), first
		//  with an explicit protocol and then with whatever the client resolves
		//  by itself - a host can have more than one server/socket.
		$cands[] = array('label' => $user . ' socket auth',     'user' => $user, 'pass' => null, 'defaults' => null, 'protocol' => 'socket', 'host' => '');
		$cands[] = array('label' => $user . ' client defaults', 'user' => $user, 'pass' => null, 'defaults' => null, 'protocol' => '',       'host' => '');

		//* 3. the credentials ISPConfig itself stores for the database
		$clientdb = erl_clientdb_conf();
		if(is_array($clientdb)) {
			$host = $clientdb['host'];
			$local = ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1');
			$cands[] = array(
				'label'    => 'mysql_clientdb.conf (' . $clientdb['user'] . ')',
				'user'     => $clientdb['user'],
				'pass'     => $clientdb['password'],
				'defaults' => null,
				'protocol' => $local ? 'socket' : 'tcp',
				'host'     => $local ? '' : $host,
			);
		}

		//* 4. a root password carried by the ISPConfig config, the environment
		//  or --root-password
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
			$cands[] = array('label' => $user . ' with password', 'user' => $user, 'pass' => $pw, 'defaults' => null, 'protocol' => 'socket', 'host' => '');
			$cands[] = array('label' => $user . ' with password (client defaults)', 'user' => $user, 'pass' => $pw, 'defaults' => null, 'protocol' => '', 'host' => '');
		}

		//* 5. option files that carry credentials (Debian maintenance account,
		//  an admin's own ~/.my.cnf)
		foreach(array('/etc/mysql/debian.cnf', '/root/.my.cnf') as $file) {
			if(is_readable($file) && filesize($file) > 0) {
				$cands[] = array('label' => $file, 'user' => null, 'pass' => null, 'defaults' => $file, 'protocol' => '', 'host' => '');
			}
		}

		return $cands;
	}
}

/**
 * Build the client command for one candidate.
 */
if(!function_exists('erl_root_argv')) {
	function erl_root_argv($bin, $cand, $dbname = '') {
		$argv = array($bin);

		$protocol = isset($cand['protocol']) ? $cand['protocol'] : 'socket';
		$host     = isset($cand['host']) ? $cand['host'] : '';

		//* --defaults-file has to be the first option
		if(isset($cand['defaults']) && $cand['defaults'] !== null) {
			$argv[] = '--defaults-file=' . $cand['defaults'];
		} else {
			if($protocol !== '') $argv[] = '--protocol=' . $protocol;
			if($host !== '') $argv[] = '--host=' . $host;
			if(isset($cand['user']) && $cand['user'] !== null) $argv[] = '--user=' . $cand['user'];
		}

		$argv[] = '--batch';
		if($dbname !== '') $argv[] = $dbname;

		return $argv;
	}
}

/**
 * Run one statement through one candidate. Fills $err with the client's stderr.
 */
if(!function_exists('erl_root_run')) {
	function erl_root_run($bin, $cand, $sql, $dbname, &$err) {
		$err = '';
		$cmd = implode(' ', array_map('escapeshellarg', erl_root_argv($bin, $cand, $dbname)));

		$env = null;
		if(isset($cand['pass']) && $cand['pass'] !== null) {
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
}

/**
 * Run DDL as the database superuser, trying each credential source once and
 * remembering the one that worked for the rest of the run.
 */
if(!function_exists('erl_root_sql')) {
	function erl_root_sql($sql, $dbname) {
		static $bin = false;
		static $chosen = null;
		static $announced = false;

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
				if(!$announced) {
					erl_root_say('root client: using ' . $cand['label']);
					$announced = true;
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
		fwrite(STDERR, "    php install/deploy.php install --root-password='<mysql root password>'\n");
		fwrite(STDERR, "  root client: or grant the DDL rights to the panel user once, as root in mysql:\n");
		fwrite(STDERR, "    GRANT ALTER, CREATE, DROP, INDEX ON `" . $dbname . "`.* TO 'ispconfig'@'localhost'; FLUSH PRIVILEGES;\n");
		fwrite(STDERR, "  root client: --root-user=NAME also works, e.g. for a dedicated admin account.\n");

		return false;
	}
}
