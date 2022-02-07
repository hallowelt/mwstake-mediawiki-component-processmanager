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
				throw new Exception( "Cannot parse steps: {$code}");
			}
			$this->steps = $decoded;

			$data = $this->executeSteps();
		} catch ( Exception $ex ) {
			$manager->recordFinish( $pid, 1, $ex->getMessage(), $ex->getPrevious()->getTrace() );
			exit();
		}

		$manager->recordFinish( $pid, 0, '', $data );
	}

	private function executeSteps() {

		$data = [];
		foreach ( $this->steps as $name => $spec ) {
			$of = \MediaWiki\MediaWikiServices::getInstance()->getObjectFactory();
			$object = $of->createObject( $spec );
			if ( !( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessStep ) ) {
				throw new Exception(
					"Specification of step \"$name\" does not produce object of type " .
					\MWStake\MediaWiki\Component\ProcessManager\IProcessStep::class
				);
			}
			try {
				$data = $object->execute( $data );
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
require_once( RUN_MAINTENANCE_IF_MAIN );
