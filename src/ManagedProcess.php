<?php

namespace  MWStake\MediaWiki\Component\ProcessManager;

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
	 * @param string[] $addInfo Additional information can be passed inside the array or [] if none
	 * @return string ProcessID
	 */
	public function start( ProcessManager $manager, $data = [], $pid = null, $addInfo = [] ) {
		$scriptPath = dirname( __DIR__ ) . '/maintenance/processExecution.php';
		$maintenancePath = $GLOBALS['IP'] . '/maintenance/Maintenance.php';

		$pid = $pid ?? md5( rand( 1, 9999999 ) + ( new \DateTime() )->getTimestamp() );
		$manager->recordStart( $pid, $this->steps, $this->timeout );
		$phpBinaryPath = $GLOBALS['wgPhpCli'];
		if ( !file_exists( $phpBinaryPath ) ) {
			$manager->recordFinish(
				$pid, 1, "PHP executable cannot be found"
			);
			return $pid;
		}

		if ( !file_exists( $maintenancePath ) ) {
			$manager->recordFinish(
				$pid, 1, "Path does not exist: $maintenancePath"
			);
			return $pid;
		}

		$this->parentProcess = new AsyncProcess( [
			$phpBinaryPath, $scriptPath, $maintenancePath, $pid ] + $addInfo );
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
