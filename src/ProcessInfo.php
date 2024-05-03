<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use DateTime;
use JsonSerializable;
use stdClass;

class ProcessInfo implements JsonSerializable {

	/** @var string */
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
	/** @var array */
	private $data;
	/** @var array */
	private $steps;
	/** @var string|null */
	private $lastCompletedStep;

	/**
	 * @param stdClass $row
	 * @return static
	 */
	public static function newFromRow( stdClass $row ) {
		return new static(
			$row->p_pid,
			$row->p_state,
			DateTime::createFromFormat( 'YmdHis', $row->p_started ),
			$row->p_timeout !== null ? (float)$row->p_timeout : null,
			$row->p_exitcode !== null ? (int)$row->p_exitcode : null,
			$row->p_exitstatus,
			$row->p_output !== null ? json_decode( $row->p_output, 1 ) : [],
			$row->p_steps !== null ? json_decode( $row->p_steps, 1 ) : [],
			$row->p_last_completed_step ?? null
		);
	}

	/**
	 * @param string $pid
	 * @param string $state
	 * @param DateTime $started
	 * @param int $timeout
	 * @param int|null $exitCode
	 * @param string|null $exitStatus
	 * @param array|null $data
	 * @param array|null $steps
	 */
	public function __construct(
		$pid, $state, DateTime $started, $timeout, $exitCode = null,
		$exitStatus = null, $data = [], $steps = [], $lastCompletedStep = null
	) {
		$this->pid = $pid;
		$this->state = $state;
		$this->started = $started;
		$this->exitCode = $exitCode;
		$this->exitStatus = $exitStatus;
		$this->timeout = $timeout;
		$this->data = $data;
		$this->steps = $steps;
		$this->lastCompletedStep = $lastCompletedStep;
	}

	/**
	 * @return string
	 */
	public function getPid(): string {
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
		return $this->timeout;
	}

	/**
	 * @return DateTime
	 */
	public function getStartDate(): DateTime {
		return $this->started;
	}

	/**
	 * @return string|null
	 */
	public function getExitStateMessage(): ?string {
		return $this->exitStatus;
	}

	/**
	 * @return int|null
	 */
	public function getExitCode(): ?int {
		return $this->exitCode;
	}

	/**
	 * @return array
	 */
	public function getOutput(): array {
		return $this->data;
	}

	/**
	 * @return array
	 */
	public function getSteps(): array {
		return $this->steps;
	}

	public function getLastCompletedStep(): ?string {
		return $this->lastCompletedStep;
	}

	/**
	 * @return array
	 */
	public function getStepProgress(): array {
		$progress = [];
		$reachedCompleted = false;
		foreach ( $this->getSteps() as $name => $spec ) {
			if ( $name === $this->lastCompletedStep ) {
				$progress[$name] = 'completed';
				$reachedCompleted = true;
				continue;
			}
			if ( $reachedCompleted || !$this->lastCompletedStep ) {
				$progress[$name] = 'pending';
				continue;
			}
			if ( $this->lastCompletedStep && $name !== $this->lastCompletedStep ) {
				$progress[$name] = 'completed';
				continue;
			}
		}
		return $progress;
	}

	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'pid' => $this->pid,
			'state' => $this->getState(),
			'started' => $this->started->format( 'YmdHis' ),
			'exitCode' => $this->exitCode,
			'exitStatus' => $this->exitStatus,
			'output' => $this->data,
			'steps' => $this->steps,
			'lastCompletedStep' => $this->lastCompletedStep
		];
	}

}
