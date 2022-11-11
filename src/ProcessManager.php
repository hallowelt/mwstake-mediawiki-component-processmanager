<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use DateInterval;
use DateTime;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Wikimedia\Rdbms\ILoadBalancer;

class ProcessManager {

	/** @var LoggerInterface */
	private $logger;

	/** @var ILoadBalancer */
	private ILoadBalancer $loadBalancer;
	/** @var int Number of minutes after which to delete processes */
	private $garbageInterval = 600;
	/** @var array Any additional script arguments passed down from the calling instance */
	private $extraArgs;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param array $extraArgs
	 */
	public function __construct( ILoadBalancer $loadBalancer, array $extraArgs ) {
		$this->loadBalancer = $loadBalancer;
		$this->extraArgs = $extraArgs;
		$this->logger = LoggerFactory::getInstance( 'processmanager-process-manager' );
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	public function getProcessInfo( $pid ): ?ProcessInfo {
		return $this->loadProcess( $pid );
	}

	/**
	 * @param string $pid
	 * @return string|null
	 */
	public function getProcessStatus( $pid ): ?string {
		$processInfo = $this->loadProcess( $pid );
		if ( $processInfo ) {
			return $processInfo->getState();
		}

		return null;
	}

	/**
	 * @param ManagedProcess $process
	 * @param array|null $data
	 * @return string
	 */
	public function startProcess( ManagedProcess $process, $data = [] ): string {
		return $process->start( $this, $data, null, $this->extraArgs );
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	private function loadProcess( $pid ): ?ProcessInfo {
		$this->garbageCollect();

		$this->logger->info(
			"Loading process {pid}", [
				'key' => $pid,
			]
		);

		$row = $this->loadBalancer->getConnection( DB_REPLICA )->selectRow(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output',
				'p_steps'
			],
			[
				'p_pid' => $pid
			],
			__METHOD__
		);

		if ( !$row ) {
			return null;
		}
		$info = ProcessInfo::newFromRow( $row );
		if ( $info->getState() === Process::STATUS_STARTED && $this->isTimeoutReached( $info ) ) {
			if ( $this->recordFinish( $info->getPid(), 152, 'Execution time too long' ) ) {
				$this->logger->warning(
					"Process {pid} with status {status} took too long to execute", [
						'key' => $info->getPid(),
						'status' => $this->getProcessStatus( $info->getPid() )
					]
				);
				return $this->loadProcess( $info->getPid() );
			}

		}

		$this->logger->info(
			"Process {pid} loaded and current status is {status}", [
				'key' => $pid,
				'status' => $info->getState(),
			]
		);

		return $info;
	}

	/**
	 * Record end of the process
	 *
	 * @param string $pid
	 * @param int $exitCode
	 * @param string $exitStatus
	 * @param array|null $data
	 * @return bool
	 */
	public function recordFinish( $pid, int $exitCode, string $exitStatus = '', $data = [] ) {
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_TERMINATED,
			'p_exitcode' => $exitCode,
			'p_exitstatus' => $exitStatus,
			'p_output' => json_encode( $data )
		] );
	}

	/**
	 * @param string $pid
	 * @param string $lastStep
	 * @param array $data
	 * @return bool
	 */
	public function recordInterrupt( $pid, $lastStep, $data ) {
		return $this->updateInfo( $pid, [
			'p_state' => InterruptingProcessStep::STATUS_INTERRUPTED,
			'p_output' => json_encode( [
				'lastStep' => $lastStep,
				'data' => $data,
			] )
		] );
	}

	/**
	 * @param string $pid
	 * @param array|null $data
	 * @return string
	 * @throws \Exception
	 */
	public function proceed( $pid, $data = [] ) {
		$info = $this->getProcessInfo( $pid );
		if ( !$info ) {
			throw new \Exception( 'Process with PID ' . $pid . ' does not exist' );
		}
		if ( $info->getState() !== InterruptingProcessStep::STATUS_INTERRUPTED ) {
			throw new \Exception( 'Process was not previously interrupted' );
		}
		$steps = $info->getSteps();
		$lastData = $info->getOutput();
		$lastStep = $lastData['lastStep'] ?? null;
		$lastData = $lastData['data'] ?? [];
		if ( !$lastStep ) {
			throw new \Exception( 'No last step information available' );
		}
		$remainingSteps = [];
		$found = false;
		foreach ( $steps as $name => $spec ) {
			if ( $name === $lastStep ) {
				$found = true;
				continue;
			}
			if ( $found ) {
				$remainingSteps[$name] = $spec;
			}
		}

		if ( empty( $remainingSteps ) ) {
			$this->recordFinish( $pid, 0, 'No steps left after proceeding', $lastData );
			return $pid;
		}

		$newProcess = new ManagedProcess( $remainingSteps, $info->getTimeout() );
		return $newProcess->start( $this, array_merge( $lastData, $data ), $pid );
	}

	/**
	 * Record starting of the process
	 *
	 * @param string $pid
	 * @param array $steps
	 * @param int $timeout
	 * @return bool
	 */
	public function recordStart( $pid, $steps, $timeout ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$info = $this->getProcessInfo( $pid );
		if (
			$info instanceof ProcessInfo &&
			$info->getState() === InterruptingProcessStep::STATUS_INTERRUPTED
		) {
			// Continue interrupted process
			return $this->updateInfo( $pid, [
				'p_state' => Process::STATUS_STARTED,
			] );
		}

		return $db->insert(
			'processes',
			[
				'p_pid' => $pid,
				'p_state' => Process::STATUS_STARTED,
				'p_timeout' => $timeout,
				'p_started' => $db->timestamp( ( new DateTime() )->format( 'YmdHis' ) ),
				'p_steps' => json_encode( $steps )
			],
			__METHOD__
		);
	}

	/**
	 * @param string $pid
	 * @param array $data
	 * @return bool
	 */
	private function updateInfo( $pid, array $data ) {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$success = $db->update(
			'processes',
			$data,
			[ 'p_pid' => $pid ],
			__METHOD__
		);

		$this->logger->info(
			"Process {pid} update info status: {status}", [
				'key' => $pid,
				'status' => $success,
			]
		);

		return $success;
	}

	/**
	 * @param ProcessInfo $info
	 * @return bool
	 */
	private function isTimeoutReached( ProcessInfo $info ): bool {
		$start = $info->getStartDate();
		$maxTime = $start->add( new DateInterval( "PT{$info->getTimeout()}S" ) );

		return new DateTime() > $maxTime;
	}

	/**
	 * Delete all processes older than 1h
	 */
	private function garbageCollect() {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$hourAgo = ( new DateTime() )->sub( new DateInterval( "PT{$this->garbageInterval}M" ) );

		$this->logger->info( "Running process garbage collection at " . date( 'Y-m-d H:i:s' ) );

		$db->delete(
			'processes',
			[
				'p_started < ' . $db->timestamp( $hourAgo->format( 'YmdHis' ) )
			],
			__METHOD__
		);
	}

}
