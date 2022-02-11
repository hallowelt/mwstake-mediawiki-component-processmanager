# Process Manager

This library allows you to create async background processes, that can be accessed later from anywhere,
to check the progress and retrieve output.

# Usage

Process works based on steps provided to it, it will execute steps sequentialy, passing output data from one step
as an input for the next, until the end. Last step will return its output as the output of the whole process.

Steps are defined as `ObjectFactory` specs. Object produced from such specs must be instance of `MWStake\MediaWiki\Component\ProcessManager\IProcessStep`.

## Sample step 
```php
class Foo implements IProcessStep {

	private $lb;
	/** @var string */
	private $name;
	
	public function __construct( ILoadBalancer $lb, $name ) {
		$this->lb = $lb;
		$this->name = $name;
	}

	public function execute( $data = [] ): array  {
	// Add "_bar" to the name passed as the argument in the spec and return it
		$name = $this->name . '_bar';

		// some lenghty code

		return [ 'modifiedName' => $name ];
	}
}
```

## Creating process
```php
// Create process that has a single step, Foo, defined above
// new ManagerProcess( array $steps, int $timeout );
$process = new ManagedProcess( [
	'foo-step' => [
		'class' => Foo::class,
		'args' => [ 'Bar-name' ],
		'services' => [ 'DBLoadBalancer' ]
	]
], 300 );
	
$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
// ProcessManager::startProcess() returns unique process ID that is required
// later on to check on the process state
echo $processManager->startProcess( $process );
// 1211a33123aae2baa6ed1d9a1846da9d
```

## Checking process status

Once the process is started using the procedure above, and we obtain the process id, we can check on its status
anytime, from anywhere, even from different process then the one that started the process

```php
$processManager = MediaWikiServices::getInstance()->getService( 'ProcessManager' );
echo $processManager->getProcessInfo( $pid );
// Returns JSON
{
	"pid": "1211a33123aae2baa6ed1d9a1846da9d",
	"started_at": "20220209125814",
	"status": "finished",
	"output": { /*JSON-encoded string of whatever the last step returned as output*/ }
}
```

In case of an error, response will contain status `error`, and show Exception message and callstack.

## Notes

- This lib requires an DB table, so `update.php` will be necessary
- Old jobs will be deleted 10h after they are created
- Known issue: If your code (in the step) has an un-catchable error (not passing required param, syntax errors), process will
not be able to record those and will just crash. Only way to track such processes would be to actually look into the kernel,
  which is not supported for now. Such jobs will just remain in `running` state until the timeout, after which they will fail
  with timeout exception.
