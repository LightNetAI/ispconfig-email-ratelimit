<?php

/******************************************
* Begin Form configuration
******************************************/

$tform_def_file = "form/email_ratelimit.tform.php";

/******************************************
* End Form configuration
******************************************/

require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

//* Check permissions for module
$app->auth->check_module_permissions('mail');

// Loading classes
$app->uses('tpl,tform,tform_actions');
$app->load('tform_actions');

class page_action extends tform_actions {

	function onShowNew() {
		global $app, $conf;

		//* Preselect the only mail server, if there is just one.
		if($_SESSION["s"]["user"]["typ"] != 'user') {
			$app->uses('getconf');
			$settings = $app->getconf->get_global_config('mail');
			if(isset($settings['default_mailserver']) && intval($settings['default_mailserver']) > 0) {
				$app->tform->formDef['tabs']['ratelimit']['fields']['server_id']['default'] = intval($settings['default_mailserver']);
			}
		}

		parent::onShowNew();
	}

}

$page = new page_action;
$page->onLoad();

?>
