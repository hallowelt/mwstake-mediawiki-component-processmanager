<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use DateInterval;
use DateTime;
use Symfony\Component\Process\Process;
use Wikimedia\Rdbms\ILoadBalancer;

class ProcessManager {
	private ILoadBalancer $loadBalancer;
	/** @var int Number of minutes after which to delete processes */
	private $garbageInterval = 600;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
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
	 * @return string
	 */
	public function startProcess( ManagedProcess $process ): string {
		return $process->start( $this );
	}

	/**
	 * @param string $pid
	 * @return ProcessInfo|null
	 */
	private function loadProcess( $pid ): ?ProcessInfo {
		$this->garbageCollect();

		$row = $this->loadBalancer->getConnection( DB_REPLICA )->selectRow(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started',
				'p_timeout',
				'p_output'
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
				return $this->loadProcess( $info->getPid() );
			}

		}

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
	 * Record starting of the process
	 *
	 * @param string $pid
	 * @param int $timeout
	 * @return bool
	 */
	public function recordStart( $pid, $timeout ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$res = $db->insert(
			'processes',
			[
				'p_pid' => $pid,
				'p_state' => Process::STATUS_STARTED,
				'p_timeout' => $timeout,
				'p_started' => $db->timestamp( ( new DateTime() )->format( 'YmdHis' ) )
			],
			__METHOD__
		);

		return $res;
	}

	/**
	 * @param string $pid
	 * @param array $data
	 * @return bool
	 */
	private function updateInfo( $pid, array $data ) {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $db->update(
			'processes',
			$data,
			[ 'p_pid' => $pid ],
			__METHOD__
		);
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
		$db->delete(
			'processes',
			[
				'p_started < ' . $db->timestamp( $hourAgo->format( 'YmdHis' ) )
			],
			__METHOD__
		);
	}

}
