<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

use Exception;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class ManagedProcess {
	/** @var Process|null */
	private $parentProcess = null;
	/** @var array */
	private $steps;
	/** @var int */
	private $timeout;

	/**
	 * @param array $steps
	 * @param int|null $timeout
	 */
	public function __construct( array $steps, ?int $timeout = 60 ) {
		$this->steps = $steps;
		$this->timeout = $timeout;
	}

	/**
	 * @param ProcessManager $manager
	 * @param array|null $data
	 * @param string|null $pid ID of the exsting process to continue
	 * @return string ProcessID
	 */
	public function start( ProcessManager $manager, $data = [], $pid = null ) {
		$scriptPath = dirname( __DIR__ ) . '/maintenance/processExecution.php';
		$maintenancePath = $GLOBALS['IP'] . '/maintenance/Maintenance.php';

		$pid = $pid ?? md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );
		$manager->recordStart( $pid, $this->steps, $this->timeout );
		$phpBinaryPath = $GLOBALS['wgPhpCli'];
		if ( !file_exists( $phpBinaryPath ) ) {
			$err = "PHP executable cannot be found. Please check if \$wgPhpCli global is correctly set";

			$manager->recordFinish(
				$pid, 1, $err
			);

			throw new Exception( $err );
		}

		if ( !file_exists( $maintenancePath ) ) {
			$err = "Maintenance path does not exist: $maintenancePath";

			$manager->recordFinish(
				$pid, 1, $err
			);

			throw new Exception( $err );
		}

		$this->parentProcess = new AsyncProcess( [
			$phpBinaryPath, $scriptPath, $maintenancePath, $pid
		] );
		$input = new InputStream();
		$input->write( json_encode( [ 'steps' => $this->steps, 'data' => $data ] ) );
		$this->parentProcess->setInput( $input );
		$this->parentProcess->setTimeout( $this->timeout );

		$this->parentProcess->start();
		$input->close();

		return $pid;
	}

	/**
	 * @return Process
	 */
	public function getParentProcess(): Process {
		return $this->parentProcess;
	}
}
