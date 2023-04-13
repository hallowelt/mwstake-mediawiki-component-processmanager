<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '2.0.3' );

Bootstrapper::getInstance()
	->register( 'processmanager', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = static function ( DatabaseUpdater $updater ) {
			$updater->addExtensionTable( 'processes', __DIR__ . '/db/processes.sql' );
		};
	} );
