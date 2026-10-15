<?php

use App\Schedule\Messaging\DynamicSchedules\AskForOrderReview;
use App\Schedule\Messaging\DynamicSchedules\MessagingConfiguration as DynamicMessagingConfiguration;
use App\Schedule\Messaging\PeriodSchedules\InvoiceGenerator;
use App\Schedule\Messaging\PeriodSchedules\MessagingConfiguration as PeriodMessagingConfiguration;
use App\Schedule\Messaging\PeriodSchedules\UserWasRegistered;
use App\Schedule\Messaging\StaticSchedules\MessagingConfiguration as StaticMessagingConfiguration;
use App\Schedule\Messaging\StaticSchedules\WelcomeNotification;
use App\Schedule\ScheduledJob\ScheduledCommandHandler\InvoiceService;
use App\Schedule\ScheduledJob\ScheduledJob\NotificationService;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Dbal\Connection\DbalConnectionFactory;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLite::bootstrap(
    containerOrAvailableServices: [
        new WelcomeNotification(),
        new InvoiceGenerator(),
        new AskForOrderReview(),
        new InvoiceService(),
        new NotificationService(),
        DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN') ? getenv('DATABASE_DSN') : 'pgsql://ecotone:secret@localhost:5432/ecotone'),
    ],
    configuration: ServiceConfiguration::createWithDefaults()->withNamespaces(['App']),
    pathToRootCatalog: __DIR__,
);
$messagingSystem->runConsoleCommand('ecotone:migration:database:setup', ['initialize' => true]);

echo "Generating invoices using Scheduled Job. Waiting for cron execution...\n";
$messagingSystem->run(InvoiceService::NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->run(NotificationService::NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(3));

$messagingSystem->getCommandBus()->sendWithRouting("registerUser");
$messagingSystem->run(StaticMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->getCommandBus()->sendWithRouting("askForOrderReview", "Samsung TV", metadata: ["deliveryDelay" => 5000]);
$messagingSystem->run(DynamicMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->getEventBus()->publish(new UserWasRegistered("Johny Bravo"));
$messagingSystem->run(PeriodMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(4));