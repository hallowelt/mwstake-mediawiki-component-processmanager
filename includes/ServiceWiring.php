<?php

use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

return [
	'ProcessManager' => static function ( \MediaWiki\MediaWikiServices $services ) {
		$queue = $services->getObjectFactory()->createObject( $GLOBALS['mwsgProcessManagerQueue'] );
		if ( !$queue instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessQueue ) {
			throw new RuntimeException( 'Invalid process queue' );
		}
		return new ProcessManager( $queue );
	}
];
