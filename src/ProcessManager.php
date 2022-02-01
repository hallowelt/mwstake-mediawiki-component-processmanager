<?php

namespace MWStake\MediaWiki\Component\ProcessManager;


use DateTime;
use Symfony\Component\Process\Process;
use Wikimedia\Rdbms\ILoadBalancer;

class ProcessManager {
	private ILoadBalancer $loadBalancer;

	/**
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	public function getProcessInfo( int $pid ): ?ProcessInfo {
		return $this->loadProcess( $pid );
	}

	public function getProcessStatus( int $pid ): ?string {
		$processInfo = $this->loadProcess( $pid );
		if ( $processInfo ) {
			return $processInfo->getState();
		}

		return null;
	}

	public function startProcess( ManagedProcess $process ): int {
		return $process->start( $this );
	}

	public function stopProcess( int $pid ): bool {
		$info = $this->loadProcess( $pid );
		if ( !$info ) {
			return false;
		}
		if  (function_exists( 'posix_kill' ) ) {
			$status = posix_kill( $pid, 15 );
		} elseif ( function_exists('exec') && strstr( PHP_OS, 'WIN' ) ) {
			$status = exec( "taskkill /F /PID $pid" ) ? true : false;
		}
		if ( $status ) {
			return $this->updateInfo( $pid, [ 'p_state' => Process::STATUS_TERMINATED, 'p_exitcode' => 137 /*interrupt*/ ] );
		}

		return false;
	}

	private function loadProcess( int $pid ): ?ProcessInfo {
		$row = $this->loadBalancer->getConnection( DB_REPLICA )->selectRow(
			'processes',
			[
				'p_pid',
				'p_state',
				'p_exitcode',
				'p_exitstatus',
				'p_started'
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
		if ( $this->isTimeoutReached( $info ) ) {
			$this->recordFinish( $info->getPid(), 152, 'Timeout reached' );
			return $this->loadProcess( $info->getPid() );
		}

		return $info;
	}

	/**
	 * Record end of the process
	 *
	 * @param int $pid
	 * @param int $exitCode
	 * @param string $exitStatus
	 * @return bool
	 */
	public function recordFinish( $pid, int $exitCode, string $exitStatus = '' ) {
		if ( !$this->loadProcess( $pid ) ) {
			throw new \InvalidArgumentException( 'Process with PID ' . $pid . ' is not managed!' );
		}
		return $this->updateInfo( $pid, [
			'p_state' => Process::STATUS_TERMINATED,
			'p_exitcode' => $exitCode,
			'p_exitstatus' => $exitStatus
		] );
	}

	/**
	 * Record starting of the process
	 *
	 * @param ManagedProcess $process
	 * @return bool
	 */
	public function recordStart( ManagedProcess $process ): bool {
		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $db->insert(
			'processes',
			[
				'p_pid' => $process->getParentProcess()->getPid(),
				'p_state' => Process::STATUS_STARTED,
				'p_timeout' => $process->getParentProcess()->getTimeout(),
				'p_started' => $db->timestamp( ( new DateTime() )->format( 'YmdHis' ) )
			],
			__METHOD__
		);
	}

	private function updateInfo( int $pid, array $data ) {
		return $this->loadBalancer->getConnection( DB_PRIMARY )->update(
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
		$maxTime = $start->add( new \DateInterval( "PT{$info->getTimeout()}S" ) );

		return new DateTime() > $maxTime;
	}

}
