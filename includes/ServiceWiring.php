<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\IProcessQueue;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

return [
	'ProcessManager' => static function ( MediaWikiServices $services ) {
		$plugins = $GLOBALS['mwsgProcessManagerPlugins'];
		$pluginObjects = [];
		foreach ( $plugins as $plugin ) {
			$pluginObjects[] = $services->getObjectFactory()->createObject( $plugin );
		}
		$pluginObjects = array_filter( $pluginObjects, static function ( $plugin ) {
			return $plugin instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessManagerPlugin;
		} );
		return new ProcessManager( $services->getService( 'ProcessManager.Queue' ), $pluginObjects );
	},
	'ProcessManager.Queue' => static function ( MediaWikiServices $services ) {
		$queue = $services->getObjectFactory()->createObject( $GLOBALS['mwsgProcessManagerQueue'] );
		if ( !$queue instanceof IProcessQueue ) {
			throw new RuntimeException( 'Invalid process queue configuration' );
		}
		return $queue;
	}
];
