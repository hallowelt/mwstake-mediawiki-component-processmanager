<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '2.0.7' );

Bootstrapper::getInstance()
	->register( 'processmanager', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';

		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = static function ( DatabaseUpdater $updater ) {
			$dbType = $updater->getDB()->getType();
			$updater->addExtensionTable( 'processes', __DIR__ . "/db/$dbType/processes.sql" );

			$updater->addExtensionField(
				'processes',
				'p_last_completed_step',
				__DIR__ . "/db/$dbType/processes_last_completed_step_patch.sql"
			);
		};
	} );
