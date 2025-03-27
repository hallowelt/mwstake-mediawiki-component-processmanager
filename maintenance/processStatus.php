<?php

use MediaWiki\MediaWikiServices;

require_once $argv[1];

class ProcessStatus extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addArg( 'pid', 'Process ID', true );
	}

	public function execute() {
		$pid = $this->getArg( 1 );
		$manager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$info = $manager->getProcessInfo( $pid );
		if ( !$info ) {
			$this->output( "Process not found\n" );
			exit();
		}
		$this->output( json_encode( $info, JSON_PRETTY_PRINT ) . "\n" );
	}
}

$maintClass = 'ProcessStatus';
require_once RUN_MAINTENANCE_IF_MAIN;
