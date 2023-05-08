<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use MWStake\MediaWiki\Component\ProcessManager\ProcessManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\InputStream;

require_once $argv[1];

class ProcessRunner extends Maintenance {
	/**
	 * @var ProcessManager
	 */
	private $manager;
	/** @var LoggerInterface */
	private $logger;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'wait', 'Wait for incoming processes in queue' );
		$this->addOption(
			'max-processes', 'Max number of processes to start before killing runner'
		);
		$this->addOption( 'script-args', 'Arguments to pass to the script' );
	}

	public function execute() {
		$this->logger = LoggerFactory::getInstance( 'ProcessRunner' );
		$this->manager = new ProcessManager(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);
		if ( $this->manager->isRunnerRunning() ) {
			$this->output( "ProcessRunner is already running\n" );
			exit();
		}
		$this->output( "Starting ProcessRunner\n" );
		$this->manager->storeProcessRunnerId( getmypid() );

		$this->logger->info( 'Starting process runner' );
		$maxJobs = (int)$this->getOption( 'max-processes', 0 );
		$executedJobs = 0;
		if ( $this->hasOption( 'wait' ) ) {
			$this->logger->info( 'Waiting for incoming processes in queue' );
			while ( true ) {
				$executedJobs += $this->runBatch( $maxJobs );
				sleep( 1 );
			}
		} else {
			$this->logger->info( 'Running processes in queue and exiting after' );
			$this->runBatch( $maxJobs );
		}
	}

	/**
	 * @param int $max
	 * @return int Number of processes executed
	 */
	public function runBatch( int $max ): int {
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

	/**
	 * @param ProcessInfo $info
	 *
	 * @return void
	 */
	private function executeProcess( ProcessInfo $info ) {
		global $argv;

		$this->logger->info( 'Starting process: ' . $info->getPid() );
		$this->output( "Starting process {$info->getPid()}..." );
		$phpBinaryPath = $GLOBALS['wgPhpCli'];
		if ( !file_exists( $phpBinaryPath ) ) {
			$err = "PHP executable cannot be found. Check if \$wgPhpCli global is correctly set";
			$this->manager->recordFinish(
				$info->getPid(), 1, $err
			);
			return;
		}

		$externalScriptArgs = $this->getOption( 'script-args' );
		$externalScriptArgs = $externalScriptArgs ? explode( ' ', $externalScriptArgs ) : [];
		$process = new Symfony\Component\Process\Process(
			array_merge(
				[ $phpBinaryPath, __DIR__ . '/processExecution.php', $argv[1] ],
				$externalScriptArgs
			)
		);
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
				$this->logger->info( 'Process interrupted' );
				$this->output( "Interrupted\n" );
				return;
			}
			$this->manager->recordFinish( $info->getPid(), 0, 'success', $data );
			$this->logger->info( 'Process finished' );
			$this->output( "Finished\n" );
			return;
		}

		$errorOut = $process->getErrorOutput();
		$this->manager->recordFinish(
			$info->getPid(), $process->getExitCode(), "failed", [ 'stack' => $errorOut ]
		);

		$this->logger->info( 'Process failed' );
		$this->logger->debug( $errorOut );
		$this->output( "Failed\n" );
	}
}

$maintClass = 'ProcessRunner';
require_once RUN_MAINTENANCE_IF_MAIN;
