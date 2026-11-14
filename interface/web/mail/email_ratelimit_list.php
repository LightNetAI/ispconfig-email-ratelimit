<?php
require_once '../../lib/config.inc.php';
require_once '../../lib/app.inc.php';

/******************************************
* Begin Form configuration
******************************************/

$list_def_file = "list/email_ratelimit.list.php";

/******************************************
* End Form configuration
******************************************/

//* Check permissions for module
$app->auth->check_module_permissions('mail');

//* External rate limits are server wide: a reseller must not be able to
//* throttle other tenants' mail, so this page is administrator-only.
if($_SESSION['s']['user']['typ'] != 'admin') {
	$app->error('Only administrator accounts may manage server-wide rate limits.');
}


$app->uses('listform_actions');

$app->listform_actions->onLoad();

?>
