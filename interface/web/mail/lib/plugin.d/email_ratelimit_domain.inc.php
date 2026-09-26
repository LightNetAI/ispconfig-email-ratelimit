<?php

/*
 * email_ratelimit_domain - adds the Ratelimit panel to the mail domain form
 * (Mail > Domain) and persists its values.
 *
 * Fields are added to the 'domain' tab in on_after_formdef, which
 * tform_base::loadFormDef() raises right after the core definition is read.
 * Persisting happens in the tab plugin's onInsert()/onUpdate(), which
 * tform_actions calls after the core mail_domain record has been written.
 */

class email_ratelimit_domain_plugin {

	var $plugin_name = 'email_ratelimit_domain_plugin';
	var $class_name  = 'email_ratelimit_domain_plugin';

	private $columns = array(
		'erl_limit_mode'   => "enum('global','unlimited','custom') NOT NULL DEFAULT 'global'",
		'erl_out_limit'    => "int(11) unsigned NOT NULL DEFAULT 0",
		'erl_out_interval' => "int(11) unsigned NOT NULL DEFAULT 3600",
		'erl_in_limit'     => "int(11) unsigned NOT NULL DEFAULT 0",
		'erl_in_interval'  => "int(11) unsigned NOT NULL DEFAULT 3600",
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

		$app->plugin->registerEvent('mail:mail_domain:on_after_formdef', $this->plugin_name, 'add_fields');
	}

	public function add_fields($event_name, $page_form) {
		global $app;

		if(!isset($page_form->formDef['tabs']['domain'])) return;

		$fields = array();

		$fields['erl_limit_mode'] = array(
			'datatype' => 'VARCHAR',
			'formtype' => 'SELECT',
			'default'  => 'global',
			'value'    => array(
				'global'    => 'Global Limits',
				'unlimited' => 'Unlimited',
				'custom'    => 'Custom Limits',
			),
		);
		$fields['erl_out_limit'] = array(
			'datatype'  => 'INTEGER',
			'formtype'  => 'TEXT',
			'validators' => array(0 => array('type' => 'REGEX',
				'regex' => '/^[0-9]{1,9}$/', 'errmsg' => 'erl_limit_error_regex')),
			'default'   => '0',
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

		foreach($fields as $name => $def) {
			$page_form->formDef['tabs']['domain']['fields'][$name] = $def;
		}
		//* Labels. Set here rather than in an .lng file so that uninstalling the
		//  extension never removes a file that belongs to the ISPConfig core.
		$page_form->wordbook['erl_settings_txt'] = 'Ratelimit';
		$page_form->wordbook['erl_limit_mode_txt'] = 'Ratelimit Mails';
		$page_form->wordbook['erl_out_limit_txt'] = 'Ratelimit Mails Out';
		$page_form->wordbook['erl_out_interval_txt'] = 'Ratelimit Interval Out';
		$page_form->wordbook['erl_in_limit_txt'] = 'Ratelimit Mails In';
		$page_form->wordbook['erl_in_interval_txt'] = 'Ratelimit Interval In';
		$page_form->wordbook['erl_limit_error_regex'] = 'Please enter a number.';


	}

	//* ------------------------------------------------------------- persistence

	public function onInsert() {
		$this->save();
	}

	public function onUpdate() {
		$this->save();
	}

	private function save() {
		global $app;

		if(!isset($this->form) || !is_object($this->form)) return;

		$id = intval($this->form->id);
		if($id <= 0) return;

		$rec = $this->form->dataRecord;

		$mode = isset($rec['erl_limit_mode']) ? $rec['erl_limit_mode'] : 'global';
		if(!in_array($mode, array('global', 'unlimited', 'custom'), true)) $mode = 'global';

		//* Values only matter in custom mode, keep them otherwise so switching
		//  back and forth in the panel does not lose them.
		$app->db->query(
			"UPDATE `mail_domain`
			 SET `erl_limit_mode` = ?, `erl_out_limit` = ?, `erl_out_interval` = ?,
			     `erl_in_limit` = ?, `erl_in_interval` = ?
			 WHERE `domain_id` = ?",
			$mode,
			$this->intval($rec, 'erl_out_limit'),
			$this->interval($rec, 'erl_out_interval'),
			$this->intval($rec, 'erl_in_limit'),
			$this->interval($rec, 'erl_in_interval'),
			$id
		);
	}

	private function intval($rec, $key) {
		return isset($rec[$key]) ? max(0, intval($rec[$key])) : 0;
	}

	private function interval($rec, $key) {
		$v = isset($rec[$key]) ? intval($rec[$key]) : 3600;
		if(!in_array($v, array(60, 300, 900, 3600, 21600, 86400), true)) $v = 3600;
		return $v;
	}
}
