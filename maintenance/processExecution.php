<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep;
use MWStake\MediaWiki\Component\ProcessManager\IProcessStep;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;

require_once $argv[1];

class ProcessExecution extends Maintenance {
	public function __construct() {
		parent::__construct();
		// TODO: Run as service, limit...
	}

	public function execute() {
		$manager = new ProcessManager(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		/** @var ProcessInfo $info */
		foreach ( $manager->getEnqueuedProcesses() as $info ) {
			$this->output( "Starting process {$info->getPid()}..." );
			try {
				$steps = $info->getSteps();
				$data = $info->getOutput();
				$data = $this->executeSteps( $steps, $data );
				if ( isset( $data['interrupt' ] ) ) {
					$manager->recordInterrupt( $info->getPid(), $data['interrupt'], $data['data'] );
					$this->output( "Interrupted\n" );
					continue;
				}
				$manager->recordFinish( $info->getPid(), 0, '', $data );
				$this->output( "Finished\n" );
			} catch ( Exception $e ) {
				$manager->recordFinish(
					$info->getPid(), 1, $e->getMessage(), $e->getPrevious()->getTrace()
				);
				$this->output( "Failed\n" );
			}
		}
	}

	private function executeSteps( $steps, $data ) {
		$of = MediaWikiServices::getInstance()->getObjectFactory();
		foreach ( $steps as $name => $spec ) {
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
