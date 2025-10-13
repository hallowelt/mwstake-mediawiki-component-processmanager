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
	/** @var int|null */
	private $lastPluginRun = null;
	/** @var string */
	private string $uuid = '';

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
		$this->output( "Starting ProcessRunner\n" );
		$this->output( "Using queue: " . get_class( $this->manager->getQueue() ) . "\n" );
		$plugins = $this->manager->getPlugins();
		if ( !empty( $plugins ) ) {
			$this->output( "Using plugins: " . implode( ', ', array_map( static function ( $plugin ) {
				return $plugin->getKey();
			}, $plugins ) ) . "\n" );
		}
		// Process unique ID
		$this->uuid = uniqid( 'prc', true );

		$this->logger->info( 'Starting process runner, queue: {queue}, plugins: {plugins}', [
			'queue' => get_class( $this->manager->getQueue() ),
			'plugins' => implode( ', ', array_map( static function ( $plugin ) {
				return $plugin->getKey();
			}, $plugins ) )
		] );
		$maxJobs = (int)$this->getOption( 'max-processes', 0 );
		$executed = 0;
		$shouldWait = $this->hasOption( 'wait' );

		while ( true ) {
			$this->runPlugins();
			$nextProcess = $this->manager->pluckOneFromQueue();
			if ( $nextProcess ) {
				$this->executeProcess( $nextProcess );
				$executed++;
			} elseif ( $shouldWait ) {
				sleep( 1 );
			} else {
				break;
			}
		}

		if ( $maxJobs ) {
			$this->output( "Executed $executed processes, max limit of $maxJobs reached, exiting\n" );
		} else {
			$this->output( "No more processes in queue, executed $executed processes, exiting\n" );
		}
	}

	/**
	 * @return void
	 */
	private function runPlugins(): void {
		if ( $this->lastPluginRun === null || $this->lastPluginRun < time() - 60 ) {
			$this->logger->debug( 'Running plugins' );
			foreach ( $this->manager->getPlugins() as $plugin ) {
				if ( !$this->manager->claimPlugin( $plugin, $this->uuid ) ) {
					// Another instance is taking care this plugin now
					continue;
				}
				if ( $plugin instanceof \Psr\Log\LoggerAwareInterface ) {
					$plugin->setLogger( $this->logger );
				}

				$this->logger->info( "Running plugin: " . $plugin->getKey() );
				$pluginProcesses = $plugin->run( $this->manager, $this->lastPluginRun );

				$this->logger->info( '*** Scheduled {count} processes from plugin: {pluginKey} ***', [
					'count' => count( $pluginProcesses ),
					'pluginKey' => $plugin->getKey()
				] );
				if ( empty( $pluginProcesses ) ) {
					continue;
				}
				$this->logger->info( '**************************************' );
			}
			$this->lastPluginRun = time();
		}
	}

	/**
	 * @param ProcessInfo $info
	 *
	 * @return void
	 */
	private function executeProcess( ProcessInfo $info ) {
		global $argv;

		$this->logger->info( 'Starting process: ' . $info->getPid() );
		$this->manager->recordStart( $info->getPid() );
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
