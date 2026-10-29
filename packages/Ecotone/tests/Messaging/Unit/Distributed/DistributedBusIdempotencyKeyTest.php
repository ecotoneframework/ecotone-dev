<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Distributed;

use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
final class DistributedBusIdempotencyKeyTest extends TestCase
{
    public static function sends(): iterable
    {
        yield 'sendCommand' => [fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'Printer is broken', idempotencyKey: ' ')];
        yield 'convertAndSendCommand' => [fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->convertAndSendCommand('ticket_service', 'ticket.create', ['description' => 'Printer is broken'], idempotencyKey: '')];
        yield 'publishEvent' => [fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->publishEvent('user.registered', 'johny', idempotencyKey: '')];
        yield 'convertAndPublishEvent' => [fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->convertAndPublishEvent('user.registered', ['name' => 'johny'], idempotencyKey: '')];
        yield 'sendMessage' => [fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: '')];
    }

    #[DataProvider('sends')]
    public function test_a_blank_idempotency_key_is_refused_because_it_would_make_every_send_one_request(callable $send): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'),
                DistributedServiceMap::initialize()
                    ->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel')
                    ->withEventMapping(channelName: 'ticket_channel', subscriptionKeys: ['user.*']),
                ServiceConfiguration::createWithDefaults()->withServiceName('user_service'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Distributed Bus was given a blank idempotencyKey. The target deduplicates on this key in place of the message id, per routing key and consuming endpoint, so a blank one would make every send to that routing key one request, handled once. Pass a key that names this business request, such as the order id, or omit idempotencyKey to have each send handled on its own.');

        $send($ecotone);
    }
}
