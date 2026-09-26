<?php

/*
 * email_ratelimit cron job - safety net.
 *
 * The plugin regenerates the configuration on every relevant change, so this
 * job is normally a no-op. It exists to repair the case where a change was
 * made while the server process was not running (server down during a panel
 * change), leaving the generated configuration behind the database.
 *
 * The job is cheap: it renders the configuration and only writes when the
 * result differs from what is already on disk.
 */

class cronjob_email_ratelimit extends cronjob {

	//* Every 10 minutes is plenty for a repair job.
	protected $_schedule  = '*/10 * * * *';
	protected $_run_at_new = false;

	public function onPrepare() {
		global $app, $conf;

		parent::onPrepare();
	}

	public function onRunJob() {
		global $app, $conf;

		if(!is_dir('/etc/rspamd')) return;

		$app->uses('email_ratelimit_sync');
		if(!is_object($app->email_ratelimit_sync)) return;

		$sync = $app->email_ratelimit_sync;

		//* Render the desired configuration without touching the disk yet.
		$config = $sync->build_config();
		if($config === false) {
			$app->log('email_ratelimit cron: cannot build configuration: ' . $sync->error, LOGLEVEL_WARN);
			return;
		}

		$desired = $sync->render_config($config);
		$current = @file_get_contents(email_ratelimit_sync::CONF_FILE);

		if($desired !== false && $desired !== $current) {
			$app->log('email_ratelimit cron: generated configuration was out of date, rewriting', LOGLEVEL_INFO);
			if($sync->sync()) {
				$sync->restart_rspamd();
			} else {
				$app->log('email_ratelimit cron: rewrite failed: ' . $sync->error, LOGLEVEL_ERROR);
			}
		}
	}
}
