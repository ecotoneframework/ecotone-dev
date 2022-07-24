<?php

use App\Schedule\Messaging\DynamicSchedules\MessagingConfiguration as DynamicMessagingConfiguration;
use App\Schedule\Messaging\PeriodSchedules\MessagingConfiguration as PeriodMessagingConfiguration;
use App\Schedule\Messaging\PeriodSchedules\UserWasRegistered;
use App\Schedule\Messaging\StaticSchedules\MessagingConfiguration as StaticMessagingConfiguration;
use App\Schedule\ScheduledJob\ScheduledCommandHandler\InvoiceService;
use App\Schedule\ScheduledJob\ScheduledJob\NotificationService;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Enqueue\Dbal\DbalConnectionFactory;

require __DIR__ . "/vendor/autoload.php";
$messagingSystem = EcotoneLiteApplication::boostrap([
    DbalConnectionFactory::class => new DbalConnectionFactory('pgsql://ecotone:secret@database:5432/ecotone')
]);

echo "Generating invoices using Scheduled Job. Waiting for cron execution...\n";
$messagingSystem->run(InvoiceService::NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->run(NotificationService::NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(3));

$messagingSystem->getCommandBus()->sendWithRouting("registerUser");
$messagingSystem->run(StaticMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->getCommandBus()->sendWithRouting("askForOrderReview", "Samsung TV", metadata: ["deliveryDelay" => 5000]);
$messagingSystem->run(DynamicMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(1));

$messagingSystem->getEventBus()->publish(new UserWasRegistered("Johny Bravo"));
$messagingSystem->run(PeriodMessagingConfiguration::CHANNEL_NAME, ExecutionPollingMetadata::createWithDefaults()->withHandledMessageLimit(4));