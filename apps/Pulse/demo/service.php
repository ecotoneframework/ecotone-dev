<?php

declare(strict_types=1);

use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;

require __DIR__ . '/bootstrap.php';

$name = $argv[1] ?? throw new InvalidArgumentException('Run it as: php demo/service.php orders|billing');
$application = bootDemoService($name);
fwrite(STDERR, "[$name] up\n");

$channels = $name === 'orders' ? ['orders', 'orders_async'] : ['billing', 'billing_channel', 'billing_async'];
while (true) {
    foreach ($channels as $channel) {
        try {
            $application->run($channel, ExecutionPollingMetadata::createWithTestingSetup(50, 700, false));
        } catch (Throwable $exception) {
            fwrite(STDERR, "[$name] $channel: " . $exception->getMessage() . "\n");
        }
    }
}
