<?php
//
// iTop module definition file
//

SetupWebPage::AddModule(
	__FILE__, // Path to the current file, all other file names are relative to the directory containing this file
	'action-whatsapp-notifications/0.1.0',
	array(
		// Identification
		//
		'label' => 'WhatsApp notifications',
		'category' => 'business',

		// Setup
		//
		'dependencies' => array(
			'itop-config-mgmt/2.2.0'
		),
		'mandatory' => false,
		'visible' => true,

		// Components
		//
		'datamodel' => array(
			'main.action-whatsapp-notifications.php',
			'model.action-whatsapp-notifications.php'
		),
		'webservice' => array(
			
		),
		'data.struct' => array(
			// add your 'structure' definition XML files here,
		),
		'data.sample' => array(
			// add your sample data XML files here,
		),
		
		// Documentation
		//
		'doc.manual_setup' => '', // hyperlink to manual setup documentation, if any
		'doc.more_information' => '', // hyperlink to more information, if any 

		// Default settings
		//
		'settings' => array(
			// Module specific settings go here, if any
			'enabled' => false,
			// Enter here your phone number with country code but without + or 00, ie: 34123456789
			'username' => '',
			// The password that you got when you were registering the phone number
			'password' => '',
			// Your nickname, it will appear in push notifications
			'nickname' => ''
		),
	)
);


?>
