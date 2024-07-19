<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;
use MWStake\MediaWiki\Component\ProcessManager\StepExecutor;

require_once $argv[1];

class ProcessExecution extends Maintenance {
	/** @var string|null */
	private $pid;
	/** @var array */
	private $steps = [];
	/** @var array */
	private $initData = [];

	public function execute() {
		$input = $this->getStdin();
		if ( !$input ) {
			throw new Exception( 'No input provided' );
		}
		$content = stream_get_contents( $input );
		$decoded = json_decode( $content, 1 );
		$code = json_last_error();
		if ( $code ) {
			throw new Exception( "Cannot parse steps: {$code}" );
		}
		$this->steps = $decoded['steps'];
		$this->initData = $decoded['data'] ?? [];
		$this->pid = $decoded['pid'] ?? null;
		$data = $this->executeSteps();
		$this->output( json_encode( $data ) );
	}

	private function executeSteps() {
		$manager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$executor = new StepExecutor(
			MediaWikiServices::getInstance()->getObjectFactory()
		);
		return $executor->execute( $this->steps, $this->initData, $manager, $this->pid );
	}
}

$maintClass = 'ProcessExecution';
require_once RUN_MAINTENANCE_IF_MAIN;
