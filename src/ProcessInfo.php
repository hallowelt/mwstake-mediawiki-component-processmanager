<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use DateTime;
use stdClass;
use Symfony\Component\VarDumper\Cloner\Data;

class ProcessInfo implements \JsonSerializable {
	public const PROCESS_STATE_RUNNING = 'running';
	public const PROCESS_STATE_STOPPED = 'stopped';
	public const PROCESS_STATE_FINISHED = 'finished';
	public const PROCESS_STATE_ERROR = 'error';

	/** @var int */
	private $pid;
	/** @var string */
	private $state;
	/** @var int|null */
	private $exitCode;
	/** @var string|null */
	private $exitStatus;
	/** @var DateTime */
	private $started;
	/** @var float */
	private $timeout;

	/**
	 * @param stdClass $row
	 * @return static
	 */
	public static function newFromRow( stdClass $row ) {
		return new static(
			(int)$row->p_pid,
			$row->p_state,
			DateTime::createFromFormat( 'YmdHis', $row->p_started ),
			$row->p_timeout !== null ? (float)$row->p_timeout : null,
			$row->p_exitcode !== null ? (int)$row->p_exitcode : null,
			$row->p_exitstatus,
		);
	}

	/**
	 * @param int $pid
	 * @param string $state
	 * @param DateTime $started
	 * @param int|null $exitCode
	 * @param string|null $exitStatus
	 */
	public function __construct(
		int $pid, $state, DateTime $started, $timeout, $exitCode = null, $exitStatus = null
	) {
		$this->pid = $pid;
		$this->state = $state;
		$this->started = $started;
		$this->exitCode = $exitCode;
		$this->exitStatus = $exitStatus;
		$this->timeout = $timeout;
	}

	public function getPid(): int {
		return $this->pid;
	}

	/**
	 * @return string
	 */
	public function getState(): string {
		return $this->state;
	}

	/**
	 * @return float|null
	 */
	public function getTimeout(): ?float {
		return $this->getTimeout();
	}

	/**
	 * @return DateTime
	 */
	public function getStartDate(): DateTime {
		return $this->started;
	}

	/**
	 * @return array
	 */
	public function jsonSerialize() {
		return [
			'pid' => $this->pid,
			'state' => $this->getState(),
			'started' => $this->started->format( 'YmdHis' ),
			'exitCode' => $this->exitCode,
			'exitStatus' => $this->exitStatus
		];
	}



}