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
			$manager->recordFinish( $pid, 1, $ex->getMessage() );
			exit();
		}

		$manager->recordFinish( $pid, 0, '', $data );
	}

	private function executeSteps() {
		$of = \MediaWiki\MediaWikiServices::getInstance()->getObjectFactory();
		$data = [];
		foreach ( $this->steps as $name => $spec ) {
			$object = $of->createObject( $spec );
			if ( !( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessStep ) ) {
				throw new Exception(
					"Specification of step \"$name\" does not produce object of type " .
					\MWStake\MediaWiki\Component\ProcessManager\IProcessStep::class
				);
			}
			$data = $object->execute( $data );
		}
		return $data;
	}
}

$maintClass = 'ProcessExecution';
require_once( RUN_MAINTENANCE_IF_MAIN );
