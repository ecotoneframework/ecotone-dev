<?php

use App\MultiTenancy\RateLimitWithDelay\Configuration;
use App\MultiTenancy\RateLimitWithDelay\EmailSender;
use App\MultiTenancy\RateLimitWithDelay\SendEmailCampaing;
use Ecotone\Lite\EcotoneLiteApplication;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Enqueue\AmqpExt\AmqpConnectionFactory;
use PHPUnit\Framework\Assert;

require __DIR__ . "/../../vendor/autoload.php";

$messagingSystem = EcotoneLiteApplication::bootstrap([
    AmqpConnectionFactory::class => new AmqpConnectionFactory(['dsn' => getenv('RABBIT_HOST') ? getenv('RABBIT_HOST') : "amqp://guest:guest@localhost:5672/%2f"])
], pathToRootCatalog: __DIR__ . '/../..');
cleanup($messagingSystem);

/** @var EmailSender $emailSender */
$emailSender = $messagingSystem->getServiceFromContainer(EmailSender::class);
$messagingSystem->getCommandBus()->send(
    new SendEmailCampaing([
        'email1@gmail.com', 'email2@gmail.com',
        'email3@gmail.com', 'email4@gmail.com'
    ])
);

$messagingSystem->run(Configuration::ASYNCHRONOUS_MESSAGES);
Assert::assertEquals(
    ['email1@gmail.com', 'email2@gmail.com'],
    $emailSender->getEmails()
);

$messagingSystem->run(Configuration::ASYNCHRONOUS_MESSAGES);
Assert::assertEquals(
    ['email1@gmail.com', 'email2@gmail.com', 'email3@gmail.com', 'email4@gmail.com'],
    $emailSender->getEmails()
);

echo "Email campaign sent in batches\n";

function cleanup(ConfiguredMessagingSystem $ecotoneLite): void
{
    /** @var AmqpConnectionFactory $amqpConnectionFactory */
    $amqpConnectionFactory = $ecotoneLite->getServiceFromContainer(AmqpConnectionFactory::class);
    $amqpConnectionFactory->createContext()->deleteQueue(new \Interop\Amqp\Impl\AmqpQueue(Configuration::ASYNCHRONOUS_MESSAGES));
}