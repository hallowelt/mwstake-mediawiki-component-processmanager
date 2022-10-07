<?php

namespace MWStake\MediaWiki\Component\ProcessManager;

use Exception;
use Wikimedia\ObjectFactory\ObjectFactory;

class StepExecutor {
	/**
	 * @var ObjectFactory
	 */
	private $objectFactory;

	/**
	 * @param ObjectFactory $objectFactory
	 */
	public function __construct( ObjectFactory $objectFactory ) {
		$this->objectFactory = $objectFactory;
	}

	/**
	 * @param array $steps
	 * @param array|null $data
	 *
	 * @return array|mixed|null
	 * @throws Exception
	 */
	public function execute( $steps, $data = [] ) {
		foreach ( $steps as $name => $spec ) {
			try {
				$object = $this->objectFactory->createObject( $spec );
				if ( !( $object instanceof IProcessStep ) ) {
					throw new Exception(
						"Specification of step \"$name\" does not produce object of type " .
						IProcessStep::class
					);
				}

				$data = $object->execute( $data );
				if ( $object instanceof InterruptingProcessStep ) {
					return [
						'interrupt' => $name,
						'data' => $data ?? [],
					];
				}
			} catch ( Exception $ex ) {
				throw new Exception(
					"Step \"$name\" failed: " . $ex->getMessage(),
					$ex->getCode(),
					$ex
				);
			}
		}

		return $data;
	}
}
