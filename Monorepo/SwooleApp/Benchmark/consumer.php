<?php

declare(strict_types=1);

/*
 * licence Apache-2.0
 */

use Doctrine\DBAL\Connection;
use Ecotone\Api\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Dbal\Attribute\CurrentConnection;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DatabaseSetupManager;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class OrdersWaitingOnInputOutput
{
    public const SECONDS_WAITING_FOR_A_DOWNSTREAM_SERVICE = 0.02;

    public int $handled = 0;

    #[Asynchronous('benchmark_orders')]
    #[CommandHandler('benchmark.place', endpointId: 'benchmark.place.endpoint')]
    public function place(array $order, #[CurrentConnection] Connection $connection): void
    {
        $connection->insert('benchmark_orders', ['order_id' => $order['orderId'], 'product' => $order['product']]);
        usleep((int) (self::SECONDS_WAITING_FOR_A_DOWNSTREAM_SERVICE * 1_000_000));
        $this->handled++;
    }
}

final class OrdersComputedInMemory
{
    public int $handled = 0;

    #[Asynchronous('benchmark_orders')]
    #[CommandHandler('benchmark.place', endpointId: 'benchmark.place.endpoint')]
    public function place(array $order): void
    {
        $checksum = 0;
        for ($round = 0; $round < 2_000; $round++) {
            $checksum = crc32($checksum . $order['orderId']);
        }
        $this->handled++;
    }
}

[$action, $workload, $database, $consumers] = [$argv[1], $argv[2], $argv[3], (int) ($argv[4] ?? 0)];

$orders = $workload === 'input-output' ? new OrdersWaitingOnInputOutput() : new OrdersComputedInMemory();
$databaseDsn = $database === 'mysql' ? getenv('DATABASE_MYSQL') : 'pgsql://ecotone:secret@database:5432/ecotone?serverVersion=16';
$services = [$orders, AmqpExtConnectionFactory::class => new AmqpExtConnectionFactory(['dsn' => getenv('RABBIT_HOST')])];
$extensionObjects = [AmqpBackedMessageChannelBuilder::create('benchmark_orders')->withReceiveTimeout(100)];
$modules = [ModulePackageList::AMQP_PACKAGE];
if ($workload === 'input-output') {
    $services[DbalConnectionFactory::class] = new DbalConnectionFactory(['dsn' => $databaseDsn, 'connection_pool_size' => 32]);
    $extensionObjects[] = DbalConfiguration::createWithDefaults();
    $modules[] = ModulePackageList::DBAL_PACKAGE;
}

$ecotone = EcotoneLite::bootstrap(
    classesToResolve: [$orders::class],
    containerOrAvailableServices: $services,
    extensionObjects: $extensionObjects,
    enabledModules: $modules,
    pathToRootCatalog: __DIR__,
    environment: 'prod',
);

if ($action === 'publish') {
    $broker = $services[AmqpExtConnectionFactory::class]->createContext();
    try {
        $broker->purgeQueue($broker->createQueue('benchmark_orders'));
    } catch (Throwable) {
    }
    if ($workload === 'input-output') {
        $ecotone->getServiceFromContainer(DatabaseSetupManager::class)->initializeAll();
        $connection = $services[DbalConnectionFactory::class]->createContext()->getDbalConnection();
        $connection->executeStatement('DROP TABLE IF EXISTS benchmark_orders');
        $connection->executeStatement('CREATE TABLE benchmark_orders (order_id INTEGER PRIMARY KEY, product VARCHAR(255))');
    }
    for ($orderId = 1; $orderId <= $consumers; $orderId++) {
        $ecotone->getCommandBus()->sendWithRouting('benchmark.place', ['orderId' => $orderId, 'product' => 'milk']);
    }
    exit(0);
}

$consume = static fn () => $ecotone->run('benchmark_orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: (bool) getenv("BENCHMARK_STOP_ON_ERROR")));
$startedAt = microtime(true);
$processorTimeBefore = getrusage();
if ($action === 'consume-in-coroutines') {
    OpenSwoole\Runtime::enableCoroutine(OpenSwoole\Runtime::HOOK_ALL);
    OpenSwoole\Coroutine::run(static function () use ($consume, $consumers): void {
        for ($consumer = 1; $consumer <= $consumers; $consumer++) {
            OpenSwoole\Coroutine::create($consume);
        }
    });
} else {
    $consume();
}
$finishedAt = microtime(true);
$processorTimeAfter = getrusage();
$processorSeconds = static fn (array $usage): float => $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) / 1_000_000;

preg_match('/VmHWM:\s+(\d+) kB/', (string) file_get_contents('/proc/self/status'), $peakResidentMemory);
echo json_encode([
    'handled' => $orders->handled,
    'startedAt' => $startedAt,
    'finishedAt' => $finishedAt,
    'processorSeconds' => $processorSeconds($processorTimeAfter) - $processorSeconds($processorTimeBefore),
    'peakMemoryMegabytes' => round(((int) ($peakResidentMemory[1] ?? 0)) / 1024, 1),
]) . PHP_EOL;
