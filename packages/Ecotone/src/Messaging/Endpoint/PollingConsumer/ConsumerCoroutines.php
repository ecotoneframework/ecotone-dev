<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use ArrayObject;
use Closure;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ConsumerCoroutines
{
    /**
     * @param Closure(): ConsumerLifecycle $createConsumer
     */
    public static function consume(string $endpointId, int $coroutines, bool $drainsAQueue, Closure $createConsumer): void
    {
        if (! $drainsAQueue) {
            throw ConfigurationException::create(
                "Endpoint `{$endpointId}` is set to run on {$coroutines} coroutines, but it runs on a schedule instead of consuming a channel, so every coroutine would run each scheduled execution again. "
                . 'Leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines() for this endpoint. '
                . 'To do its work on coroutines, let it send messages to an #[Asynchronous] handler and run that channel\'s consumer on coroutines.'
            );
        }

        $runtime = self::coroutineRuntime();
        if ($runtime === null) {
            throw ConfigurationException::create(
                "Consumer `{$endpointId}` is set to consume on {$coroutines} coroutines, but this PHP has neither the openswoole nor the swoole extension loaded, so there is nothing to run coroutines on. "
                . 'Install one of the extensions for the PHP that runs the consumer, or consume one message at a time: leave out the `coroutines` option of ecotone:run, ExecutionPollingMetadata::withCoroutines() and PollingMetadata::withCoroutines(). '
                . 'To handle more messages at once without coroutines, start more processes of this consumer.'
            );
        }

        $coroutineClass = $runtime . '\\Coroutine';
        /** @var ConsumerLifecycle[] $consumers */
        $consumers = [];
        /** @var ArrayObject<int, Throwable> $failures */
        $failures = new ArrayObject();
        $consumeOnCoroutines = static function () use ($coroutineClass, $coroutines, $createConsumer, &$consumers, $failures): void {
            $channelClass = $coroutineClass . '\\Channel';
            $finished = new $channelClass($coroutines);
            for ($coroutine = 1; $coroutine <= $coroutines; $coroutine++) {
                $coroutineClass::create(static function () use ($createConsumer, $finished, &$consumers, $failures): void {
                    try {
                        if ($failures->count() === 0) {
                            $consumer = $createConsumer();
                            $consumers[] = $consumer;
                            $consumer->run();
                        }
                    } catch (Throwable $exception) {
                        $failures->append($exception);
                        foreach ($consumers as $consumer) {
                            $consumer->stop();
                        }
                    } finally {
                        $finished->push(true);
                    }
                });
            }
            for ($coroutine = 1; $coroutine <= $coroutines; $coroutine++) {
                $finished->pop();
            }
        };

        if ($coroutineClass::getCid() > 0) {
            $consumeOnCoroutines();
        } else {
            $runtimeClass = $runtime . '\\Runtime';
            $hooksOfTheApplication = $runtimeClass::getHookFlags();
            if ($hooksOfTheApplication === 0) {
                $runtimeClass::enableCoroutine($runtime === 'OpenSwoole' ? $runtimeClass::HOOK_ALL : constant('SWOOLE_HOOK_ALL'));
            }
            try {
                $schedulerClass = $coroutineClass . '\\Scheduler';
                $scheduler = new $schedulerClass();
                $scheduler->add($consumeOnCoroutines);
                $scheduler->start();
            } finally {
                $runtimeClass::enableCoroutine($hooksOfTheApplication);
            }
        }

        foreach ($failures as $failure) {
            throw $failure;
        }
    }

    private static function coroutineRuntime(): ?string
    {
        return match (true) {
            extension_loaded('openswoole') => 'OpenSwoole',
            extension_loaded('swoole') => 'Swoole',
            default => null,
        };
    }
}
