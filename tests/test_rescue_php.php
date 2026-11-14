<?php
/**
 * Unit tests for install/rescue.php - the standalone cleanup that has to work
 * when the normal uninstall cannot (broken install, broken PHP, no DB).
 *
 * Every test runs against a throw-away tree, never the real /etc or
 * /usr/local/ispconfig.
 *
 * Run: php tests/test_rescue_php.php
 */

$fail = 0;
function check($name, $cond, $detail = '') {
	global $fail;
	echo ($cond ? "  ok   " : "  FAIL ") . $name . ($cond || $detail === '' ? '' : "  [$detail]") . "\n";
	if(!$cond) $fail++;
}

$repo = dirname(__DIR__);
require $repo . '/install/rescue.php';

// ---------------------------------------------------------------- fixtures

function rrmdir($dir) {
	if(!is_dir($dir)) return;
	foreach(scandir($dir) as $f) {
		if($f === '.' || $f === '..') continue;
		$p = $dir . '/' . $f;
		if(is_link($p) || is_file($p)) @unlink($p);
		else rrmdir($p);
	}
	@rmdir($dir);
}

function fixture($root) {
	global $repo;
	rrmdir($root);
	$ispc = $root . '/usr/local/ispconfig';

	// rspamd.local.lua with admin content on both sides of our block
	@mkdir($root . '/etc/rspamd', 0777, true);
	file_put_contents($root . '/etc/rspamd/rspamd.local.lua',
		"-- admin line one\n" . erl_rspamd_block() . "-- admin line two\n");
	file_put_contents($root . '/etc/rspamd/email_ratelimit.conf.lua', "-- generated\n");
	file_put_contents($root . '/etc/rspamd/email_ratelimit.lua', "-- module\n");

	// deployed files
	foreach(array(
		'/server/lib/classes/email_ratelimit_sync.inc.php',
		'/interface/web/mail/email_ratelimit_list.php',
		'/interface/web/mail/lib/plugin.d/email_ratelimit_domain.inc.php',
	) as $rel) {
		@mkdir(dirname($ispc . $rel), 0777, true);
		file_put_contents($ispc . $rel, "<?php // ours\n");
	}

	// a theme template the admin had customised, shadow-copied by install
	@mkdir($ispc . '/interface/web/themes/default/templates/mail', 0777, true);
	file_put_contents($ispc . '/interface/web/themes/default/templates/mail/mail_domain_edit.htm', "{tmpl_var name='erl_fields'}\n");
	file_put_contents($ispc . '/interface/web/themes/default/templates/mail/mail_domain_edit.htm.email_ratelimit.bak', "{tmpl_var name='admin_original'}\n");

	// a half-extracted extension: file.list present
	@mkdir($ispc . '/extensions/email_ratelimit/install', 0777, true);
	copy($repo . '/install/file.list', $ispc . '/extensions/email_ratelimit/install/file.list');

	return array($root, $ispc);
}

$base = sys_get_temp_dir() . '/erl-rescue-test-' . getmypid();

// ---------------------------------------------------------------- dry run

list($root, $ispc) = fixture($base);
$before = file_get_contents($root . '/etc/rspamd/rspamd.local.lua');
$log = array();
$ok = erl_rescue_strip_include($root, true, $log);
check('dry run reports success', $ok === true);
check('dry run leaves rspamd.local.lua untouched', file_get_contents($root . '/etc/rspamd/rspamd.local.lua') === $before);
check('dry run logs what it would do', strpos(implode("\n", $log), '[dry]') !== false);

// ---------------------------------------------------------------- apply

$log = array();
$ok = erl_rescue_strip_include($root, false, $log);
$after = file_get_contents($root . '/etc/rspamd/rspamd.local.lua');
check('include block removed', strpos($after, 'email_ratelimit') === false);
check('admin line before block preserved', strpos($after, '-- admin line one') !== false);
check('admin line after block preserved', strpos($after, '-- admin line two') !== false);
check('no dofile of the extension left', strpos($after, 'dofile') === false);

$log = array();
check('generated config deleted', erl_rescue_remove_generated($root, false, $log) === true
	&& !file_exists($root . '/etc/rspamd/email_ratelimit.conf.lua'));
check('generated module deleted', !file_exists($root . '/etc/rspamd/email_ratelimit.lua'));

// ---------------------------------------------------------------- deployed files

$log = array();
$ok = erl_rescue_remove_deployed($ispc, false, $log);
check('deployed file removed', !file_exists($ispc . '/interface/web/mail/email_ratelimit_list.php'));
check('deployed plugin removed', !file_exists($ispc . '/interface/web/mail/lib/plugin.d/email_ratelimit_domain.inc.php'));
check('customised theme template restored from backup', is_file($ispc . '/interface/web/themes/default/templates/mail/mail_domain_edit.htm')
	&& strpos(file_get_contents($ispc . '/interface/web/themes/default/templates/mail/mail_domain_edit.htm'), 'admin_original') !== false);
check('backup consumed, not left behind', !file_exists($ispc . '/interface/web/themes/default/templates/mail/mail_domain_edit.htm.email_ratelimit.bak'));
check('deployed removal reports success', $ok === true, implode('; ', $log));

// ---------------------------------------------------------------- idempotency

$log = array();
$again = erl_rescue_strip_include($root, false, $log)
	&& erl_rescue_remove_generated($root, false, $log)
	&& erl_rescue_remove_deployed($ispc, false, $log);
check('second run is a clean no-op', $again === true, implode('; ', $log));
check('second run adds no error', strpos(implode("\n", $log), 'ERROR') === false);

// ---------------------------------------------------------------- file.list parsing

$targets = erl_rescue_targets($ispc);
check('targets read from file.list', in_array('interface/web/mail/email_ratelimit_list.php', $targets, true));
check('directory entries skipped', !in_array('interface/web/mail/lib/plugin.d', $targets, true));
check('no parent-dir traversal targets', count(array_filter($targets, function($t) { return strpos($t, '..') !== false; })) === 0);

// missing file.list falls back to the built-in manifest
$builtin = erl_rescue_builtin_targets();
check('built-in manifest covers the server module', in_array('server/lua/email_ratelimit.lua', $builtin, true));
check('built-in manifest covers the theme override',
	in_array('interface/web/themes/default/templates/mail/mail_user_mailbox_edit.htm', $builtin, true));

// ---------------------------------------------------------------- abort path

list($root, $ispc) = fixture($base);
chmod($root . '/etc/rspamd/rspamd.local.lua', 0000);
$log = array();
$ok = erl_rescue_strip_include($root, false, $log);
$unreadable = ($ok === false);
chmod($root . '/etc/rspamd/rspamd.local.lua', 0644);
check('unreadable local.lua returns false (caller must abort)', $unreadable === true, implode('; ', $log));

// ---------------------------------------------------------------- missing database

list($root, $ispc) = fixture($base);
$log = array();
$ok = erl_rescue_db($ispc, false, $log);
check('no config.inc.php is not fatal', $ok === true);
check('no config.inc.php is explained', strpos(implode("\n", $log), 'skipping database cleanup') !== false);

// ---------------------------------------------------------------- cli guard

$out = shell_exec('php ' . escapeshellarg($repo . '/install/rescue.php') . ' --root=' . escapeshellarg($base) . ' 2>&1');
if(function_exists('posix_getuid') && posix_getuid() === 0) {
	check('cli defaults to a dry run', strpos($out, 'DRY RUN') !== false, (string)$out);
	check('cli prints the preview banner', strpos($out, 'email_ratelimit rescue') !== false);
} else {
	//* the suite normally runs unprivileged; assert the guard instead
	check('cli refuses to run unprivileged', strpos($out, 'must be run as root') !== false, (string)$out);
}

rrmdir($base);

echo "\n";
echo ($fail === 0 ? "ALL PASSED" : "$fail FAILED") . "\n";
exit($fail === 0 ? 0 : 1);
