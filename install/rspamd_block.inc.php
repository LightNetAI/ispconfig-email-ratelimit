<?php

/*
 * Shared handling of the block this extension owns in
 * /etc/rspamd/rspamd.local.lua.
 *
 * Used by install/installer.php (normal install/uninstall) and by
 * install/rescue.php (standalone cleanup that must work even when the
 * extension's own PHP or the ISPConfig bootstrap is broken). Keeping one
 * implementation is what makes the rescue path trustworthy.
 */

if(!defined('ERL_RSPAMD_BEGIN')) {
	define('ERL_RSPAMD_BEGIN', '-- email_ratelimit (managed by the ISPConfig email_ratelimit extension) BEGIN');
	define('ERL_RSPAMD_END', '-- email_ratelimit END');
}

/** The include block: configuration first, then the module. */
function erl_rspamd_block() {
	return ERL_RSPAMD_BEGIN . "\n"
		. "dofile('/etc/rspamd/email_ratelimit.conf.lua')\n"
		. "dofile('/etc/rspamd/email_ratelimit.lua')\n"
		. ERL_RSPAMD_END . "\n";
}

/**
 * Remove our block (current BEGIN/END form) and the legacy single-line marker
 * form, so an install that predates the block markers is updated cleanly
 * instead of ending up with two includes.
 */
function erl_rspamd_strip($content) {
	$lines = preg_split('/\R/', $content);
	$out = array();
	$in_block = false;
	$skip_legacy_next = false;
	$legacy_marker = '-- email_ratelimit (managed by the ISPConfig email_ratelimit extension)';

	foreach($lines as $line) {
		if(strpos($line, ERL_RSPAMD_BEGIN) !== false) {
			$in_block = true;
			continue;
		}
		if($in_block) {
			if(strpos($line, ERL_RSPAMD_END) !== false) $in_block = false;
			continue;
		}
		if($skip_legacy_next) {
			$skip_legacy_next = false;
			if(trim($line) === "dofile('/etc/rspamd/email_ratelimit.conf.lua')") continue;
		}
		if(trim($line) === $legacy_marker) {
			$skip_legacy_next = true;
			continue;
		}
		$out[] = $line;
	}
	return rtrim(implode("\n", $out)) . "\n";
}

/** Write a file atomically: temp file in the same directory, then rename. */
function erl_write_atomic($path, $content, $mode = 0644) {
	$tmp = $path . '.tmp.' . getmypid();
	if(@file_put_contents($tmp, $content) === false) return false;
	@chmod($tmp, $mode);
	if(!@rename($tmp, $path)) {
		@unlink($tmp);
		return false;
	}
	return true;
}

/**
 * 0640 root:_rspamd when that group exists (the generated files contain every
 * mailbox address), 0644 otherwise so rspamd can still read them.
 */
function erl_rspamd_perms($path) {
	if(!file_exists($path)) return;
	@chmod($path, 0640);
	if(!@chgrp($path, '_rspamd')) {
		@chmod($path, 0644);
	}
}
