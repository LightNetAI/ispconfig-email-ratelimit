<?php

/*
 * email_ratelimit_module - announces the events of the extension's own table
 * and hooks it into the ISPConfig datalog processor.
 *
 * The table hook makes the server process receive a notification whenever a
 * row of `email_ratelimit` changes, which email_ratelimit_plugin.inc.php then
 * turns into a configuration regeneration. Modelled on ISPConfig's own
 * server/mods-available/mail_module.inc.php.
 */

class email_ratelimit_module {

	var $module_name = 'email_ratelimit_module';
	var $class_name  = 'email_ratelimit_module';

	var $actions_available = array(
		'email_ratelimit_insert',
		'email_ratelimit_update',
		'email_ratelimit_delete',
	);

	//* Called during ISPConfig installation to decide on the mods-enabled symlink.
	public function onInstall() {
		global $conf;
		//* only meaningful on a mail server
		if(isset($conf['services']['mail']) && $conf['services']['mail'] == true) {
			return true;
		}
		return false;
	}

	public function onLoad() {
		global $app;

		//* Make the extension's events registerable by plugins.
		$app->plugins->announceEvents($this->module_name, $this->actions_available);

		//* Get notified of every change to the extension's table.
		$app->modules->registerTableHook('email_ratelimit', 'email_ratelimit_module', 'process');
	}

	/**
	 * Called by the datalog processor for every changed row of a hooked table.
	 * Raises the matching plugin event, exactly like mail_module does.
	 */
	public function process($tablename, $action, $data) {
		global $app;

		if($tablename != 'email_ratelimit') return;

		if($action == 'i') $app->plugins->raiseEvent('email_ratelimit_insert', $data);
		if($action == 'u') $app->plugins->raiseEvent('email_ratelimit_update', $data);
		if($action == 'd') $app->plugins->raiseEvent('email_ratelimit_delete', $data);
	}
}
