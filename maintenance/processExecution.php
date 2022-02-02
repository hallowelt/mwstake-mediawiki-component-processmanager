<?php

require_once( $argv[1] );
require_once( $argv[2] );

class ProcessExecution extends Maintenance {
	/** @var array */
	private $steps = [];

	public function execute() {
		global $argv;
		$pid = $argv[3];

		$manager = new \MWStake\MediaWiki\Component\ProcessManager\ProcessManager(
			\MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancerFactory()->newMainLB()
		);

		try {
			$input = $this->getStdin();
			if ( !$input ) {
				throw new Exception( 'No input provided' );
			}
			$content = stream_get_contents( $input );
			$decoded = json_decode( $content, 1 );
			$code = json_last_error();
			if ( $code ) {
				throw new Exception( "Cannot parse steps: {$code}");
			}
			$this->steps = $decoded;

			$this->executeSteps();
		} catch ( Exception $ex ) {
			$manager->recordFinish( $pid, 1, $ex->getMessage() );
			exit();
		}

		$manager->recordFinish( $pid, 0 );
	}

	private function executeSteps() {
		$of = \MediaWiki\MediaWikiServices::getInstance()->getObjectFactory();
		$steps = [];
		foreach ( $this->steps as $name => $spec ) {
			$object = $of->createObject( $spec );
			if ( !( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessStep ) ) {
				throw new Exception(
					"Specification of step \"$name\" does not produce object of type " .
					\MWStake\MediaWiki\Component\ProcessManager\IProcessStep::class
				);
			}
			$steps[] = $object;
		}
		if ( empty( $steps ) ) {
			throw new Exception( 'No steps specified' );
		}

		// Now we actually execute steps, this is done separately, so we make sure all steps
		// are valid before the execution starts
		foreach ( $steps as $step ) {
			$step->execute();
		}
	}
}

$maintClass = 'ProcessExecution';
require_once( RUN_MAINTENANCE_IF_MAIN );
