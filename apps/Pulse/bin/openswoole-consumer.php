<?php

declare(strict_types=1);

/*
 * php bin/openswoole-consumer.php <channel>
 * Consumes a channel in one process on several OpenSwoole coroutines, started by docker/entrypoint.sh when
 * PULSE_RUNTIME=swoole. Each coroutine handles one message at a time on its own broker and database connection,
 * and gives the processor to the others while it waits for either.
 *
 *   PULSE_CONSUMER_COROUTINES   coroutines consuming the channel, default 6
 *   PULSE_WORKER_MEMORY_LIMIT   megabytes after which the process finishes, to be restarted, default 256
 *   PULSE_WORKER_TIME_LIMIT_MS  milliseconds after which the process finishes, to be restarted, default 3600000
 */

use App\Kernel;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\Messaging\ConfiguredMessagingSystem;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$channel = $argv[1] ?? null;
if ($channel === null) {
    fwrite(STDERR, "Name the channel to consume: php bin/openswoole-consumer.php <channel>. php bin/console pulse:reply-channels lists the channels Pulse consumes.\n");
    exit(1);
}

(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$messagingSystem = $kernel->getContainer()->get(ConfiguredMessagingSystem::class);

$coroutines = max(1, (int) (getenv('PULSE_CONSUMER_COROUTINES') ?: 6));
$limits = ExecutionPollingMetadata::createWithDefaults()
    ->withMemoryLimitInMegabytes((int) (getenv('PULSE_WORKER_MEMORY_LIMIT') ?: 256))
    ->withExecutionTimeLimitInMilliseconds((int) (getenv('PULSE_WORKER_TIME_LIMIT_MS') ?: 3600000));

Runtime::enableCoroutine(Runtime::HOOK_ALL);
Coroutine::run(static function () use ($messagingSystem, $channel, $coroutines, $limits): void {
    for ($coroutine = 1; $coroutine <= $coroutines; $coroutine++) {
        Coroutine::create(static fn () => $messagingSystem->run($channel, $limits));
    }
});
