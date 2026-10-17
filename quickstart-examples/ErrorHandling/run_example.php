<?php

use App\Application\OrderService;
use App\Application\PlaceOrder;
use App\Domain\ShippingService;
use App\Infrastructure\NetworkFailingShippingService;
use Ecotone\Api\Amqp\AmqpQueue;
use Ecotone\Api\Amqp\AmqpExtConnectionFactory as AmqpConnectionFactory;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Lite\EcotoneLite;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";

$serviceName = 'example_service';
$shippingService = new NetworkFailingShippingService();
$ecotoneLite = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new OrderService(),
        ShippingService::class => $shippingService,
        NetworkFailingShippingService::class => $shippingService,
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
        AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : 'amqp://guest:guest@localhost:5672/%2f']),
    ],
    extensionObjects: [ServiceConfiguration::createWithDefaults()->withServiceName($serviceName)->withDefaultErrorChannel('errorChannel')],
    pathToRootCatalog: __DIR__,
    namespaces: ['App'],
);
$ecotoneLite->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);
/** @var AmqpConnectionFactory $amqpConnectionFactory */
$amqpConnectionFactory = $ecotoneLite->getServiceFromContainer(AmqpConnectionFactory::class);
$amqpConnectionFactory->createContext()->deleteQueue(AmqpQueue::createWith('orders')->toEnqueueQueue());
$ecotoneLite->getGatewayByName(DeadLetterGateway::class)->deleteAll();
$executionPollingMetadata = ExecutionPollingMetadata::createWithDefaults()->withExecutionTimeLimitInMilliseconds(1000)->withHandledMessageLimit(1);

$commandBus = $ecotoneLite->getCommandBus();
$queryBus = $ecotoneLite->getQueryBus();

$messageId = Uuid::uuid4()->toString();
$commandBus->send(new PlaceOrder(Uuid::uuid4()->toString(), "Milk"), metadata: [
    "event_message_id" => $messageId,
]);
$ecotoneLite->run("orders", $executionPollingMetadata);
$ecotoneLite->run('orders', $executionPollingMetadata);
$ecotoneLite->run('orders', $executionPollingMetadata);
$ecotoneLite->run('orders', $executionPollingMetadata);
/** Retries exceeded. Messages goes to DLQ */

/** This is what replaying from Ecotone Pulse or the ecotone:deadletter:replay console command does */
$ecotoneLite->getGatewayByName(DeadLetterGateway::class)->replay($messageId);

$ecotoneLite->run('orders', $executionPollingMetadata);

Assert::assertTrue($ecotoneLite->getQueryBus()->sendWithRouting("isShippingSuccessful"));

echo "Success! Message was successfully replayed from DLQ\n";