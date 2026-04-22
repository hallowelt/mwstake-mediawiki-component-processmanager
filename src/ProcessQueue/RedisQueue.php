<?php

namespace MWStake\MediaWiki\Component\ProcessManager\ProcessQueue;

use DateTime;
use Exception;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\WikiMap\WikiMap;
use MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep;
use MWStake\MediaWiki\Component\ProcessManager\IProcessManagerPlugin;
use MWStake\MediaWiki\Component\ProcessManager\IProcessQueue;
use MWStake\MediaWiki\Component\ProcessManager\ManagedProcess;
use MWStake\MediaWiki\Component\ProcessManager\ProcessInfo;
use Psr\Log\LoggerInterface;
use RedisException;
use Symfony\Component\Process\Process;
use Wikimedia\ObjectCache\RedisConnectionPool;

class RedisQueue implements IProcessQueue {

	/** @var RedisConnectionPool */
	private $redisPool;

	/** @var string */
	private $server;

	/** @var string */
	private $wikiId;

	/** @var LoggerInterface */
	private $logger;

	/** TTL for process data (1 day) */
	private const PROCESS_TTL = 86400;

	/** TTL for interrupted process data (7 days) */
	private const INTERRUPTED_TTL = 604800;

	/**
	 * @param array $params Possible keys:
	 *   - redisConfig : An array of parameters to RedisConnectionPool::__construct().
	 *                   The serializer option is forced to "none".
	 *   - redisServer : A hostname/port combination or the absolute path of a UNIX socket.
	 *                   If a hostname is specified but no port, port 6379 will be used.
	 */
	public function __construct( array $params ) {
		$params['redisConfig']['serializer'] = 'none';
		$this->server = $params['redisServer'];
		$this->redisPool = RedisConnectionPool::singleton( $params['redisConfig'] );
		$this->wikiId = WikiMap::getCurrentWikiId();
		$this->logger = LoggerFactory::getInstance( 'ProcessManager' );
	}

	/**
	 * @inheritDoc
	 */
	public function getProcessInfo( string $pid ): ?ProcessInfo {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return null;
		}
		try {
			$raw = $conn->get( $this->processKey( $pid ) );
			if ( $raw === false ) {
				return null;
			}
			$data = json_decode( $raw, true );
			if ( !is_array( $data ) ) {
				return null;
			}
			return ProcessInfo::newFromRow( (object)$data );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function enqueueProcess( ManagedProcess $process, array $data ): ?string {
		$pid = md5( rand( 1, 9999999 ) + ( new DateTime() )->getTimestamp() );

		$processData = [
			'p_pid' => $pid,
			'p_state' => Process::STATUS_READY,
			'p_timeout' => $process->getTimeout(),
			'p_started' => ( new DateTime() )->format( 'YmdHis' ),
			'p_exitcode' => null,
			'p_exitstatus' => null,
			'p_output' => json_encode( $data ),
			'p_steps' => json_encode( $process->getSteps() ),
			'p_last_completed_step' => null,
			'p_additional_script_args' => $process->getAdditionalArgs()
				? json_encode( $process->getAdditionalArgs() ) : null,
			'p_wiki_id' => $this->wikiId,
		];

		$conn = $this->getConnection();
		if ( !$conn ) {
			return null;
		}
		try {
			$conn->multi();
			$conn->setex( $this->processKey( $pid ), self::PROCESS_TTL, json_encode( $processData ) );
			$conn->lPush( $this->queueKey(), $pid );
			$results = $conn->exec();

			if ( $results === false ) {
				return null;
			}
			return $pid;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function proceed( $pid ): ?string {
		$info = $this->getProcessInfo( $pid );
		if ( !$info ) {
			throw new Exception( 'Process with PID ' . $pid . ' does not exist' );
		}
		if ( $info->getState() !== InterruptingProcessStep::STATUS_INTERRUPTED ) {
			throw new Exception( 'Process was not previously interrupted' );
		}
		$steps = $info->getSteps();
		$lastData = $info->getOutput();
		$lastStep = $lastData['lastStep'] ?? null;
		$lastData = $lastData['data'] ?? [];
		if ( !$lastStep ) {
			throw new Exception( 'No last step information available' );
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

		$conn = $this->getConnection();
		if ( !$conn ) {
			return null;
		}
		try {
			$raw = $conn->get( $this->processKey( $pid ) );
			if ( $raw === false ) {
				return null;
			}
			$data = json_decode( $raw, true );
			$data['p_state'] = Process::STATUS_READY;
			$data['p_output'] = json_encode( $lastData );
			$data['p_steps'] = json_encode( $remainingSteps );

			$conn->multi();
			$conn->setex( $this->processKey( $pid ), self::PROCESS_TTL, json_encode( $data ) );
			$conn->lPush( $this->queueKey(), $pid );
			$results = $conn->exec();

			return ( $results !== false ) ? $pid : null;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function recordInterrupt( string $pid, string $lastStep, array $data ): bool {
		return $this->updateProcessFields( $pid, [
			'p_state' => InterruptingProcessStep::STATUS_INTERRUPTED,
			'p_output' => json_encode( [
				'lastStep' => $lastStep,
				'data' => $data,
			] ),
		], self::INTERRUPTED_TTL );
	}

	/**
	 * @inheritDoc
	 */
	public function recordStart( string $pid ): bool {
		return $this->updateProcessFields( $pid, [
			'p_state' => Process::STATUS_STARTED,
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function storeLastCompletedStep( string $pid, string $step ): bool {
		return $this->updateProcessFields( $pid, [
			'p_last_completed_step' => $step,
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function recordFinish(
		string $pid, int $exitCode, string $exitStatus = '', array $data = []
	): bool {
		return $this->updateProcessFields( $pid, [
			'p_state' => Process::STATUS_TERMINATED,
			'p_exitcode' => $exitCode,
			'p_exitstatus' => $exitStatus,
			'p_output' => json_encode( $data ),
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function pluckOneFromQueue( string $runnerUUID ): ?ProcessInfo {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return null;
		}
		try {
			$queueKey = $this->queueKey();
			$requeue = [];
			try {
				while ( true ) {
					$pid = $conn->rPop( $queueKey );
					if ( $pid === false ) {
						return null;
					}

					$processKey = $this->processKey( $pid );
					$raw = $conn->get( $processKey );
					if ( $raw === false ) {
						// Process data expired, discard
						continue;
					}

					$data = json_decode( $raw, true );
					if ( !is_array( $data ) || $data['p_state'] !== Process::STATUS_READY ) {
						continue;
					}
					// Claimed by a different runner — put back for that runner
					if ( isset( $data['p_claimed_by'] ) && $data['p_claimed_by'] !== $runnerUUID ) {
						$requeue[] = $pid;
						continue;
					}

					// Claim: update state and record claiming runner
					$returnData = $data;
					$data['p_state'] = 'claimed';
					$data['p_claimed_by'] = $runnerUUID;
					$conn->setex( $processKey, self::PROCESS_TTL, json_encode( $data ) );

					return ProcessInfo::newFromRow( (object)$returnData );
				}
			} finally {
				// Push back any PIDs belonging to other runners
				foreach ( $requeue as $requeuePid ) {
					$conn->lPush( $queueKey, $requeuePid );
				}
			}
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return null;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function claimPlugin( IProcessManagerPlugin $plugin, string $requester ): bool {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$now = time();
			$threshold = $now - 60;
			$lockKey = $this->pluginLockKey( $plugin->getKey() );

			/** @lang Lua */
			$script = <<<LUA
local key = KEYS[1]
local requester = ARGV[1]
local now = ARGV[2]
local threshold = tonumber(ARGV[3])

local raw = redis.call('GET', key)
if not raw then
	redis.call('SET', key, cjson.encode({ppl_claimed_by = requester, ppl_locked_at = now}))
	return 1
end

local claim = cjson.decode(raw)
if claim.ppl_claimed_by ~= requester and tonumber(claim.ppl_locked_at) > threshold then
	return 0
end

redis.call('SET', key, cjson.encode({ppl_claimed_by = requester, ppl_locked_at = now}))
return 1
LUA;
			$result = $conn->luaEval(
				$script,
				[ $lockKey, $requester, (string)$now, (string)$threshold ],
				1
			);
			return (bool)$result;
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * @param string $pid
	 * @return string Redis key for process data
	 */
	private function processKey( string $pid ): string {
		return $this->getKeyPrefix() . ':process:' . $pid;
	}

	/**
	 * @return string Redis key for the process queue list
	 */
	protected function queueKey(): string {
		return $this->getKeyPrefix() . ':queue';
	}

	/**
	 * @param string $pluginKey
	 * @return string Redis key for a plugin lock
	 */
	private function pluginLockKey( string $pluginKey ): string {
		return $this->getKeyPrefix() . ':plugin:' . rawurlencode( $pluginKey );
	}

	/**
	 * @return string Common key prefix scoped to this wiki
	 */
	protected function getKeyPrefix(): string {
		return rawurlencode( $this->wikiId ) . ':processmanager';
	}

	/**
	 * Read a process record, apply field updates, and write back.
	 *
	 * @param string $pid
	 * @param array $fields
	 * @param int $ttl
	 * @return bool
	 */
	private function updateProcessFields( string $pid, array $fields, int $ttl = self::PROCESS_TTL ): bool {
		$conn = $this->getConnection();
		if ( !$conn ) {
			return false;
		}
		try {
			$key = $this->processKey( $pid );
			$raw = $conn->get( $key );
			if ( $raw === false ) {
				return false;
			}
			$data = json_decode( $raw, true );
			if ( !is_array( $data ) ) {
				return false;
			}

			foreach ( $fields as $field => $value ) {
				$data[$field] = $value;
			}

			return (bool)$conn->setex( $key, $ttl, json_encode( $data ) );
		} catch ( RedisException $e ) {
			$this->handleError( $conn, $e );
			return false;
		}
	}

	/**
	 * @return \Redis|false A Redis connection or false on failure
	 */
	private function getConnection() {
		$conn = $this->redisPool->getConnection( $this->server, $this->logger );
		if ( !$conn ) {
			$this->logger->error( 'Unable to connect to Redis server {server}', [
				'server' => $this->server,
			] );
			return false;
		}
		return $conn;
	}

	/**
	 * @param \Redis $conn
	 * @param RedisException $e
	 */
	private function handleError( $conn, RedisException $e ): void {
		$this->redisPool->handleError( $conn, $e );
		$this->logger->error( 'Redis error in ProcessManager: {message}', [
			'message' => $e->getMessage(),
		] );
	}
}
