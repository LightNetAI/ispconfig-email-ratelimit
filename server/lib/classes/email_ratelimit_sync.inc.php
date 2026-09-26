<?php

/*
 * email_ratelimit_sync - resolves the effective rate limits from the ISPConfig
 * database and generates the Rspamd side configuration.
 *
 * Called by:
 *   - the installer (enable/disable/uninstall)
 *   - the server plugin email_ratelimit_plugin.inc.php on every relevant change
 *   - the cron job 100-email_ratelimit.inc.php as a safety net
 *
 * Generated artefacts:
 *   /etc/rspamd/email_ratelimit.conf.lua   the configuration table read by
 *                                          server/plugins-available/email_ratelimit.lua
 *   /etc/rspamd/email_ratelimit.lua        the enforcement module itself (copied)
 */

class email_ratelimit_sync {

	/** Path of the generated configuration (dofile()d from rspamd.local.lua) */
	const CONF_FILE = '/etc/rspamd/email_ratelimit.conf.lua';

	/** Path of the enforcement module */
	const MODULE_FILE = '/etc/rspamd/email_ratelimit.lua';

	/** Where the extension keeps its own copy of the module */
	const MODULE_SOURCE = '/usr/local/ispconfig/extensions/email_ratelimit/server/lua/email_ratelimit.lua';

	private $module = 'email_ratelimit';

	/** Set to a message when something went wrong, for logging by the caller */
	public $error = '';

	/** @var array limits valid intervals, in seconds */
	private $interval_map = array(
		'minute'   => 60,
		'5min'     => 300,
		'15min'    => 900,
		'hour'     => 3600,
		'6hour'    => 21600,
		'day'      => 86400,
	);

	//* ------------------------------------------------------------------ public

	/**
	 * Resolve the current settings and write both generated files.
	 * Returns true on success, false when the configuration could not be
	 * read/written (in which case rspamd keeps its previous behaviour).
	 */
	public function sync() {
		global $app;

		$config = $this->build_config();
		if($config === false) {
			return false;
		}

		if(!$this->write_conf($config)) {
			return false;
		}
		$this->copy_module();

		return true;
	}

	/** Used by the installer's disable(): keep files, stop enforcement. */
	public function write_disabled() {
		$config = array(
			'enabled'  => false,
			'interval' => 3600,
			'defaults' => array(
				'out' => array('enabled' => false, 'limit' => 0, 'interval' => 3600),
				'in'  => array('enabled' => false, 'limit' => 0, 'interval' => 3600),
			),
			'users'    => array(),
			'extern'   => array(),
		);
		return $this->write_conf($config);
	}

	/** Used by the installer's uninstall(). */
	public function remove_generated() {
		if(file_exists(self::CONF_FILE)) @unlink(self::CONF_FILE);
		if(file_exists(self::MODULE_FILE)) @unlink(self::MODULE_FILE);
		$this->restart_rspamd();
		return true;
	}

	//* ----------------------------------------------------------------- resolve

	/**
	 * Effective server-wide configuration for one mail server.
	 * Resolution order for a mailbox:
	 *   1. server wide: erl_enabled must be 'y', otherwise nothing is limited
	 *   2. per-mailbox: mode custom -> own values
	 *                   mode unlimited -> not limited
	 *                   mode domain -> the domain's policy
	 *   3. per-domain:  mode custom -> own values
	 *                   mode unlimited -> not limited
	 *                   mode global -> the server-wide defaults
	 */
	public function build_config() {
		global $app, $conf;

		$db = $this->db();
		if($db === null) {
			$this->error = 'no database handle';
			return false;
		}

		$server_id = isset($conf['server_id']) ? intval($conf['server_id']) : 0;
		if($server_id <= 0) {
			$this->error = 'unknown server_id';
			return false;
		}

		$server = $db->queryOneRecord(
			"SELECT * FROM `server` WHERE `server_id` = ?", $server_id);
		if(!is_array($server)) {
			$this->error = 'server record not found: ' . $server_id;
			return false;
		}

		$enabled = (isset($server['erl_enabled']) && $server['erl_enabled'] == 'y');

		$out_limit    = isset($server['erl_out_limit'])    ? intval($server['erl_out_limit'])    : 0;
		$out_interval = isset($server['erl_out_interval']) ? intval($server['erl_out_interval']) : 3600;
		$in_limit     = isset($server['erl_in_limit'])     ? intval($server['erl_in_limit'])     : 0;
		$in_interval  = isset($server['erl_in_interval'])  ? intval($server['erl_in_interval'])  : 3600;
		$default_user = isset($server['erl_default_user_out']) ? intval($server['erl_default_user_out']) : 0;

		//* A limit of 0 (or an empty value) means "this direction is unlimited".
		$defaults = array(
			'out' => array(
				'enabled'  => ($out_limit > 0),
				'limit'    => max(0, $out_limit),
				'interval' => $this->sanitize_interval($out_interval),
			),
			'in'  => array(
				'enabled'  => ($in_limit > 0),
				'limit'    => max(0, $in_limit),
				'interval' => $this->sanitize_interval($in_interval),
			),
		);

		if(!$enabled) {
			return array(
				'enabled'  => false,
				'interval' => 3600,
				'defaults' => array(
					'out' => array('enabled' => false, 'limit' => 0, 'interval' => 3600),
					'in'  => array('enabled' => false, 'limit' => 0, 'interval' => 3600),
				),
				'users'    => array(),
				'extern'   => array(),
			);
		}

		//* ---- domains -------------------------------------------------------
		$domains = array();
		$rows = $db->queryAllRecords(
			"SELECT `domain_id`, `domain`, `erl_limit_mode`,
			        `erl_out_limit`, `erl_out_interval`, `erl_in_limit`, `erl_in_interval`
			 FROM `mail_domain` WHERE `server_id` = ?", $server_id);
		if(is_array($rows)) {
			foreach($rows as $r) {
				$domains[intval($r['domain_id'])] = $r;
			}
		}

		//* ---- mailboxes ------------------------------------------------------
		$users = array();
		$mailboxes = $db->queryAllRecords(
			"SELECT `mailuser_id`, `email`, `domain_id`, `erl_limit_mode`,
			        `erl_out_limit`, `erl_out_interval`, `erl_in_limit`, `erl_in_interval`
			 FROM `mail_user`
			 WHERE `server_id` = ? AND `email` <> ''", $server_id);

		if(is_array($mailboxes)) {
			foreach($mailboxes as $m) {
				$email = strtolower(trim($m['email']));
				if($email === '') continue;

				$domain_id = intval($m['domain_id']);
				$domain = isset($domains[$domain_id]) ? $domains[$domain_id] : null;

				// effective defaults for this mailbox, before its own override
				$base_out = $defaults['out'];
				$base_in  = $defaults['in'];

				if($domain !== null) {
					$dmode = isset($domain['erl_limit_mode']) ? $domain['erl_limit_mode'] : 'global';
					if($dmode === 'unlimited') {
						$base_out = array('enabled' => false, 'limit' => 0, 'interval' => $base_out['interval']);
						$base_in  = array('enabled' => false, 'limit' => 0, 'interval' => $base_in['interval']);
					} elseif($dmode === 'custom') {
						$base_out = $this->dir_from_row($domain, 'out', $base_out);
						$base_in  = $this->dir_from_row($domain, 'in', $base_in);
					}
					// 'global' -> keep the server defaults
				}

				$mmode = isset($m['erl_limit_mode']) ? $m['erl_limit_mode'] : 'domain';
				if($mmode === 'unlimited') {
					$eff_out = array('enabled' => false, 'limit' => 0, 'interval' => $base_out['interval']);
					$eff_in  = array('enabled' => false, 'limit' => 0, 'interval' => $base_in['interval']);
				} elseif($mmode === 'custom') {
					$eff_out = $this->dir_from_row($m, 'out', $base_out);
					$eff_in  = $this->dir_from_row($m, 'in', $base_in);
				} else { // 'domain'
					$eff_out = $base_out;
					$eff_in  = $base_in;
				}

				// the server-wide "default per mailbox" acts as an upper bound for
				// mailboxes that inherit, so an admin can cap everyone at once
				if($default_user > 0 && $mmode !== 'custom') {
					if($eff_out['enabled'] && ($eff_out['limit'] === 0 || $eff_out['limit'] > $default_user)) {
						$eff_out['limit'] = $default_user;
					}
				}

				// only emit an entry when it differs from the server default.
				// The *_on flags are explicit so the Lua side can tell
				// "no override for this mailbox" (absent/fall back to the
				// default) apart from "explicitly unlimited" (present, on=false).
				if($this->differs($eff_out, $defaults['out']) || $this->differs($eff_in, $defaults['in'])) {
					$users[$email] = array(
						'out_on'       => $eff_out['enabled'],
						'out'          => $eff_out['enabled'] ? $eff_out['limit'] : 0,
						'out_interval' => $eff_out['interval'],
						'in_on'        => $eff_in['enabled'],
						'in'           => $eff_in['enabled'] ? $eff_in['limit'] : 0,
						'in_interval'  => $eff_in['interval'],
					);
				}
			}
		}

		//* ---- explicit external limits --------------------------------------
		//* read through dbmaster: on a slave this table only exists on the master
		$extern = array();
		$master_db = $this->dbmaster();
		if($master_db !== null) {
			$rows = $master_db->queryAllRecords(
				"SELECT `source`, `type`, `interval`, `limit`
				 FROM `email_ratelimit`
				 WHERE `server_id` = ? AND `active` = 'y' AND `limit` > 0", $server_id);
			if(is_array($rows)) {
				foreach($rows as $r) {
					$src = strtolower(trim($r['source']));
					if($src === '') continue;
					$type = $r['type'];
					if($type !== 'in' && $type !== 'out' && $type !== 'both') $type = 'out';
					$extern[] = array(
						'source'   => $src,
						'type'     => $type,
						'limit'    => max(0, intval($r['limit'])),
						'interval' => $this->sanitize_interval(intval($r['interval'])),
					);
				}
			}
		}

		return array(
			'enabled'  => true,
			'interval' => 3600,
			'defaults' => array(
				'out' => $defaults['out'],
				'in'  => $defaults['in'],
			),
			'users'    => $users,
			'extern'   => $extern,
		);
	}

	//* ------------------------------------------------------------------ helper

	private function db() {
		global $app;
		if(isset($app->db) && is_object($app->db)) return $app->db;
		if(isset($app->dbmaster) && is_object($app->dbmaster)) return $app->dbmaster;
		return null;
	}

	/**
	 * Handle for master-scope tables.
	 *
	 * mail_domain / mail_user / server live in the server's own database
	 * (ISPConfig replicates them), but `email_ratelimit` is created by
	 * install_master.sql and only exists in the master database. On a slave the
	 * local database has no such table, so it must be read through dbmaster -
	 * on a single server or a master, dbmaster === db.
	 */
	private function dbmaster() {
		global $app;
		if(isset($app->dbmaster) && is_object($app->dbmaster)) return $app->dbmaster;
		return $this->db();
	}

	private function differs($a, $b) {
		return ($a['enabled'] != $b['enabled']) || ($a['limit'] != $b['limit'])
			|| ($a['interval'] != $b['interval']);
	}

	/** Build one direction's settings from a mail_domain / mail_user row. */
	private function dir_from_row($row, $dir, $fallback) {
		$limit    = isset($row['erl_' . $dir . '_limit'])    ? intval($row['erl_' . $dir . '_limit'])    : 0;
		$interval = isset($row['erl_' . $dir . '_interval']) ? intval($row['erl_' . $dir . '_interval']) : $fallback['interval'];
		if($limit <= 0) {
			return array('enabled' => false, 'limit' => 0, 'interval' => $this->sanitize_interval($interval));
		}
		return array(
			'enabled'  => true,
			'limit'    => $limit,
			'interval' => $this->sanitize_interval($interval),
		);
	}

	/** Keep only sane intervals. */
	private function sanitize_interval($seconds) {
		$seconds = intval($seconds);
		if(!in_array($seconds, $this->interval_map, true)) {
			return 3600;
		}
		return $seconds;
	}

	//* ------------------------------------------------------------------- write

	/**
	 * Render and install the generated Lua configuration.
	 * Writes to a temporary file and renames it into place, so rspamd never
	 * sees a half written file.
	 */
	private function write_conf($config) {
		global $app;

		$lua = $this->render_config($config);
		if($lua === false) return false;

		$tmp = self::CONF_FILE . '.tmp';
		if(file_put_contents($tmp, $lua) === false) {
			$this->error = 'cannot write ' . $tmp;
			if(isset($app)) $app->log('email_ratelimit: ' . $this->error, LOGLEVEL_ERROR);
			return false;
		}
		@chmod($tmp, 0644);

		if(!@rename($tmp, self::CONF_FILE)) {
			$this->error = 'cannot move ' . $tmp . ' to ' . self::CONF_FILE;
			if(isset($app)) $app->log('email_ratelimit: ' . $this->error, LOGLEVEL_ERROR);
			@unlink($tmp);
			return false;
		}

		return true;
	}

	/**
	 * Render the configuration array as the Lua source that rspamd reads.
	 * Public so the cron job can compare the desired state to the file on disk
	 * without writing anything.
	 */
	public function render_config($config) {
		$lua  = "-- email_ratelimit configuration - GENERATED by ISPConfig, do not edit.\n";
		$lua .= "-- Source: System > Server Config > Mail, Mail > Domain, Mail > Email Mailbox,\n";
		$lua .= "--         Mail > Ratelimit. Regenerated on every change.\n";
		$lua .= "ERL_CONFIG = {\n";
		$lua .= sprintf("	enabled = %s,\n", $config['enabled'] ? 'true' : 'false');
		$lua .= sprintf("	interval = %d,\n", intval($config['interval']));

		$d = $config['defaults'];
		$lua .= "	defaults = {\n";
		$lua .= $this->lua_direction('out', $d['out']);
		$lua .= $this->lua_direction('in',  $d['in']);
		$lua .= "	},\n";

		$lua .= "	users = {\n";
		foreach($config['users'] as $email => $u) {
			// NOTE: the field names must not be Lua keywords, hence in_limit /
			// in_interval rather than "in".
			$lua .= sprintf(
				"		[%s] = { out_on = %s, out_limit = %d, out_interval = %d, in_on = %s, in_limit = %d, in_interval = %d },\n",
				$this->lua_string($email),
				!empty($u['out_on']) ? 'true' : 'false',
				intval($u['out']), intval($u['out_interval']),
				!empty($u['in_on']) ? 'true' : 'false',
				intval($u['in']), intval($u['in_interval']));
		}
		$lua .= "	},\n";

		$lua .= "	extern = {\n";
		foreach($config['extern'] as $e) {
			$lua .= sprintf(
				"		{ source = %s, type = %s, limit = %d, interval = %d },\n",
				$this->lua_string($e['source']),
				$this->lua_string($e['type']),
				intval($e['limit']), intval($e['interval']));
		}
		$lua .= "	},\n";
		$lua .= "}\n";

		return $lua;
	}

	private function lua_direction($name, $dir) {
		$key = ($name === 'in') ? 'in_' : 'out';
		return sprintf(
			"\t\t%s = { enabled = %s, limit = %d, interval = %d },\n",
			$key,
			$dir['enabled'] ? 'true' : 'false',
			intval($dir['limit']),
			intval($dir['interval']));
	}

	/**
	 * Render a Lua string literal. Lua 5.1 (LuaJIT, what rspamd embeds) has no
	 * long-bracket need here; escaping backslash and double quote is enough for
	 * the e-mail addresses and domains this file contains. Anything else is
	 * stripped, so a hostile value can never break out of the literal.
	 */
	private function lua_string($s) {
		$s = str_replace(array('\\', '"', "\r", "\n", "\0"), array('\\\\', '\\"', '', '', ''), (string)$s);
		return '"' . $s . '"';
	}

	/**
	 * Install the enforcement module next to the configuration, so rspamd does
	 * not depend on anything inside /usr/local/ispconfig/extensions (which the
	 * extension framework may remove on uninstall).
	 */
	private function copy_module() {
		if(!file_exists(self::MODULE_SOURCE)) {
			$this->error = 'module source missing: ' . self::MODULE_SOURCE;
			return false;
		}
		$content = file_get_contents(self::MODULE_SOURCE);
		if($content === false) {
			$this->error = 'cannot read ' . self::MODULE_SOURCE;
			return false;
		}

		$tmp = self::MODULE_FILE . '.tmp';
		if(file_put_contents($tmp, $content) === false) {
			$this->error = 'cannot write ' . $tmp;
			return false;
		}
		@chmod($tmp, 0644);
		if(!@rename($tmp, self::MODULE_FILE)) {
			$this->error = 'cannot move ' . $tmp . ' to ' . self::MODULE_FILE;
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/** Ask ISPConfig to reload rspamd (delayed, so a burst of changes is one reload). */
	public function restart_rspamd() {
		global $app;
		if(!isset($app) || !is_object($app) || !isset($app->services)) return false;
		return $app->services->restartServiceDelayed('rspamd', 'reload');
	}
}
