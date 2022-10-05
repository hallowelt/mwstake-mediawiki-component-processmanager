<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep;
use MWStake\MediaWiki\Component\ProcessManager\IProcessStep;

require_once $argv[1];

class ProcessExecution extends Maintenance {
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
		$data = $this->executeSteps();
		$this->output( json_encode( $data ) );
	}

	private function executeSteps() {
		$data = $this->initData;
		$of = MediaWikiServices::getInstance()->getObjectFactory();
		foreach ( $this->steps as $name => $spec ) {
			try {
				$object = $of->createObject( $spec );
				if ( !( $object instanceof IProcessStep ) ) {
					throw new Exception(
						"Specification of step \"$name\" does not produce object of type " .
						IProcessStep::class
					);
				}

				$data = $object->execute( $data );
				if ( $object instanceof InterruptingProcessStep ) {
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
