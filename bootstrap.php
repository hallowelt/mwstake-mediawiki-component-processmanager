<?php

use MWStake\MediaWiki\ComponentLoader\Bootstrapper;

if ( defined( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION' ) ) {
	return;
}

define( 'MWSTAKE_MEDIAWIKI_COMPONENT_PROCESSMANAGER_VERSION', '5.0.0' );

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
				$updater->addExtensionField(
					'processes',
					'p_claimed_by',
					__DIR__ . "/db/$dbType/patch_process_claim.sql"
				);
			} );
		};

	if ( !isset( $GLOBALS['mwsgProcessManagerQueueConfig'] ) ) {
		$GLOBALS['mwsgProcessManagerQueueConfig'] = [];
	}
		$GLOBALS['mwsgProcessManagerQueueConfig']['local'] = [
			'class' => 'MWStake\MediaWiki\Component\ProcessManager\ProcessQueue\SimpleDatabaseQueue',
			'services' => [ "DBLoadBalancer" ]
		];

		if ( !isset( $GLOBALS['mwsgProcessManagerQueue'] ) ) {
			$GLOBALS['mwsgProcessManagerQueue'] = 'local';
		}

		if ( isset( $GLOBALS['mwsgProcessManagerPlugins'] ) ) {
			$GLOBALS['mwsgProcessManagerPlugins'] = [];
		}
	} );
