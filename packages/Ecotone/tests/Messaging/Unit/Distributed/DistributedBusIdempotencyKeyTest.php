<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Distributed;

use Ecotone\Api\ExtensionObject\DistributedServiceMap;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\LicenceTesting;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

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
        foreach (self::sendsWith(' ') as $name => $send) {
            yield $name => [$send, false];
            yield $name . ' through an asynchronous Distributed Bus' => [$send, true];
        }
    }

    public static function sendsWithAKeyLongerThan255Characters(): iterable
    {
        foreach (self::sendsWith(str_repeat('k', 256)) as $name => $send) {
            yield $name => [$send, false];
            yield $name . ' through an asynchronous Distributed Bus' => [$send, true];
        }
    }

    #[DataProvider('sends')]
    public function test_a_blank_idempotency_key_is_refused_because_it_would_make_every_send_one_request(callable $send, bool $asynchronous): void
    {
        $ecotone = $this->userService($asynchronous);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The Distributed Bus was given a blank idempotencyKey. The target deduplicates on this key in place of the message id, per sending service, routing key and consuming endpoint, so a blank one would make every send from this service to that routing key one request, handled once. Pass a key that names this business request, such as the order id, or omit idempotencyKey to have each send handled on its own.');

        $send($ecotone);
    }

    #[DataProvider('sendsWithAKeyLongerThan255Characters')]
    public function test_an_idempotency_key_longer_than_255_characters_is_refused_because_the_target_cannot_store_it(callable $send, bool $asynchronous): void
    {
        $ecotone = $this->userService($asynchronous);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The Distributed Bus was given an idempotencyKey of 256 characters. The target stores the key in its deduplication table, which holds at most 255, so a longer one would fail every delivery of the message. Pass a key of at most 255 characters that names this business request, such as the order id, or a hash of a longer one.');

        $send($ecotone);
    }

    private static function sendsWith(string $idempotencyKey): iterable
    {
        yield 'sendCommand' => fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->sendCommand('ticket_service', 'ticket.create', 'Printer is broken', idempotencyKey: $idempotencyKey);
        yield 'convertAndSendCommand' => fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->convertAndSendCommand('ticket_service', 'ticket.create', ['description' => 'Printer is broken'], idempotencyKey: $idempotencyKey);
        yield 'publishEvent' => fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->publishEvent('user.registered', 'johny', idempotencyKey: $idempotencyKey);
        yield 'convertAndPublishEvent' => fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->convertAndPublishEvent('user.registered', ['name' => 'johny'], idempotencyKey: $idempotencyKey);
        yield 'sendMessage' => fn (FlowTestSupport $ecotone) => $ecotone->getDistributedBus()->sendMessage('ticket_service', 'ticket.confirm', 'seat-7', idempotencyKey: $idempotencyKey);
    }

    private function userService(bool $asynchronous): FlowTestSupport
    {
        $serviceMap = DistributedServiceMap::initialize()
            ->withCommandMapping(targetServiceName: 'ticket_service', channelName: 'ticket_channel')
            ->withEventMapping(channelName: 'ticket_channel', subscriptionKeys: ['user.*']);

        return EcotoneLite::bootstrapFlowTesting(
            extensionObjects: [
                SimpleMessageChannelBuilder::createQueueChannel('ticket_channel'),
                SimpleMessageChannelBuilder::createQueueChannel('user_outbox'),
                $asynchronous ? $serviceMap->withAsynchronousChannel('user_outbox') : $serviceMap,
                ServiceConfiguration::createWithDefaults()->withServiceName('user_service'),
            ],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
