<?php

/*
	Form Definition

	Ratelimit - explicit external rate limits.

	The values are stored in the extension's own table, so this form is a
	plain ISPConfig form; no core table is touched.
*/

$form["title"]    = "Ratelimit";
$form["description"]  = "";
$form["name"]    = "email_ratelimit";
$form["action"]   = "email_ratelimit_edit.php";
$form["db_table"]  = "email_ratelimit";
$form["db_table_idx"] = "ratelimit_id";
$form["db_history"]  = "yes";
$form["tab_default"] = "ratelimit";
$form["list_default"] = "email_ratelimit_list.php";
$form["auth"]   = 'yes'; // yes / no

$form["auth_preset"]["userid"]  = 0; // 0 = id of the user, > 0 id must match with id of current user
$form["auth_preset"]["groupid"] = 0; // 0 = default groupid of the user, > 0 id must match with groupid of current user
$form["auth_preset"]["perm_user"] = 'riud'; //r = read, i = insert, u = update, d = delete
$form["auth_preset"]["perm_group"] = 'riud'; //r = read, i = insert, u = update, d = delete
$form["auth_preset"]["perm_other"] = ''; //r = read, i = insert, u = update, d = delete

$form["tabs"]['ratelimit'] = array (
	'title'  => "Ratelimit",
	'width'  => 100,
	'template'  => "templates/email_ratelimit_edit.htm",
	'fields'  => array (
		//#################################
		// Begin Datatable fields
		//#################################
		'server_id' => array (
			'datatype' => 'INTEGER',
			'formtype' => 'SELECT',
			'default' => '',
			'datasource' => array (  'type' => 'SQL',
				'querystring' => 'SELECT server_id,server_name FROM server WHERE mail_server = 1 AND mirror_server_id = 0 AND {AUTHSQL} ORDER BY server_name',
				'keyfield'=> 'server_id',
				'valuefield'=> 'server_name'
			),
			'value'  => ''
		),
		'source' => array (
			'datatype' => 'VARCHAR',
			'formtype' => 'TEXT',
			'validators' => array (  0 => array ( 'type' => 'NOTEMPTY',
					'errmsg'=> 'source_error_empty'),
				1 => array ( 'type' => 'REGEX',
					'regex' => '/^(@?[A-Za-z0-9._%+\-]+@)?[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/',
					'errmsg'=> 'source_error_regex'),
			),
			'default' => '',
			'value'  => '',
			'width'  => '40',
			'maxlength' => '255'
		),
		'type' => array (
			'datatype' => 'VARCHAR',
			'formtype' => 'SELECT',
			'default' => 'out',
			'value'  => array('in' => 'Incoming', 'out' => 'Outgoing', 'both' => 'In and Out')
		),
		'interval' => array (
			'datatype' => 'INTEGER',
			'formtype' => 'SELECT',
			'default' => '3600',
			'value'  => array(
				'60'    => '1 minute',
				'300'   => '5 minutes',
				'900'   => '15 minutes',
				'3600'  => '1 hour',
				'21600' => '6 hours',
				'86400' => '1 day'
			)
		),
		'limit' => array (
			'datatype' => 'INTEGER',
			'formtype' => 'TEXT',
			'validators' => array (  0 => array ( 'type' => 'NOTEMPTY',
					'errmsg'=> 'limit_error_empty'),
				1 => array ( 'type' => 'REGEX',
					'regex' => '/^[0-9]{1,9}$/',
					'errmsg'=> 'limit_error_regex'),
			),
			'default' => '0',
			'value'  => '',
			'width'  => '10',
			'maxlength' => '9'
		),
		'active' => array (
			'datatype' => 'VARCHAR',
			'formtype' => 'CHECKBOX',
			'default' => 'y',
			'value'  => array(0 => 'n', 1 => 'y')
		),
		//#################################
		// END Datatable fields
		//#################################
	)
);

?>
