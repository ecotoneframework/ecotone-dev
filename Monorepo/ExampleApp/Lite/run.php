<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$bootstrap = require __DIR__.'/app.php';

/** @var ConfiguredMessagingSystem $messagingSystem */
$messagingSystem =  $bootstrap();
/** @var Configuration $configuration */
$configuration = $messagingSystem->getServiceFromContainer(Configuration::class);

$messagingSystem->getCommandBus()->send(
    new PlaceOrder(
        Uuid::uuid4(),
        $configuration->userId(),
        new ShippingAddress(
            'Washington',
            '15',
            '81-221',
            'Netherlands'
        ),
        $configuration->productId()
    )
);
