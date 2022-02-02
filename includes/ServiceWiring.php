<?php

return [
	'ProcessManager' => static function( \MediaWiki\MediaWikiServices $services ) {
		return new MWStake\MediaWiki\Component\ProcessManager\ProcessManager( $services->getDBLoadBalancer() );
	}
];
