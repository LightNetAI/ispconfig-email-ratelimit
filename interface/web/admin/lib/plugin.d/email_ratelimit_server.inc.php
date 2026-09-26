<?php

/*
 * email_ratelimit_server - adds the rate limit options to
 * System > Server Config > Mail.
 *
 * Mechanism (no core file is modified):
 *   - loadFormDef() raises <module>:<form>:on_after_formdef. We add the fields
 *     to the 'mail' tab there, so the core mail tab keeps its own fields and we
 *     only append ours.
 *   - tform_actions calls every tab plugin's onUpdate() after the core record
 *     has been written, which is where we persist our columns.
 *
 * The columns live in the core `server` table, which ISPConfig already
 * replicates to every mail server, so nothing else has to be synced.
 */

class email_ratelimit_server_plugin {

	var $plugin_name = 'email_ratelimit_server_plugin';
	var $class_name  = 'email_ratelimit_server_plugin';

	/** columns this plugin owns in the `server` table */
	private $columns = array(
		'erl_enabled'          => "enum('n','y') NOT NULL DEFAULT 'n'",
		'erl_out_limit'        => "int(11) unsigned NOT NULL DEFAULT 500",
		'erl_out_interval'     => "int(11) unsigned NOT NULL DEFAULT 3600",
		'erl_in_limit'         => "int(11) unsigned NOT NULL DEFAULT 0",
		'erl_in_interval'      => "int(11) unsigned NOT NULL DEFAULT 3600",
		'erl_default_user_out' => "int(11) unsigned NOT NULL DEFAULT 0",
	);

	private $intervals = array(
		'60'    => '1 minute',
		'300'   => '5 minutes',
		'900'   => '15 minutes',
		'3600'  => '1 hour',
		'21600' => '6 hours',
		'86400' => '1 day',
	);

	public function onLoad() {
		global $app;

		//* Inject our fields into the server_config form the moment it is loaded.
		$app->plugin->registerEvent('admin:server_config:on_after_formdef', $this->plugin_name, 'add_fields');
	}

	/**
	 * Called by loadFormDef() right after the core form definition was read.
	 */
	public function add_fields($event_name, $page_form) {
		global $app;

		if(!isset($page_form->formDef['tabs']['mail'])) return;

		$fields = array();

		$fields['erl_enabled'] = array(
			'datatype' => 'VARCHAR',
			'formtype' => 'CHECKBOX',
			'default'  => 'n',
			'value'    => array(0 => 'n', 1 => 'y'),
		);

		$fields['erl_out_limit'] = array(
			'datatype'  => 'INTEGER',
			'formtype'  => 'TEXT',
			'validators' => array(0 => array('type' => 'REGEX',
				'regex' => '/^[0-9]{1,9}$/', 'errmsg' => 'erl_limit_error_regex')),
			'default'   => '500',
			'value'     => '',
			'width'     => '10',
			'maxlength' => '9',
		);

		$fields['erl_out_interval'] = array(
			'datatype' => 'INTEGER',
			'formtype' => 'SELECT',
			'default'  => '3600',
			'value'    => $this->intervals,
		);

		$fields['erl_in_limit'] = array(
			'datatype'  => 'INTEGER',
			'formtype'  => 'TEXT',
			'validators' => array(0 => array('type' => 'REGEX',
				'regex' => '/^[0-9]{1,9}$/', 'errmsg' => 'erl_limit_error_regex')),
			'default'   => '0',
			'value'     => '',
			'width'     => '10',
			'maxlength' => '9',
		);

		$fields['erl_in_interval'] = array(
			'datatype' => 'INTEGER',
			'formtype' => 'SELECT',
			'default'  => '3600',
			'value'    => $this->intervals,
		);

		$fields['erl_default_user_out'] = array(
			'datatype'  => 'INTEGER',
			'formtype'  => 'TEXT',
			'validators' => array(0 => array('type' => 'REGEX',
				'regex' => '/^[0-9]{1,9}$/', 'errmsg' => 'erl_limit_error_regex')),
			'default'   => '0',
			'value'     => '',
			'width'     => '10',
			'maxlength' => '9',
		);

		foreach($fields as $name => $def) {
			$page_form->formDef['tabs']['mail']['fields'][$name] = $def;
		}
		//* Labels. Set here rather than in an .lng file so that uninstalling the
		//  extension never removes a file that belongs to the ISPConfig core.
		$page_form->wordbook['erl_settings_txt'] = 'Ratelimit';
		$page_form->wordbook['erl_enabled_txt'] = 'Ratelimit';
		$page_form->wordbook['erl_out_limit_txt'] = 'Ratelimit Mails Out';
		$page_form->wordbook['erl_out_interval_txt'] = 'Ratelimit Interval Out';
		$page_form->wordbook['erl_in_limit_txt'] = 'Ratelimit Mails In';
		$page_form->wordbook['erl_in_interval_txt'] = 'Ratelimit Interval In';
		$page_form->wordbook['erl_default_user_out_txt'] = 'Ratelimit Mails Out per mailbox';
		$page_form->wordbook['erl_limit_error_regex'] = 'Please enter a number.';
		$page_form->wordbook['erl_note_out_txt'] = 'Maximum messages an authenticated mailbox may send within the interval. 0 disables the outgoing limit.';
		$page_form->wordbook['erl_note_in_txt'] = 'Maximum messages a mailbox may receive within the interval. 0 disables the incoming limit.';
		$page_form->wordbook['erl_note_default_user_txt'] = 'Default per-mailbox outgoing limit for mailboxes that inherit (0 = inherit the server limit).';


		//* the template needs the labels
	}
}
