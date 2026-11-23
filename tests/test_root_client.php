<?php

/*
 * Hermetic tests for install/root_client.inc.php.
 *
 * No database and no ISPConfig needed: the credential parsing and the command
 * construction are checked directly, and a fake client verifies that the
 * password reaches the process through the environment, never through argv.
 */

$inc = __DIR__ . '/../install/root_client.inc.php';
if(!is_file($inc)) {
	fwrite(STDERR, "missing $inc\n");
	exit(1);
}
require_once $inc;

$pass = 0;
$fail = 0;

function ok($cond, $what) {
	global $pass, $fail;
	if($cond) { $pass++; echo "  ok   $what\n"; }
	else { $fail++; echo "  FAIL $what\n"; }
}

function tmpdir() {
	$dir = sys_get_temp_dir() . '/erl-root-client-' . getmypid() . '-' . mt_rand();
	mkdir($dir, 0700, true);
	return $dir;
}

$dir = tmpdir();

//* ------------------------------------------------------------ clientdb.conf

$conf_file = $dir . '/mysql_clientdb.conf';
file_put_contents($conf_file, "<?php\n\n\$clientdb_host\t\t\t= 'localhost';\n\$clientdb_user\t\t\t= 'root';\n\$clientdb_password\t\t= 's3cr3t-pw';\n\n?>\n");

$parsed = erl_clientdb_conf($conf_file);
ok(is_array($parsed), 'clientdb: parses a normal file');
ok($parsed['host'] === 'localhost', 'clientdb: host');
ok($parsed['user'] === 'root', 'clientdb: user');
ok($parsed['password'] === 's3cr3t-pw', 'clientdb: password');

ok(erl_clientdb_conf($dir . '/nope.conf') === null, 'clientdb: missing file is ignored');

$no_user = $dir . '/no_user.conf';
file_put_contents($no_user, "<?php\n\$clientdb_host = 'localhost';\n\$clientdb_password = 'x';\n");
$p3 = erl_clientdb_conf($no_user);
ok(is_array($p3) && $p3['user'] === 'root', 'clientdb: user defaults to root');

$no_host = $dir . '/no_host.conf';
file_put_contents($no_host, "<?php\n\$clientdb_user = 'admin';\n\$clientdb_password = 'y';\n");
$p2 = erl_clientdb_conf($no_host);
ok(is_array($p2) && $p2['host'] === '' && $p2['user'] === 'admin', 'clientdb: host defaults to the socket');

//* ERL_CLIENTDB_CONF is honoured, so the candidates list can be driven by tests
putenv('ERL_CLIENTDB_CONF=' . $conf_file);

//* ------------------------------------------------------------- argv shaping

$socket = array('label' => 'x', 'user' => 'root', 'pass' => null, 'defaults' => null, 'protocol' => 'socket', 'host' => '');
$argv = erl_root_argv('/usr/bin/mysql', $socket, 'dbispconfig');
ok(in_array('--protocol=socket', $argv, true), 'argv: socket candidate uses the socket');
ok(in_array('--user=root', $argv, true), 'argv: user is passed');
ok(!preg_grep('/^--password/', $argv), 'argv: no password on the command line');
ok(end($argv) === 'dbispconfig', 'argv: database is last');

$pw = array('label' => 'x', 'user' => 'root', 'pass' => 's3cr3t-pw', 'defaults' => null, 'protocol' => 'socket', 'host' => '');
$argv = erl_root_argv('/usr/bin/mysql', $pw, 'dbispconfig');
ok(!in_array('s3cr3t-pw', $argv, true), 'argv: password never in argv');
ok(!preg_grep('/^--password/', $argv), 'argv: password goes through the environment');

$file = array('label' => 'x', 'user' => null, 'pass' => null, 'defaults' => '/etc/mysql/debian.cnf', 'protocol' => '', 'host' => '');
$argv = erl_root_argv('/usr/bin/mysql', $file, 'dbispconfig');
ok($argv[1] === '--defaults-file=/etc/mysql/debian.cnf', 'argv: --defaults-file comes first');

$remote = array('label' => 'x', 'user' => 'root', 'pass' => 'p', 'defaults' => null, 'protocol' => 'tcp', 'host' => '10.0.0.5');
$argv = erl_root_argv('/usr/bin/mysql', $remote, 'dbispconfig');
ok(in_array('--host=10.0.0.5', $argv, true), 'argv: remote host is passed');
ok(in_array('--protocol=tcp', $argv, true), 'argv: remote host uses tcp');

//* ------------------------------------------------- candidates ordering

$labels = array();
foreach(erl_root_candidates() as $c) $labels[] = $c['label'];

$found = false;
foreach($labels as $l) if(strpos($l, 'mysql_clientdb.conf') === 0) $found = true;
ok($found, 'candidates: mysql_clientdb.conf is used');

ok(strpos($labels[0], 'socket auth') !== false, 'candidates: socket auth is tried first');

$clientdb_pos = null;
foreach($labels as $i => $l) if(strpos($l, 'mysql_clientdb.conf') === 0) $clientdb_pos = $i;
ok($clientdb_pos !== null && $clientdb_pos >= 2, 'candidates: clientdb is tried after the unauthenticated socket attempts');

$option_pos = null;
foreach($labels as $i => $l) if(strpos($l, '/etc/mysql/debian.cnf') === 0 || strpos($l, '/root/.my.cnf') === 0) { $option_pos = $i; break; }
ok($option_pos === null || $clientdb_pos < $option_pos, 'candidates: clientdb comes before the option files');

//* ------------------------------------------- password reaches the child via env

$fake = $dir . '/fake-mysql';
$out  = $dir . '/fake-mysql.out';
file_put_contents($fake, "#!/bin/sh\necho \"ARGV: \$@\" > " . escapeshellarg($out) . "\necho \"PWD: \${MYSQL_PWD:-missing}\" >> " . escapeshellarg($out) . "\ncat > /dev/null\nexit 0\n");
chmod($fake, 0755);

$cand = array('label' => 'fake', 'user' => 'root', 'pass' => 'top-secret', 'defaults' => null, 'protocol' => 'socket', 'host' => '');
$err = '';
ok(erl_root_run($fake, $cand, 'SELECT 1', 'dbispconfig', $err) === true, 'run: fake client succeeds');

$said = (string)@file_get_contents($out);
ok(strpos($said, 'PWD: top-secret') !== false, 'run: password is exported as MYSQL_PWD');
ok(strpos($said, 'top-secret') !== false && strpos($said, 'ARGV: --protocol=socket --user=root --batch dbispconfig') !== false, 'run: argv carries no password');

//* ------------------------------------------------------------- cleanup

foreach(array($conf_file, $no_user, $no_host, $fake, $out, $dir . '/argv.txt') as $f) @unlink($f);
@rmdir($dir);

echo "\n" . ($fail === 0 ? "ALL PASSED" : "$fail FAILED") . " ($pass assertions)\n";
exit($fail === 0 ? 0 : 1);
