<?php

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
		$db->query("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $ddl);
		$this->messages[] = "added column $table.$column";
		return true;
	}

	private function drop_column($db, $table, $column) {
		if(!$this->table_exists($db, $table)) return false;
		if(!$this->column_exists($db, $table, $column)) return false;
		$db->query("ALTER TABLE `" . $table . "` DROP COLUMN `" . $column . "`");
		$this->messages[] = "dropped column $table.$column";
		return true;
	}

	private function add_core_columns() {
		$db = $this->db();
		if($db === null) {
			$this->messages[] = 'WARN: no database handle, skipping core columns';
			return false;
		}
		foreach($this->core_columns as $table => $columns) {
			foreach($columns as $column => $ddl) {
				$this->add_column($db, $table, $column, $ddl);
			}
		}
		return true;
	}

	private function drop_core_columns() {
		$db = $this->db();
		if($db === null) return false;
		foreach($this->core_columns as $table => $columns) {
			foreach($columns as $column => $ddl) {
				$this->drop_column($db, $table, $column);
			}
		}
		return true;
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
				if((is_link($abs) || is_file($abs)) && @unlink($abs)) {
					$this->messages[] = "removed $target";
				}
				// clean up the shadow copy a previous install may have left
				if(file_exists($abs . '.email_ratelimit.bak')) {
					@unlink($abs . '.email_ratelimit.bak');
					$this->messages[] = "removed $target.email_ratelimit.bak";
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
					if($existing !== $content && !file_exists($abs . '.email_ratelimit.bak')) {
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

	//* ------------------------------------------------------------------- install

	public function install() {
		global $app;

		$this->add_core_columns();

		if(!$this->apply_file_list(false)) {
			$app->log('email_ratelimit: file deployment failed', LOGLEVEL_ERROR);
			return false;
		}

		$this->register_rspamd_include();
		$this->sync_now();

		return true;
	}

	//* -------------------------------------------------------------------- enable

	public function enable() {
		$this->register_rspamd_include();
		$this->sync_now();
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
			$app->email_ratelimit_sync->restart_rspamd();
		}
		return true;
	}

	//* ------------------------------------------------------------------ update

	public function update() {
		$this->add_core_columns();

		//* idempotent: existing files are overwritten, links recreated
		if(!$this->apply_file_list(false)) {
			return false;
		}

		$this->register_rspamd_include();
		$this->sync_now();

		return true;
	}

	//* ---------------------------------------------------------------- uninstall

	public function uninstall() {
		global $app;

		//* 1. stop rspamd from loading the module and drop generated files
		$sync_class = $this->ispconfig_dir . '/server/lib/classes/email_ratelimit_sync.inc.php';
		if(file_exists($sync_class)) {
			$app->uses('email_ratelimit_sync');
			if(isset($app->email_ratelimit_sync) && is_object($app->email_ratelimit_sync)) {
				$app->email_ratelimit_sync->remove_generated();
			}
		}
		$this->unregister_rspamd_include();

		//* 2. remove the deployed files (the core does not do this for us)
		$this->apply_file_list(true);

		//* 3. remove the columns we added to core tables
		$this->drop_core_columns();

		//* the extension's own table is dropped by install/uninstall_master.sql
		return true;
	}

	//* ------------------------------------------------------------------ helper

	private function sync_now() {
		global $app;

		$app->uses('email_ratelimit_sync');
		if(isset($app->email_ratelimit_sync) && is_object($app->email_ratelimit_sync)) {
			if($app->email_ratelimit_sync->sync()) {
				$app->email_ratelimit_sync->restart_rspamd();
			} else {
				$app->log('email_ratelimit: initial configuration failed: '
					. $app->email_ratelimit_sync->error, LOGLEVEL_WARN);
			}
		}
	}

	//* ------------------------------------------------------- rspamd.local.lua

	/**
	 * The generated module is loaded from Rspamd's official customisation entry
	 * point /etc/rspamd/rspamd.local.lua, which rspamd dofile()s. We append a
	 * single guarded statement and never overwrite what the admin put there.
	 */
	private function register_rspamd_include() {
		global $app;

		if(!is_dir('/etc/rspamd')) {
			$this->messages[] = 'note: /etc/rspamd not found, skipped rspamd integration';
			return false;
		}

		$local_lua = '/etc/rspamd/rspamd.local.lua';
		$marker = '-- email_ratelimit (managed by the ISPConfig email_ratelimit extension)';
		$snippet = "\n" . $marker . "\n"
			. "dofile('/etc/rspamd/email_ratelimit.conf.lua')\n";

		$current = file_exists($local_lua) ? file_get_contents($local_lua) : '';

		if(strpos($current, $marker) === false) {
			if(!file_exists($local_lua)) {
				$current = "-- Local Lua rules for Rspamd (managed by ISPConfig)\n";
			}
			if(file_put_contents($local_lua, rtrim($current) . "\n" . $snippet) === false) {
				$this->messages[] = "ERROR: cannot write $local_lua";
				return false;
			}
			$this->messages[] = 'registered rspamd.local.lua include';
		}

		//* create the file the include points at, so rspamd never dofile()s a
		//  missing path (rspamd warns loudly on that)
		if(!file_exists('/etc/rspamd/email_ratelimit.conf.lua')) {
			file_put_contents('/etc/rspamd/email_ratelimit.conf.lua',
				"-- email_ratelimit: not yet generated, mail is not rate limited\n");
		}

		@chmod($local_lua, 0644);
		@chmod('/etc/rspamd/email_ratelimit.conf.lua', 0644);

		return true;
	}

	private function unregister_rspamd_include() {
		$local_lua = '/etc/rspamd/rspamd.local.lua';
		$conf_lua  = '/etc/rspamd/email_ratelimit.conf.lua';

		if(file_exists($local_lua)) {
			$marker = '-- email_ratelimit (managed by the ISPConfig email_ratelimit extension)';
			$lines = file($local_lua, FILE_IGNORE_NEW_LINES);
			$out = array();
			$skip_next = false;
			foreach($lines as $line) {
				if($skip_next && trim($line) === "dofile('/etc/rspamd/email_ratelimit.conf.lua')") {
					$skip_next = false;
					continue;
				}
				$skip_next = false;
				if(strpos($line, $marker) !== false) {
					$skip_next = true;
					continue;
				}
				$out[] = $line;
			}
			file_put_contents($local_lua, implode("\n", $out) . "\n");
			$this->messages[] = 'unregistered rspamd.local.lua include';
		}

		if(file_exists($conf_lua)) {
			@unlink($conf_lua);
			$this->messages[] = 'removed generated rspamd configuration';
		}
		return true;
	}
}
