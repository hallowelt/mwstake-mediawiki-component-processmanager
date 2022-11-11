<?php

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '1.2.5' );

\MWStake\MediaWiki\ComponentLoader\Bootstrapper::getInstance()
	->register( 'processmanager', function () {
		$GLOBALS['mwscProcessManagerAdditonalExecutionCLIScriptArgs'] =
			$GLOBALS['mwscProcessManagerAdditonalExecutionCLIScriptArgs'] ?? [];
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';
		$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = function ( DatabaseUpdater $updater ) {
			$updater->addExtensionTable( 'processes', __DIR__ . '/db/processes.sql' );
		};
	} );
