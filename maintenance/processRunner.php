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
		$this->manager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
		$runnerId = $this->getRunnerId();
		if ( $this->isRunnerRunning( $runnerId ) ) {
			$this->output( "ProcessRunner with these arguments is already running\n" );
			exit();
		}
		$this->output( "Starting ProcessRunner\n" );
		$this->storeProcessRunnerId( $runnerId, getmypid() );
		$this->output( "Using queue: " . get_class( $this->manager->getQueue() ) . "\n" );

		$this->logger->info( 'Starting process runner, queue: ' . get_class( $this->manager->getQueue() ) );
		$maxJobs = (int)$this->getOption( 'max-processes', 0 );
		if ( $this->hasOption( 'wait' ) ) {
			$this->logger->info( 'Waiting for incoming processes in queue' );
			while ( true ) {
				$this->runBatch( $maxJobs );
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
			$this->logger->error( $err );
			$this->manager->recordFinish(
				$info->getPid(), 1, $err
			);
			return;
		}

		$extraArgs = array_merge( $this->getAdditionalArgsFromProcess( $info ), $this->getExternalArgs() );
		if ( $extraArgs ) {
			$this->output( " ...with additional args: " . implode( ' ', $extraArgs ) . '...' );
		}
		$process = new Symfony\Component\Process\Process(
			array_merge(
				[ $phpBinaryPath, __DIR__ . '/processExecution.php', $argv[1] ],
				$extraArgs
			)
		);
		$input = new InputStream();
		$process->setInput( $input );
		$input->write( json_encode( [
			'steps' => $info->getSteps(), 'data' => $info->getOutput(), 'pid' => $info->getPid()
		] ) );
		$this->logger->debug( 'Process command: ' . $process->getCommandLine() );
		$this->logger->debug( 'Input data: ' . json_encode( [
			'steps' => $info->getSteps(), 'data' => $info->getOutput(), 'pid' => $info->getPid()
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
				$this->logger->debug( 'Interrupted with: ' . json_encode( $data ) );
				$this->output( "Interrupted\n" );
				return;
			}
			$this->manager->recordFinish( $info->getPid(), 0, 'success', $data );
			$this->logger->info( 'Process finished' );
			$this->logger->debug( 'Output: ' . json_encode( $data ) );
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

	/**
	 * Generate unique runner id
	 * This id includes passed `script-args` to allow for different runners to be
	 * started with different arguments in parallel
	 * @return string
	 */
	private function getRunnerId(): string {
		// Path to mainenance script
		$id = md5( $this->parameters->getArg( 0 ) );
		if ( $this->hasOption( 'script-args' ) ) {
			$value = $this->getOption( 'script-args' );
			$value = str_replace( ' ', '', $value );
			$value = str_replace( '-', '', $value );
			$id .= '#' . md5( $value );
		}
		return $id;
	}

	/**
	 * Check is ProcessRunner is running
	 * @param string $id Runner id
	 *
	 * @return bool
	 */
	private function isRunnerRunning( $id ): bool {
		$file = sys_get_temp_dir() . '/process-runner.pid';
		if ( !file_exists( $file ) ) {
			return false;
		}
		$fileData = json_decode( file_get_contents( $file ), true );
		if ( !$fileData ) {
			return false;
		}
		if ( !isset( $fileData[$id] ) ) {
			return false;
		}

		$pid = (int)$fileData[$id];
		if ( wfIsWindows() ) {
			return $this->isWindowsPidRunning( $pid );
		}
		return (bool)posix_getsid( $pid );
	}

	/**
	 * Store PID of the ProcessRunner instance
	 * @param string $id Runner id
	 * @param int $pid Process id for the runner
	 *
	 * @return bool
	 */
	private function storeProcessRunnerId( string $id, int $pid ): bool {
		$file = sys_get_temp_dir() . '/process-runner.pid';

		$data = [];
		if ( file_exists( $file ) ) {
			$fileData = json_decode( file_get_contents( $file ), true );
			if ( is_array( $fileData ) ) {
				$data = $fileData;
			}
		}
		$data[$id] = $pid;
		return (bool)file_put_contents( $file, json_encode( $data ) );
	}

	/**
	 * @param string|int $pid
	 *
	 * @return bool
	 */
	private function isWindowsPidRunning( $pid ): bool {
		$taskList = [];
		// @codingStandardsIgnoreStart
		exec( "tasklist 2>NUL", $taskList );
		// @codingStandardsIgnoreEnd
		foreach ( $taskList as $line ) {
			// Get PID
			$line = preg_replace( '/\s+/', ' ', $line );
			$line = explode( ' ', $line );
			$line = array_filter( $line );
			$line = array_values( $line );
			if ( count( $line ) < 2 ) {
				continue;
			}
			$pidLine = $line[1];
			if ( !is_numeric( $pidLine ) ) {
				continue;
			}
			$pidLine = (int)$pidLine;
			if ( $pidLine === (int)$pid ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array
	 */
	private function getExternalArgs(): array {
		$externalScriptArgs = $this->getOption( 'script-args' );
		return $externalScriptArgs ? explode( ' ', $externalScriptArgs ) : [];
	}

	/**
	 * @param ProcessInfo $info
	 * @return array
	 */
	private function getAdditionalArgsFromProcess( ProcessInfo $info ): array {
		$formatted = [];
		$args = $info->getAdditionalArgs();
		if ( !$args ) {
			return [];
		}
		foreach ( $args as $key => $value ) {
			$formatted[] = "--$key";
			$formatted[] = $value;
		}

		return $formatted;
	}
}

$maintClass = 'ProcessRunner';
require_once RUN_MAINTENANCE_IF_MAIN;
