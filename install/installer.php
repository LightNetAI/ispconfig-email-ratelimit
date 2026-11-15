<?php

require_once __DIR__ . '/rspamd_block.inc.php';

/*
 * email_ratelimit - ISPConfig 3.3 extension installer
 *
 * Installation is done from an unpacked copy of the extension, because
 * "ispc extension install" only handles extensions that are published in the
 * ISPConfig extension repository:
 *
 *   cp -a email_ratelimit /usr/local/ispconfig/extensions/
 *   cd /usr/local/ispconfig/extensions/email_ratelimit
 *   php install/deploy.php install
 *
 * deploy.php loads this class and drives it the same way the framework does.
 *
 * IMPORTANT: ISPConfig's extension_installer has enable_files()/disable_files()
 * methods, but nothing in the core calls them, so install/file.list is NOT
 * applied automatically. The file deployment therefore happens here, in
 * install()/uninstall(), which is the only place that is guaranteed to run.
 */

class email_ratelimit_installer extends extension_installer_base {

	/**
	 * Optional callable($sql) => bool used when the app DB user may not run
	 * DDL (hardened servers restrict `ispconfig` to DML). deploy.php points
	 * this at the root MySQL client; the install still requires root anyway.
	 */
	public $ddl_runner = null;

	/** Extension name, also the directory name under /usr/local/ispconfig/extensions */
	private $name = 'email_ratelimit';

	/** Columns added to core tables, table => array(column => DDL fragment) */
	private $core_columns = array(
		// server-wide defaults and master switches (System > Server Config > Mail)
		'server' => array(
			'erl_enabled'           => "enum('n','y') NOT NULL DEFAULT 'n'",
			'erl_out_limit'         => "int(11) unsigned NOT NULL DEFAULT 500",
			'erl_out_interval'      => "int(11) unsigned NOT NULL DEFAULT 3600",
			'erl_in_limit'          => "int(11) unsigned NOT NULL DEFAULT 0",
			'erl_in_interval'       => "int(11) unsigned NOT NULL DEFAULT 3600",
			'erl_default_user_out'  => "int(11) unsigned NOT NULL DEFAULT 0",
		),
		// per-domain policy (Mail > Domain)
		'mail_domain' => array(
			// global = inherit server config, unlimited = no limit, custom = own values
			'erl_limit_mode'        => "enum('global','unlimited','custom') NOT NULL DEFAULT 'global'",
			'erl_out_limit'         => "int(11) unsigned NOT NULL DEFAULT 0",
			'erl_out_interval'      => "int(11) unsigned NOT NULL DEFAULT 3600",
			'erl_in_limit'          => "int(11) unsigned NOT NULL DEFAULT 0",
			'erl_in_interval'       => "int(11) unsigned NOT NULL DEFAULT 3600",
		),
		// per-mailbox policy (Mail > Email Mailbox)
		'mail_user' => array(
			// domain = inherit the domain, unlimited = no limit, custom = own values
			'erl_limit_mode'        => "enum('domain','unlimited','custom') NOT NULL DEFAULT 'domain'",
			'erl_out_limit'         => "int(11) unsigned NOT NULL DEFAULT 0",
			'erl_out_interval'      => "int(11) unsigned NOT NULL DEFAULT 3600",
			'erl_in_limit'          => "int(11) unsigned NOT NULL DEFAULT 0",
			'erl_in_interval'       => "int(11) unsigned NOT NULL DEFAULT 3600",
		),
	);

	/** collected messages, printed by deploy.php */
	public $messages = array();

	//* ---------------------------------------------------------------- database

	private function db() {
		global $app;
		if(isset($app->db) && is_object($app->db)) return $app->db;
		if(isset($app->dbmaster) && is_object($app->dbmaster)) return $app->dbmaster;
		return null;
	}

	private function column_exists($db, $table, $column) {
		$rec = $db->queryOneRecord(
			"SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
			$table, $column);
		return (isset($rec['cnt']) && $rec['cnt'] > 0);
	}

	private function table_exists($db, $table) {
		$rec = $db->queryOneRecord(
			"SELECT COUNT(*) AS cnt FROM information_schema.TABLES
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
			$table);
		return (isset($rec['cnt']) && $rec['cnt'] > 0);
	}

	/**
	 * Add a column to a core table if it is not there yet.
	 * Written to be safe on MySQL and MariaDB alike (no reliance on
	 * "ADD COLUMN IF NOT EXISTS", which MySQL 8 does not support).
	 */
	private function add_column($db, $table, $column, $ddl) {
		if(!$this->table_exists($db, $table)) {
			$this->messages[] = "skip: table $table does not exist";
			return false;
		}
		if($this->column_exists($db, $table, $column)) {
			return true;
		}
		$sql = "ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $ddl;
		$res = $db->query($sql);
		if($res === false) {
			if($this->ddl_runner !== null && call_user_func($this->ddl_runner, $sql)) {
				$this->messages[] = "added column $table.$column (via root client)";
				return true;
			}
			$this->messages[] = "ERROR: cannot add column $table.$column" . $this->db_error($db);
			return false;
		}
		$this->messages[] = "added column $table.$column";
		return true;
	}

	private function drop_column($db, $table, $column) {
		//* nothing to drop is success: uninstall is idempotent and has to work
		//  after an install that stopped before adding every column
		if(!$this->table_exists($db, $table)) return true;
		if(!$this->column_exists($db, $table, $column)) return true;
		$sql = "ALTER TABLE `" . $table . "` DROP COLUMN `" . $column . "`";
		$res = $db->query($sql);
		if($res === false) {
			if($this->ddl_runner !== null && call_user_func($this->ddl_runner, $sql)) {
				$this->messages[] = "dropped column $table.$column (via root client)";
				return true;
			}
			$this->messages[] = "ERROR: cannot drop column $table.$column" . $this->db_error($db);
			return false;
		}
		$this->messages[] = "dropped column $table.$column";
		return true;
	}

	private function db_error($db) {
		if(is_object($db) && isset($db->errorMessage) && $db->errorMessage !== '') {
			return ': ' . $db->errorMessage;
		}
		return '';
	}

	private function add_core_columns() {
		$db = $this->db();
		if($db === null) {
			$this->messages[] = 'WARN: no database handle, skipping core columns';
			return false;
		}
		$ok = true;
		foreach($this->core_columns as $table => $columns) {
			foreach($columns as $column => $ddl) {
				if(!$this->add_column($db, $table, $column, $ddl)) $ok = false;
			}
		}
		return $ok;
	}

	private function drop_core_columns() {
		$db = $this->db();
		if($db === null) return false;
		$ok = true;
		foreach($this->core_columns as $table => $columns) {
			foreach($columns as $column => $ddl) {
				if(!$this->drop_column($db, $table, $column)) $ok = false;
			}
		}
		return $ok;
	}

	//* ------------------------------------------------------------ file deploy

	/**
	 * Parse install/file.list.
	 * Returns array of array(action, source, target) or false on a bad line.
	 */
	private function read_file_list() {
		$path = $this->extension_basedir . '/' . $this->name . '/install/file.list';
		if(!file_exists($path)) {
			$this->messages[] = "ERROR: no file list at $path";
			return false;
		}

		$ext_dir = realpath($this->extension_basedir . '/' . $this->name);
		$out = array();

		foreach(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			$line = trim($line);
			if($line === '' || $line[0] === '#') continue;

			$parts = explode(':', $line);
			if(count($parts) !== 3) {
				$this->messages[] = "ERROR: bad file.list line: $line";
				return false;
			}
			list($action, $source, $target) = $parts;

			if($action !== 'c' && $action !== 's' && $action !== 'd') {
				$this->messages[] = "ERROR: bad action in file.list line: $line";
				return false;
			}
			if($target === '' || $target === '/' || strpos($target, '..') !== false) {
				$this->messages[] = "ERROR: bad target in file.list line: $line";
				return false;
			}

			if($action === 'd') {
				$out[] = array($action, '', $target);
				continue;
			}

			$src = realpath($ext_dir . '/' . $source);
			if($src === false || strpos($src, $ext_dir . '/') !== 0) {
				$this->messages[] = "ERROR: bad source in file.list line: $line";
				return false;
			}
			$out[] = array($action, $src, $target);
		}
		return $out;
	}

	private function mkdir_p($dir, $mode = 0750) {
		if(is_dir($dir)) return true;
		if(!@mkdir($dir, $mode, true)) return false;
		@chmod($dir, $mode);
		$this->chown_for($dir);
		return true;
	}

	/** interface/* belongs to the web user, everything else to root */
	private function chown_for($target) {
		$rel = ltrim(str_replace($this->ispconfig_dir, '', $target), '/');
		$owner = (strpos($rel, 'interface') === 0) ? 'ispconfig:ispconfig' : 'root:root';
		@exec('chown -h ' . escapeshellarg($owner) . ' ' . escapeshellarg($target));
	}

	/**
	 * Apply install/file.list.
	 * When $reverse is true the deployed files are removed instead (used by
	 * uninstall), which matters because the core never removes them for us.
	 */
	private function apply_file_list($reverse = false) {
		$files = $this->read_file_list();
		if($files === false) return false;

		foreach($files as $entry) {
			list($action, $source, $target) = $entry;
			$abs = $this->ispconfig_dir . '/' . $target;

			// make sure the parent directory exists
			$parent = dirname($abs);
			if(!$reverse && !is_dir($parent)) {
				$this->mkdir_p($parent);
			}

			if($action === 'd') {
				if($reverse) {
					if(is_dir($abs) && !is_link($abs) && @rmdir($abs)) {
						$this->messages[] = "rmdir $target";
					}
				} else {
					$this->mkdir_p($abs);
					$this->messages[] = "dir $target";
				}
				continue;
			}

			if($reverse) {
				$bak = $abs . '.email_ratelimit.bak';
				// If a previous install shadow-copied a file that already existed
				// (e.g. a themed template), put the original back instead of
				// deleting both copies - uninstalling must not eat the admin's
				// customisation.
				if(file_exists($bak)) {
					if(@rename($bak, $abs)) {
						$this->messages[] = "restored $target from backup";
					} else {
						$this->messages[] = "ERROR: cannot restore $target from backup";
					}
					continue;
				}
				if((is_link($abs) || is_file($abs)) && @unlink($abs)) {
					$this->messages[] = "removed $target";
				}
				continue;
			}

			if($action === 'c') {
				$dir = dirname($abs);
				$this->mkdir_p($dir);

				$content = file_get_contents($source);
				if($content === false) {
					$this->messages[] = "ERROR: cannot read source for $target";
					return false;
				}

				// Shadow-copy someone else's file before replacing it, so an
				// unexpected collision is recoverable. Our own files (identical
				// content) are simply overwritten - this keeps the tree clean
				// and avoids a backup file on every re-install.
				if(file_exists($abs) && !is_link($abs)) {
					$existing = file_get_contents($abs);
					// A .bak of one of our own earlier versions would be restored
					// on uninstall and resurrect a stale extension file, so only
					// back up files that did not come from this extension.
					if($existing !== $content && !$this->looks_like_ours($abs)
						&& !file_exists($abs . '.email_ratelimit.bak')) {
						if(@copy($abs, $abs . '.email_ratelimit.bak')) {
							$this->messages[] = "backed up $target -> $target.email_ratelimit.bak";
						}
					}
				}

				if(file_put_contents($abs, $content) === false) {
					$this->messages[] = "ERROR: cannot copy $target";
					return false;
				}
				@chmod($abs, 0640);
				$this->chown_for($abs);
				$this->messages[] = "copy $target";
			} elseif($action === 's') {
				if(is_link($abs) || is_file($abs)) @unlink($abs);
				if(!@symlink($source, $abs)) {
					$this->messages[] = "ERROR: cannot symlink $target";
					return false;
				}
				$this->chown_for($abs);
				$this->messages[] = "link $target";
			}
		}
		return true;
	}

	/**
	 * True when the file already belongs to this extension (an earlier version
	 * of it, or a themed copy we deployed). Such files must not be shadow-copied:
	 * a .bak would be restored on uninstall and leave a stale panel template
	 * behind instead of removing it.
	 */
	private function looks_like_ours($path) {
		$head = @file_get_contents($path, false, null, 0, 8192);
		if($head === false) return false;

		foreach(array('email_ratelimit', 'toggle-ratelimit', 'erl_limit_mode', 'erl_out_enabled') as $needle) {
			if(strpos($head, $needle) !== false) return true;
		}

		return false;
	}

	//* ------------------------------------------------------------------- install

	public function install() {
		global $app;

		if(!$this->add_core_columns()) {
			$app->log('email_ratelimit: adding the database columns failed, aborting', LOGLEVEL_ERROR);
			return false;
		}

		$this->migrate_server_flags();

		if(!$this->apply_file_list(false)) {
			$app->log('email_ratelimit: file deployment failed', LOGLEVEL_ERROR);
			return false;
		}

		$this->register_rspamd_include();

		if(!$this->sync_now()) {
			$app->log('email_ratelimit: initial configuration failed, install incomplete', LOGLEVEL_ERROR);
			return false;
		}

		$this->reload_rspamd_now();

		return true;
	}

	//* -------------------------------------------------------------------- enable

	public function enable() {
		$this->register_rspamd_include();
		if(!$this->sync_now()) return false;
		$this->reload_rspamd_now();
		return true;
	}

	//* ------------------------------------------------------------------ disable

	public function disable() {
		global $app;

		//* Stop enforcing: keep all files, regenerate the configuration with
		//  enforcement switched off so rspamd stops limiting mail.
		$app->uses('email_ratelimit_sync');
		if(isset($app->email_ratelimit_sync) && is_object($app->email_ratelimit_sync)) {
			$app->email_ratelimit_sync->write_disabled();
		}
		$this->reload_rspamd_now();
		return true;
	}

	//* ------------------------------------------------------------------ update

	public function update() {
		if(!$this->add_core_columns()) {
			$this->messages[] = 'ERROR: adding the database columns failed, aborting';
			return false;
		}

		$this->migrate_server_flags();

		//* idempotent: existing files are overwritten, links recreated
		if(!$this->apply_file_list(false)) {
			return false;
		}

		$this->register_rspamd_include();

		if(!$this->sync_now()) {
			$this->messages[] = 'ERROR: configuration could not be generated';
			return false;
		}

		$this->reload_rspamd_now();

		return true;
	}

	//* ---------------------------------------------------------------- uninstall

	public function uninstall() {
		global $app;

		$ok = true;

		//* 1. stop rspamd from dofile()ing the generated configuration. This has
		//  to happen BEFORE the files are deleted: a reload in between would
		//  leave rspamd with a dofile() of a missing file and it would refuse
		//  to start. If this fails, delete nothing.
		if(!$this->unregister_rspamd_include()) {
			$this->messages[] = 'ERROR: could not remove the rspamd include, no files were deleted';
			return false;
		}

		//* 2. delete the generated files directly. Deliberately does not go
		//  through email_ratelimit_sync: uninstall has to work when that class
		//  (or the app bootstrap) is the broken part.
		foreach(array('/etc/rspamd/email_ratelimit.conf.lua', '/etc/rspamd/email_ratelimit.lua') as $f) {
			if(file_exists($f)) {
				if(@unlink($f)) {
					$this->messages[] = 'removed ' . basename($f);
				} else {
					$this->messages[] = "ERROR: cannot delete $f";
					$ok = false;
				}
			}
		}
		$this->reload_rspamd_now();

		//* 3. remove the deployed files (the core does not do this for us)
		if(!$this->apply_file_list(true)) {
			$this->messages[] = 'ERROR: some deployed files could not be removed';
			$ok = false;
		}

		//* 4. remove the columns we added to core tables
		if(!$this->drop_core_columns()) {
			$ok = false;
		}

		//* the extension's own table is dropped by install/uninstall_master.sql
		return $ok;
	}

	//* ------------------------------------------------------------------ helper

	/**
	 * Serialise a config array the way the panel does ("[section]" + "key=value").
	 * Needed because the server copy of ini_parser::get_ini_string() takes no
	 * argument and would silently return the unmodified config.
	 */
	private function ini_string($cfg) {
		$out = '';
		foreach($cfg as $section => $data) {
			if(!is_array($data)) continue;
			$out .= '[' . $section . "]\n";
			foreach($data as $key => $value) {
				if($key === '') continue;
				$out .= $key . '=' . trim($value) . "\n";
			}
			$out .= "\n";
		}
		return $out;
	}

	/**
	 * Older installs only had the single erl_enabled switch. The panel now has
	 * separate outgoing/incoming switches, so seed them from the old value the
	 * first time we run. Goes through datalogUpdate so the server process picks
	 * the change up exactly like a panel save.
	 */
	private function migrate_server_flags() {
		global $app, $conf;

		$server_id = isset($conf['server_id']) ? intval($conf['server_id']) : 0;
		if($server_id <= 0) return;

		$app->uses('getconf,ini_parser');
		if(!is_object($app->getconf) || !is_object($app->ini_parser)) return;

		$cfg = $app->getconf->get_server_config($server_id);
		if(!is_array($cfg) || !isset($cfg['mail']) || !is_array($cfg['mail'])) return;

		$mail = $cfg['mail'];
		if(isset($mail['erl_out_enabled']) || isset($mail['erl_in_enabled'])) return;

		$legacy = (isset($mail['erl_enabled']) && $mail['erl_enabled'] == 'y') ? 'y' : 'n';
		$mail['erl_out_enabled'] = $legacy;
		$mail['erl_in_enabled']  = $legacy;
		$cfg['mail'] = $mail;

		//* The server-side ini_parser::get_ini_string() ignores its argument and
		//  dumps the last parsed config, so serialise the array ourselves in the
		//  same format the panel writes.
		$str = $this->ini_string($cfg);
		if($str == '') return;

		$app->db->datalogUpdate('server', array('config' => $str), 'server_id', $server_id);
		$this->messages[] = 'migrated erl_enabled=' . $legacy . ' to erl_out_enabled/erl_in_enabled';
	}

	private function sync_now() {
		global $app;

		$app->uses('email_ratelimit_sync');
		if(!isset($app->email_ratelimit_sync) || !is_object($app->email_ratelimit_sync)) {
			$this->messages[] = 'ERROR: cannot load email_ratelimit_sync';
			return false;
		}
		if(!$app->email_ratelimit_sync->sync()) {
			$this->messages[] = 'ERROR: initial configuration failed: '
				. $app->email_ratelimit_sync->error;
			return false;
		}
		$app->email_ratelimit_sync->restart_rspamd();
		return true;
	}

	//* ------------------------------------------------------- rspamd.local.lua

	/**
	 * The generated module is loaded from Rspamd's official customisation entry
	 * point /etc/rspamd/rspamd.local.lua, which rspamd dofile()s. Both the
	 * configuration AND the enforcement module must be loaded - the config file
	 * only assigns ERL_CONFIG and registers nothing by itself.
	 *
	 * The block is delimited by markers so an update can replace it and an
	 * uninstall can remove it without touching anything the admin put there.
	 */
	private function rspamd_block() {
		return erl_rspamd_block();
	}

	/** Write a file atomically: temp file in the same directory, then rename. */
	private function write_atomic($path, $content, $mode = 0644) {
		return erl_write_atomic($path, $content, $mode);
	}

	/**
	 * Remove our block (current BEGIN/END form) and the legacy single-line
	 * marker form, so an install that predates the block markers is updated
	 * cleanly instead of ending up with two includes.
	 */
	private function strip_rspamd_block($content) {
		return erl_rspamd_strip($content);
	}


	/**
	 * 0640 root:_rspamd when that group exists (the generated files contain
	 * every mailbox address), 0644 otherwise so rspamd can still read them.
	 */
	private function fix_rspamd_perms($path) {
		erl_rspamd_perms($path);
	}

	/**
	 * Reload rspamd right now.
	 *
	 * restartServiceDelayed() only queues an action that server.php drains at
	 * the end of its own run; a CLI install/uninstall never reaches that point,
	 * so the new configuration would otherwise sit on disk unused (and an
	 * uninstall would keep enforcing the old limits from rspamd's memory).
	 */
	private function reload_rspamd_now() {
		global $app;

		if(isset($app) && is_object($app) && isset($app->services) && is_object($app->services)) {
			$ret = @$app->services->restartService('rspamd', 'reload');
			if(is_array($ret) && isset($ret['retval']) && intval($ret['retval']) === 0) {
				$this->messages[] = 'reloaded rspamd';
				return true;
			}
		}

		foreach(array('systemctl reload-or-restart rspamd', 'service rspamd reload') as $cmd) {
			$out = array();
			$rc = 1;
			@exec($cmd . ' 2>&1', $out, $rc);
			if($rc === 0) {
				$this->messages[] = 'reloaded rspamd (' . $cmd . ')';
				return true;
			}
		}

		$this->messages[] = 'WARN: could not reload rspamd, do it manually: service rspamd reload';
		return false;
	}

	private function register_rspamd_include() {
		global $app;

		if(!is_dir('/etc/rspamd')) {
			$this->messages[] = 'note: /etc/rspamd not found, skipped rspamd integration';
			return false;
		}

		$local_lua = '/etc/rspamd/rspamd.local.lua';
		$current = file_exists($local_lua)
			? file_get_contents($local_lua)
			: "-- Local Lua rules for Rspamd (managed by ISPConfig)\n";
		if($current === false) $current = '';

		$desired = rtrim($this->strip_rspamd_block($current)) . "\n\n" . $this->rspamd_block();

		if($desired !== $current) {
			if(file_exists($local_lua) && !file_exists($local_lua . '.email_ratelimit.bak')) {
				if(@copy($local_lua, $local_lua . '.email_ratelimit.bak')) {
					$this->messages[] = 'backed up rspamd.local.lua';
				}
			}
			if(!$this->write_atomic($local_lua, $desired, 0644)) {
				$this->messages[] = "ERROR: cannot write $local_lua";
				return false;
			}
			$this->messages[] = 'registered rspamd.local.lua include';
		}

		//* Both files the block dofile()s must exist, otherwise a crash between
		//  install and the first sync leaves rspamd unable to load its config.
		$stubs = array(
			'/etc/rspamd/email_ratelimit.conf.lua' => "-- email_ratelimit: not yet generated, mail is not rate limited\n",
			'/etc/rspamd/email_ratelimit.lua'      => "-- email_ratelimit: not yet deployed, mail is not rate limited\n",
		);
		foreach($stubs as $path => $stub) {
			if(!file_exists($path)) {
				$this->write_atomic($path, $stub, 0640);
			}
			$this->fix_rspamd_perms($path);
		}

		$this->fix_rspamd_perms($local_lua);

		return true;
	}

	private function unregister_rspamd_include() {
		$local_lua = '/etc/rspamd/rspamd.local.lua';
		$failed = false;

		if(file_exists($local_lua)) {
			$current = @file_get_contents($local_lua);
			if($current === false) {
				$this->messages[] = "ERROR: cannot read $local_lua";
				$failed = true;
			} elseif(strpos($current, 'email_ratelimit') !== false) {
				$clean = $this->strip_rspamd_block($current);
				if($clean !== $current) {
					if(!$this->write_atomic($local_lua, $clean, 0644)) {
						$this->messages[] = "ERROR: cannot write $local_lua";
						$failed = true;
					} else {
						$this->messages[] = 'unregistered rspamd.local.lua include';
					}
				}
			}
		}

		if($failed) return false;
		return true;
	}
}
