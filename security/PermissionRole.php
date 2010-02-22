<?php

/**
 * A PermissionRole represents a collection of permission codes that can be applied to groups.
 * 
 * Because permission codes are very granular, this lets website administrators create more
 * business-oriented units of access control - Roles - and assign those to groups.
 */
class PermissionRole extends DataObject {
	static $db = array(
		"Title" => "Varchar",
		"OnlyAdminCanApply" => "Boolean"
	);
	
	static $has_many = array(
		"Codes" => "PermissionRoleCode",
	);
	
	static $belongs_many_many = array(
		"Groups" => "Group",
	);
	
	static $default_sort = "\"Title\"";
	
	function getCMSFields() {
		$fields = parent::getCMSFields();
		
		$fields->removeFieldFromTab('Root', 'Codes');
		$fields->removeFieldFromTab('Root', 'Groups');
		
		$fields->addFieldToTab('Root.Main', new PermissionCheckboxSetField(
			'Codes',
			singleton('Permission')->i18n_plural_name(),
			'PermissionRoleCode',
			'RoleID'));
		
		return $fields;
	}
	
	function onAfterDelete() {
		parent::onAfterDelete();
		
		// Delete associated permission codes
		$codes = $this->Codes();
		foreach ( $codes as $code ) {
			$code->delete();
		}
	}
}
