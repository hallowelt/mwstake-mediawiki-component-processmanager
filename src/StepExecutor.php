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
	 * @param ProcessManager|null $manager
	 * @param string|null $pid
	 * @return array|mixed|null
	 * @throws Exception
	 */
	public function execute( $steps, $data = [], ?ProcessManager $manager = null, ?string $pid = null ) {
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
				if ( $manager && $pid ) {
					$manager->storeLastCompletedStep( $pid, $name );
				}
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
