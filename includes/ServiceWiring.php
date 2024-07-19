<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\IProcessQueue;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

return [
	'ProcessManager' => static function ( MediaWikiServices $services ) {
		return new ProcessManager( $services->getService( 'ProcessManager.Queue' ) );
	},
	'ProcessManager.Queue' => static function ( MediaWikiServices $services ) {
		$queue = $services->getObjectFactory()->createObject( $GLOBALS['mwsgProcessManagerQueue'] );
		if ( !$queue instanceof IProcessQueue ) {
			throw new RuntimeException( 'Invalid process queue configuration' );
		}
		return $queue;
	}
];
