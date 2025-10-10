<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '4.0.3' );

Bootstrapper::getInstance()
	->register( 'processmanager', static function () {
		$GLOBALS['wgServiceWiringFiles'][] = __DIR__ . '/includes/ServiceWiring.php';

		$GLOBALS['wgExtensionFunctions'][] = static function () {
			$hookContainer = \MediaWiki\MediaWikiServices::getInstance()->getHookContainer();
			$hookContainer->register( 'LoadExtensionSchemaUpdates', static function ( DatabaseUpdater $updater ) {
				$dbType = $updater->getDB()->getType();

				$updater->addExtensionTable( 'processes', __DIR__ . "/db/$dbType/processes.sql" );
				$updater->addExtensionTable(
					'process_plugin_lock', __DIR__ . "/db/$dbType/process_plugin_lock.sql"
				);

				$updater->addExtensionField(
					'processes',
					'p_last_completed_step',
					__DIR__ . "/db/$dbType/patch_last_completed_step.sql"
				);
				$updater->addExtensionField(
					'processes',
					'p_additional_script_args',
					__DIR__ . "/db/$dbType/patch_additional_args.sql"
				);
			} );
		};

		$GLOBALS['mwsgProcessManagerQueue'] = [
			'class' => 'MWStake\MediaWiki\Component\ProcessManager\ProcessQueue\SimpleDatabaseQueue',
			'services' => [ "DBLoadBalancer" ]
		];
		$GLOBALS['mwsgProcessManagerPlugins'] = [];
	} );
