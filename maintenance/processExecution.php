<?php

use MediaWiki\MediaWikiServices;

require_once $argv[1];

class ProcessExecution extends Maintenance {
	/** @var array */
	private $steps = [];
	/** @var array */
	private $initData = [];

	public function execute() {
		global $argv;
		$pid = $argv[2];

		$manager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$data = [];
		try {
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
			$data = $this->executeSteps();
			if ( isset( $data['interrupt' ] ) ) {
				$manager->recordInterrupt( $pid, $data['interrupt'], $data['data'] );
				exit();
			}
		} catch ( Exception $ex ) {
			$manager->recordFinish( $pid, 1, $ex->getMessage(), $ex->getPrevious()->getTrace() );
			exit();
		}

		$manager->recordFinish( $pid, 0, '', $data );
	}

	private function executeSteps() {
		$data = $this->initData;
		$of = \MediaWiki\MediaWikiServices::getInstance()->getObjectFactory();
		foreach ( $this->steps as $name => $spec ) {
			try {
				$object = $of->createObject( $spec );
				if ( !( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessStep ) ) {
					throw new Exception(
						"Specification of step \"$name\" does not produce object of type " .
						\MWStake\MediaWiki\Component\ProcessManager\IProcessStep::class
					);
				}

				$data = $object->execute( $data );
				if ( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep ) {
					return [
						'interrupt' => $name,
						'data' => $data ?? [],
					];
				}
			} catch ( Exception $ex ) {
				throw new Exception(
					"Step \"$name\" failed: " . $ex->getMessage(),
					$ex->getCode(),
					$ex
				);
			}
		}
		return $data;
	}
}

$maintClass = 'ProcessExecution';
require_once RUN_MAINTENANCE_IF_MAIN;
