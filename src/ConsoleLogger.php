<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use InvalidArgumentException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Passes all records to the decorated logger, and additionally writes the ones
 * of at least the given level to stdout (below `warning`) or stderr
 */
class ConsoleLogger extends AbstractLogger {

	private const LEVELS = [
		LogLevel::DEBUG => 0,
		LogLevel::INFO => 1,
		LogLevel::NOTICE => 2,
		LogLevel::WARNING => 3,
		LogLevel::ERROR => 4,
		LogLevel::CRITICAL => 5,
		LogLevel::ALERT => 6,
		LogLevel::EMERGENCY => 7,
	];

	/** @var LoggerInterface */
	private $decoratedLogger;

	/** @var int */
	private $minLevel;

	/** @var string */
	private $channel;

	/**
	 * @param LoggerInterface $decoratedLogger
	 * @param string $minLevel PSR-3 level name (case-insensitive) or `warn`
	 * @param string $channel
	 * @throws InvalidArgumentException
	 */
	public function __construct( LoggerInterface $decoratedLogger, string $minLevel, string $channel ) {
		$this->decoratedLogger = $decoratedLogger;
		$this->minLevel = static::getLevelValue( $minLevel );
		$this->channel = $channel;
	}

	/**
	 * @param string $level
	 * @return int
	 * @throws InvalidArgumentException
	 */
	public static function getLevelValue( string $level ): int {
		$level = strtolower( trim( $level ) );
		if ( $level === 'warn' ) {
			$level = LogLevel::WARNING;
		}
		if ( !isset( self::LEVELS[$level] ) ) {
			throw new InvalidArgumentException(
				"Invalid log level \"$level\", must be one of " . implode( ', ', array_keys( self::LEVELS ) )
			);
		}
		return self::LEVELS[$level];
	}

	/**
	 * Signature compatible with psr/log 1.x (MediaWiki 1.43) and 3.x
	 *
	 * @param mixed $level
	 * @param string|Stringable $message
	 * @param array $context
	 * @return void
	 */
	public function log( $level, $message, array $context = [] ): void {
		$this->decoratedLogger->log( $level, $message, $context );

		$levelValue = self::LEVELS[$level] ?? self::LEVELS[LogLevel::ERROR];
		if ( $levelValue < $this->minLevel ) {
			return;
		}
		$line = sprintf(
			"[%s] %s.%s: %s\n",
			date( DATE_ATOM ), $this->channel, strtoupper( (string)$level ), $this->interpolate( $message, $context )
		);
		fwrite( $levelValue < self::LEVELS[LogLevel::WARNING] ? STDOUT : STDERR, $line );
	}

	/**
	 * @param string|Stringable $message
	 * @param array $context
	 * @return string
	 */
	private function interpolate( string|Stringable $message, array $context ): string {
		$replace = [];
		foreach ( $context as $key => $value ) {
			if ( $value instanceof Throwable ) {
				$replace['{' . $key . '}'] = get_class( $value ) . ': ' . $value->getMessage();
			} elseif ( $value === null || is_scalar( $value ) || $value instanceof Stringable ) {
				$replace['{' . $key . '}'] = (string)$value;
			} else {
				$replace['{' . $key . '}'] = json_encode( $value );
			}
		}
		return strtr( (string)$message, $replace );
	}
}
