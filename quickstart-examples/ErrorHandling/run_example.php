<?php

use App\Application\PlaceOrder;
use App\Domain\ShippingService;
use App\Infrastructure\NetworkFailingShippingService;
use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Dbal\Recoverability\DeadLetterGateway;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use Enqueue\Dbal\DbalConnectionFactory;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;

require __DIR__ . "/vendor/autoload.php";

$serviceName = 'example_service';
$shippingService = new NetworkFailingShippingService();
$ecotoneLite = EcotoneLiteApplication::bootstrap([ShippingService::class => $shippingService, NetworkFailingShippingService::class => $shippingService, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'), AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : 'amqp://guest:guest@localhost:5672/%2f'])], serviceConfiguration: ServiceConfiguration::createWithDefaults()->withServiceName($serviceName)->withDefaultErrorChannel('errorChannel'), pathToRootCatalog: __DIR__);
/** @var AmqpConnectionFactory $amqpConnectionFactory */
$amqpConnectionFactory = $ecotoneLite->getServiceFromContainer(AmqpConnectionFactory::class);
$amqpConnectionFactory->createContext()->deleteQueue(new \Interop\Amqp\Impl\AmqpQueue('orders'));
$amqpConnectionFactory->createContext()->deleteQueue(new \Interop\Amqp\Impl\AmqpQueue('distributed_example_service'));
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

/** This imitating calling replay from Ecotone Pulse or CLI  */
$distributedBus = $ecotoneLite->getDistributedBus();
$distributedBus->sendMessage(
    $serviceName,
    DbalDeadLetterBuilder::getChannelName(DeadLetterGateway::class, DbalDeadLetterBuilder::REPLAY_CHANNEL),
    $messageId,
);

$ecotoneLite->run($serviceName, $executionPollingMetadata);
$ecotoneLite->run('orders', $executionPollingMetadata);

Assert::assertTrue($ecotoneLite->getQueryBus()->sendWithRouting("isShippingSuccessful"));

echo "Success! Message was successfully replayed from DLQ\n";