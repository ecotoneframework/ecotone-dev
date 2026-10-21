<?php

declare(strict_types=1);

namespace Ecotone\Redis\Pulse;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelDepth;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseChannelProbe;
use Enqueue\Redis\RedisContext;
use Interop\Queue\ConnectionFactory;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class RedisChannelDepthProbe implements PulseChannelProbe
{
    private const DEPTH_SCRIPT = <<<'LUA'
        local due = redis.call('zcount', KEYS[2], '-inf', ARGV[1])
        local scheduled = redis.call('zcount', KEYS[2], '(' .. ARGV[1], '+inf')
        return {redis.call('llen', KEYS[1]) + due, scheduled, redis.call('zcard', KEYS[3])}
        LUA;

    public function __construct(private ConnectionFactory $connectionFactory, private EcotoneClockInterface $clock)
    {
    }

    public function depthsOf(array $destinationByChannel, int $budgetInMilliseconds): array
    {
        /** @var RedisContext $context */
        $context = $this->connectionFactory->createContext();
        $now = (string) $this->clock->now()->getTimestamp();

        $depths = [];
        foreach ($destinationByChannel as $channel => $queueName) {
            [$ready, $delayed, $inFlight] = $context->getRedis()->eval(self::DEPTH_SCRIPT, [$queueName, $queueName . ':delayed', $queueName . ':reserved'], [$now]);
            $depths[$channel] = PulseChannelDepth::measured((int) $ready, (int) $delayed, (int) $inFlight);
        }

        return $depths;
    }
}
