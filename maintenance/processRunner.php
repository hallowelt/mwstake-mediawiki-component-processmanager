<?php

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;
use Symfony\Component\Process\InputStream;

require_once $argv[1];

class ProcessRunner extends Maintenance {
	/**
	 * @var ProcessManager
	 */
	private $manager;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'wait', 'Wait for incoming processes in queue' );
		$this->addOption(
			'max-processes', 'Max number of processes to start before killing runner'
		);
	}

	public function execute() {
		$this->manager = new ProcessManager(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);

		$maxJobs = (int) $this->getOption( 'max-processes', 0 );
		$executedJobs = 0;
		if ( $this->hasOption( 'wait' ) ) {
			while ( true ) {
				$executedJobs += $this->runBatch( $maxJobs );
				sleep( 1 );
			}
		} else {
			$this->runBatch( $maxJobs );
		}
	}

	public function runBatch( int $max ) {
		$cnt = 0;
		/** @var ProcessInfo $info */
		foreach ( $this->manager->getEnqueuedProcesses() as $info ) {
			if ( $max > 0 && $cnt >= $max ) {
				break;
			}
			$cnt++;
			$this->executeProcess( $info );
		}
		return $cnt;
	}

	private function executeProcess( ProcessInfo $info ) {
		global $argv;

		$this->output( "Starting process {$info->getPid()}..." );
		$phpBinaryPath = $GLOBALS['wgPhpCli'];
		if ( !file_exists( $phpBinaryPath ) ) {
			$err = "PHP executable cannot be found. Check if \$wgPhpCli global is correctly set";
			$this->manager->recordFinish(
				$info->getPid(), 1, $err
			);
			return;
		}

		$process = new Symfony\Component\Process\Process( [
			$phpBinaryPath, __DIR__ . '/processExecution.php', $argv[1]
		] );
		$input = new InputStream();
		$process->setInput( $input );
		$input->write( json_encode( [
			'steps' => $info->getSteps(), 'data' => $info->getOutput()
		] ) );
		$process->setTimeout( $info->getTimeout() );
		$process->start();
		$input->close();

		$process->wait();
		if ( $process->isSuccessful() ) {
			$data = $process->getOutput();
			$data = json_decode( $data, 1 );
			if ( isset( $data['interrupt' ] ) ) {
				$this->manager->recordInterrupt( $info->getPid(), $data['interrupt'], $data['data'] );
				$this->output( "Interrupted\n" );
				return;
			}
			$this->manager->recordFinish( $info->getPid(), 0, '', $data );
			$this->output( "Finished\n" );
			return;
		}

		$errorOut = $process->getErrorOutput();
		$this->manager->recordFinish(
			$info->getPid(), $process->getExitCode(), "failed", $errorOut
		);
		$this->output( "Failed\n" );
	}
}

$maintClass = 'ProcessRunner';
require_once RUN_MAINTENANCE_IF_MAIN;
