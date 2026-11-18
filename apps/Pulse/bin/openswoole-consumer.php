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
use App\Settings\PulseEnvironment;
use App\Settings\Runtime as PulseRuntime;
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

$coroutines = PulseRuntime::consumerCoroutines(PulseEnvironment::read());
$limits = ExecutionPollingMetadata::createWithDefaults()
    ->withMemoryLimitInMegabytes((int) (getenv('PULSE_WORKER_MEMORY_LIMIT') ?: 256))
    ->withExecutionTimeLimitInMilliseconds((int) (getenv('PULSE_WORKER_TIME_LIMIT_MS') ?: 3600000));

$failed = false;
Runtime::enableCoroutine(Runtime::HOOK_ALL);
Coroutine::run(static function () use ($messagingSystem, $channel, $coroutines, $limits, &$failed): void {
    for ($coroutine = 1; $coroutine <= $coroutines; $coroutine++) {
        Coroutine::create(static function () use ($messagingSystem, $channel, $limits, &$failed): void {
            try {
                $messagingSystem->run($channel, $limits);
            } catch (Throwable $failure) {
                fwrite(STDERR, sprintf("Pulse: a coroutine consuming %s stopped with %s: %s. The other coroutines are asked to stop, so the consumer is started again as a whole.\n", $channel, $failure::class, $failure->getMessage()));
                $failed = true;
                posix_kill(getmypid(), SIGTERM);
            }
        });
    }
});

exit($failed ? 1 : 0);
