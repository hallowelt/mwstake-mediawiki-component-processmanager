<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use Exception;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class CodeExecutor {

	/** @var LoggerInterface */
	private $logger;

	/** @var array */
	private $steps;

	/**
	 * @param array $steps
	 */
	public function __construct( array $steps ) {
		$this->steps = $steps;
		$this->logger = LoggerFactory::getInstance( 'processmanager-code-executer' );
	}

	/**
	 * @param array $data
	 * @return array
	 */
	public function executeSteps( array $data = [] ) {
		$this->logger->info( "Start processing at " . date( 'Y-m-d H:i:s' ) );
		$of = \MediaWiki\MediaWikiServices::getInstance()->getObjectFactory();
		foreach ( $this->steps as $name => $spec ) {
			try {
				$object = $of->createObject( $spec );
				if ( !( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\IProcessStep ) ) {
					throw new Exception(
						"Specification of step \"$name\" does not produce object of type " .
						\MWStake\MediaWiki\Component\ProcessManager\IProcessStep::class
					);
				}

				if ( $object instanceof \MWStake\MediaWiki\Component\ProcessManager\InterruptingProcessStep ) {
					throw new Exception(
						InterruptingProcessStep::class . " interface is not supported by code executor"
					);
				}

				$data = $object->execute( $data );
			} catch ( Exception $ex ) {
				throw new Exception(
					"Step \"$name\" failed: " . $ex->getMessage(),
					$ex->getCode(),
					$ex
				);
			}
		}
		$this->logger->info( "End processing at " . date( 'Y-m-d H:i:s' ) );
		return $data;
	}

}
