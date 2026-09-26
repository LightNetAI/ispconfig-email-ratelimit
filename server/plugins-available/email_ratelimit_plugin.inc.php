<?php

/*
 * email_ratelimit_plugin - regenerates the Rspamd rate limit configuration
 * whenever something that influences the limits changes.
 *
 * Triggering events:
 *   server_update                 - System > Server Config > Mail (the master switch)
 *   mail_domain_*                 - Mail > Domain
 *   mail_user_*                   - Mail > Email Mailbox
 *   email_ratelimit_*             - Mail > Ratelimit (the extension's own table)
 *
 * The regeneration itself lives in the email_ratelimit_sync class so the cron
 * job can reuse it.
 */

class email_ratelimit_plugin {

	var $plugin_name = 'email_ratelimit_plugin';
	var $class_name  = 'email_ratelimit_plugin';

	//* Called during ISPConfig installation to decide on the symlink.
	public function onInstall() {
		global $conf;
		if(isset($conf['services']['mail']) && $conf['services']['mail'] == true) {
			return true;
		}
		return false;
	}

	public function onLoad() {
		global $app;

		//* Rate limits are stored on the mail server; without rspamd there is
		//  nothing to configure.
		if(!is_dir('/etc/rspamd')) return;

		//* Server wide switches.
		$app->plugins->registerEvent('server_insert', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('server_update', $this->plugin_name, 'config_changed');

		//* Per-domain policy.
		$app->plugins->registerEvent('mail_domain_insert', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('mail_domain_update', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('mail_domain_delete', $this->plugin_name, 'config_changed');

		//* Per-mailbox policy.
		$app->plugins->registerEvent('mail_user_insert', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('mail_user_update', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('mail_user_delete', $this->plugin_name, 'config_changed');

		//* The extension's own table (announced by email_ratelimit_module).
		$app->plugins->registerEvent('email_ratelimit_insert', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('email_ratelimit_update', $this->plugin_name, 'config_changed');
		$app->plugins->registerEvent('email_ratelimit_delete', $this->plugin_name, 'config_changed');
	}

	/**
	 * Regenerate the generated configuration.
	 * The data record is not strictly needed: the sync class reads the current
	 * state from the database, which is what makes this idempotent and cheap to
	 * trigger from several events.
	 */
	public function config_changed($event_name, $data) {
		global $app;

		if(!is_dir('/etc/rspamd')) return;

		$app->uses('email_ratelimit_sync');
		if(!is_object($app->email_ratelimit_sync)) {
			$app->log('email_ratelimit: could not load email_ratelimit_sync', LOGLEVEL_WARN);
			return;
		}

		if(!$app->email_ratelimit_sync->sync()) {
			$app->log('email_ratelimit: configuration regeneration failed: '
				. $app->email_ratelimit_sync->error, LOGLEVEL_WARN);
			return;
		}

		$app->log('email_ratelimit: configuration regenerated after ' . $event_name, LOGLEVEL_DEBUG);

		//* Reload rspamd so the new limits take effect. Delayed, so a burst of
		//  changes results in one reload.
		$app->email_ratelimit_sync->restart_rspamd();
	}
}
