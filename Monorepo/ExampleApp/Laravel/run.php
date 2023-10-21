<?php

use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Monorepo\ExampleApp\Common\Infrastructure\Configuration;
use Monorepo\ExampleApp\Common\UI\OrderController;
use PHPUnit\Framework\Assert;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// test with cache
//\putenv('APP_ENV=prod');
//\putenv('APP_DEBUG=false');
//Artisan::call('route:cache');
//Artisan::call('config:cache');
$configuration = $app->get(Configuration::class);
/** @var Kernel $kernel */
$kernel = $app->get(Kernel::class);

$response = $kernel->handle(
    \Illuminate\Http\Request::create(
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

$app->terminate();
