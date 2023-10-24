<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use Monorepo\ExampleApp\Symfony\Kernel;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$kernel = new Kernel('prod', false);
$kernel->boot();

$configuration = $kernel->getContainer()->get(Configuration::class);

$response = $kernel->handle(
    Request::create(
        '/place-order',
        'POST',
        content: json_encode([
            'orderId' => Uuid::uuid4()->toString(),
            'address' => [
                'street' => 'Washington',
                'houseNumber' => '15',
                'postCode' => '81-221',
                'country' => 'Netherlands'
            ],
            'productId' => $configuration->productId(),
        ])
    )
);
Assert::assertSame(200, $response->getStatusCode(), $response->getContent());

$kernel->shutdown();