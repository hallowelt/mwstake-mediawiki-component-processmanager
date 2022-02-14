<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '1.0.0' );

\MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
	->register( 'processmanager', function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = function ( DatabaseUpdater $updater ) {
			$updater->addExtensionTable( 'processes', __DIR__ . '/db/processes.sql' );
		};
	} );
