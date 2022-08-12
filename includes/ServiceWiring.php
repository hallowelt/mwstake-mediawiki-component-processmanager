<?php

use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

return [
	'ProcessManager' => static function ( \MediaWiki\MediaWikiServices $services ) {
		return new ProcessManager( $services->getDBLoadBalancer(),
		$GLOBALS['mwscProcessManagerAdditonalExecutionCLIScriptArgs'] );
	}
];
