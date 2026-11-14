<?php
/**
 * Unit tests for the parts of the extension that run before a server exists:
 * the SQL loader and the rspamd.local.lua block handling.
 *
 * Run: php tests/test_installer_php.php
 */

$fail = 0;
function check($name, $cond, $detail = '') {
	global $fail;
	echo ($cond ? "  ok   " : "  FAIL ") . $name . ($cond || $detail === '' ? '' : "  [$detail]") . "\n";
	if(!$cond) $fail++;
}

$repo = dirname(__DIR__);

// ---------------------------------------------------------------- SQL loader
$src = file_get_contents($repo . '/install/deploy.php');
if(!preg_match('/function erl_sql\(.*?\n\}\n/s', $src, $m)) {
	echo "FATAL: cannot extract erl_sql()\n"; exit(1);
}
eval($m[0]);

class FakeDb {
	public $errorMessage = '';
	public $seen = array();
	public $failOn = '';
	function query($sql) {
		$this->seen[] = $sql;
		if($this->failOn !== '' && strpos($sql, $this->failOn) !== false) {
			$this->errorMessage = 'simulated failure';
			return false;
		}
		return new stdClass;
	}
}

echo "SQL loader\n";
$db = new FakeDb;
$ok = erl_sql($db, $repo . '/install/install_master.sql');
check('install_master.sql returns true', $ok === true);
check('exactly one statement executed', count($db->seen) === 1, 'got ' . count($db->seen));
check('statement is the CREATE TABLE', isset($db->seen[0]) && strpos($db->seen[0], 'CREATE TABLE') === 0,
	isset($db->seen[0]) ? substr($db->seen[0], 0, 40) : 'none');
check('table name present', isset($db->seen[0]) && strpos($db->seen[0], '`email_ratelimit`') !== false);
check('no leading comment leaks into SQL', isset($db->seen[0]) && strpos($db->seen[0], '--') === false);

$tmp = tempnam(sys_get_temp_dir(), 'erl');
file_put_contents($tmp, "-- header comment\n-- second line\nCREATE TABLE a (id int);\n-- middle\nINSERT INTO a VALUES (1);\n");
$db2 = new FakeDb;
check('multi-statement file with comments', erl_sql($db2, $tmp) === true && count($db2->seen) === 2, 'got ' . count($db2->seen));
unlink($tmp);

$db3 = new FakeDb;
check('missing file fails loudly', erl_sql($db3, '/nonexistent.sql') === false && count($db3->seen) === 0);

$db4 = new FakeDb;
$db4->failOn = 'INSERT';
$tmp2 = tempnam(sys_get_temp_dir(), 'erl');
file_put_contents($tmp2, "CREATE TABLE b (id int);\nINSERT INTO b VALUES (2);\n");
check('failing statement aborts', erl_sql($db4, $tmp2) === false);
unlink($tmp2);

// ------------------------------------------------------- rspamd.lua block
echo "\nrspamd.local.lua block\n";

// minimal stand-in for the core base class
class extension_installer_base {
	protected $extension_basedir = '/usr/local/ispconfig/extensions';
	protected $ispconfig_dir = '/usr/local/ispconfig';
}
require $repo . '/install/installer.php';

$rc = new ReflectionClass('email_ratelimit_installer');
$inst = $rc->newInstance();
$block_m = $rc->getMethod('rspamd_block');
$block_m->setAccessible(true);
$strip_m = $rc->getMethod('strip_rspamd_block');
$strip_m->setAccessible(true);
$block = $block_m->invoke($inst);

check('block loads config then module', strpos($block, "dofile('/etc/rspamd/email_ratelimit.conf.lua')") !== false
	&& strpos($block, "dofile('/etc/rspamd/email_ratelimit.lua')") !== false);
check('config is loaded before module',
	strpos($block, 'conf.lua') < strpos($block, "email_ratelimit.lua'"));
check('block is delimited', strpos($block, 'BEGIN') !== false && strpos($block, '-- email_ratelimit END') !== false);

$admin = "-- my own rules\nlocal x = 1\n";
$with = $admin . $block;
check('admin content preserved', trim($strip_m->invoke($inst, $with)) === trim($admin),
	json_encode($strip_m->invoke($inst, $with)));

$legacy = $admin . "-- email_ratelimit (managed by the ISPConfig email_ratelimit extension)\n"
	. "dofile('/etc/rspamd/email_ratelimit.conf.lua')\n";
check('legacy marker + dofile removed', trim($strip_m->invoke($inst, $legacy)) === trim($admin));

$dupe = $admin . $block . $block;
check('duplicate blocks both removed', trim($strip_m->invoke($inst, $dupe)) === trim($admin));

// idempotency: apply the same transform that register_rspamd_include() applies
$once = rtrim($strip_m->invoke($inst, $admin)) . "\n\n" . $block;
$twice = rtrim($strip_m->invoke($inst, $once)) . "\n\n" . $block;
check('register is idempotent', $once === $twice);

$only_legacy_tail = $admin . $block . "local y = 2\n";
check('content after block preserved', strpos($strip_m->invoke($inst, $only_legacy_tail), 'local y = 2') !== false);


// ---------------------------------------------------------------- uninstall idempotency

echo "\nuninstall idempotency\n";

/**
 * Minimal information_schema stand-in: table_exists() and column_exists() each
 * run one queryOneRecord(), so the fake only has to answer those two shapes.
 */
class MetaDb {
	public $tables = array();
	public $columns = array();   // 'table.column' => true
	public $failAlter = false;
	public $errorMessage = '';

	function queryOneRecord($sql, $table = null, $column = null) {
		if(strpos($sql, 'information_schema.TABLES') !== false) {
			return array('cnt' => in_array($table, $this->tables, true) ? 1 : 0);
		}
		if(strpos($sql, 'information_schema.COLUMNS') !== false) {
			return array('cnt' => isset($this->columns[$table . '.' . $column]) ? 1 : 0);
		}
		return array('cnt' => 0);
	}

	function query($sql) {
		if($this->failAlter) {
			$this->errorMessage = 'denied';
			return false;
		}
		return new stdClass;
	}
}

$db = new MetaDb;
$db->tables = array('mail_user');
$db->columns = array('mail_user.erl_limit_mode' => true);

$drop_m = $rc->getMethod('drop_column');
$drop_m->setAccessible(true);

check('dropping a column that is gone is success', $drop_m->invoke($inst, $db, 'mail_user', 'erl_out_limit') === true);
check('dropping from a missing table is success', $drop_m->invoke($inst, $db, 'no_such_table', 'erl_x') === true);

$db2 = new MetaDb;
$db2->tables = array('mail_user');
$db2->columns = array('mail_user.erl_limit_mode' => true);
$db2->failAlter = true;
check('a failed DROP is still reported as failure', $drop_m->invoke($inst, $db2, 'mail_user', 'erl_limit_mode') === false);

echo "\n" . ($fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
