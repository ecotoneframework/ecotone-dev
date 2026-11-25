<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Kafka\KafkaBrokerConfiguration;
use Ecotone\Api\Kafka\KafkaMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Test\CoroutineTesting;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\Kafka\ConnectionTestCase;

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
#[RequiresMethod(Coroutine::class, 'create')]
final class CoroutineExecutionsTest extends TestCase
{
    public function test_coroutines_publishing_to_a_topic_at_once_publish_every_message_once_with_its_own_headers(): void
    {
        $ecotone = $this->bootstrapOrders();

        CoroutineTesting::runAtOnce(...$this->publishersOfOrders($ecotone, publishers: 4, ordersOfEachPublisher: 25));
        $ecotone->run('coroutine_orders', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 100, executionTimeLimitInMilliseconds: 15000));

        $this->assertEquals($this->ordersWithTheirTenants(publishers: 4, ordersOfEachPublisher: 25), $ecotone->sendQueryWithRouting('order.placed'));
    }

    /**
     * @return callable[]
     */
    private function publishersOfOrders(FlowTestSupport $ecotone, int $publishers, int $ordersOfEachPublisher): array
    {
        $coroutines = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            $coroutines[] = static function () use ($ecotone, $publisher, $ordersOfEachPublisher): void {
                for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                    $ecotone->sendCommandWithRouting('order.register', "order-{$publisher}-{$order}", metadata: ['tenant' => "tenant-{$publisher}-{$order}"]);
                    usleep(1_000);
                }
            };
        }

        return $coroutines;
    }

    /**
     * @return array<string, string[]>
     */
    private function ordersWithTheirTenants(int $publishers, int $ordersOfEachPublisher): array
    {
        $orders = [];
        for ($publisher = 1; $publisher <= $publishers; $publisher++) {
            for ($order = 1; $order <= $ordersOfEachPublisher; $order++) {
                $orders["order-{$publisher}-{$order}"] = ["tenant-{$publisher}-{$order}"];
            }
        }

        return $orders;
    }

    private function bootstrapOrders(): FlowTestSupport
    {
        $orders = new class () {
            private array $placed = [];

            #[Asynchronous('coroutine_orders')]
            #[CommandHandler('order.register', endpointId: 'order.register.endpoint')]
            public function place(string $orderId, #[Header('tenant')] string $tenant): void
            {
                $this->placed[$orderId][] = $tenant;
            }

            #[QueryHandler('order.placed')]
            public function placed(): array
            {
                ksort($this->placed);

                return $this->placed;
            }
        };
        $topicName = 'coroutine_orders_' . Uuid::v7()->toRfc4122();

        return EcotoneLite::bootstrapFlowTesting(
            [$orders::class],
            [$orders, KafkaBrokerConfiguration::class => ConnectionTestCase::getConnection()],
            extensionObjects: [KafkaMessageChannelBuilder::create('coroutine_orders', topicName: $topicName, messageGroupId: $topicName)],
            enabledModules: [ModulePackageList::KAFKA_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
    }
}
