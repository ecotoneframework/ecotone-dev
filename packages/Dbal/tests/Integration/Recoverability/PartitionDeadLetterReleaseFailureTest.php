<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration\Recoverability;

use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Gateway\PartitionDeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Test\LicenceTesting;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class PartitionDeadLetterReleaseFailureTest extends DbalMessagingTestCase
{
    private object $orders;
    private object $connectionLoss;

    public function test_a_replayed_message_failing_after_its_transaction_committed_is_not_handled_again_when_it_is_retried_and_lets_the_next_one_follow(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults());
        $this->failTheFirstOfTwoMessagesOfOrder($ecotone);

        $this->orders->broken = [];
        $this->connectionLoss->failuresAfterCommit = 1;
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        self::assertSame(['broken', 'coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->getGateway(PartitionDeadLetterGateway::class)->list());
    }

    public function test_a_replayed_message_dead_lettered_after_its_transaction_committed_is_not_handled_again_when_it_is_replayed_and_lets_the_next_one_follow(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults(), instantRetries: false);
        $this->failTheFirstOfTwoMessagesOfOrder($ecotone);

        $this->orders->broken = [];
        $this->connectionLoss->failuresAfterCommit = 1;
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);
        self::assertSame(['broken'], $this->orders->placed);
        self::assertSame(2, $ecotone->getGateway(DeadLetterGateway::class)->count());

        $ecotone->getGateway(PartitionDeadLetterGateway::class)->replay('partitionRelease.place.endpoint', '1');
        $this->consume($ecotone);

        self::assertSame(['broken', 'coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_without_deduplication_a_replayed_message_failing_after_its_transaction_committed_is_handled_again_before_the_next_one_follows(): void
    {
        $ecotone = $this->bootstrap(DbalConfiguration::createWithDefaults()->withDeduplication(false));
        $this->failTheFirstOfTwoMessagesOfOrder($ecotone);

        $this->orders->broken = [];
        $this->connectionLoss->failuresAfterCommit = 1;
        $ecotone->getGateway(DeadLetterGateway::class)->replay('m-1');
        $this->consume($ecotone);

        self::assertSame(['broken', 'broken', 'coffee'], $this->orders->placed);
        self::assertSame(0, $ecotone->getGateway(DeadLetterGateway::class)->count());
    }

    private function failTheFirstOfTwoMessagesOfOrder(FlowTestSupport $ecotone): void
    {
        $ecotone->sendCommandWithRouting('partitionRelease.place', 'broken', metadata: ['orderId' => '1', MessageHeaders::MESSAGE_ID => 'm-1']);
        $ecotone->sendCommandWithRouting('partitionRelease.place', 'coffee', metadata: ['orderId' => '1', MessageHeaders::MESSAGE_ID => 'm-2']);
        $this->consume($ecotone);
    }

    private function consume(FlowTestSupport $ecotone): void
    {
        $ecotone->run('partitionRelease.orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
    }

    private function bootstrap(DbalConfiguration $dbalConfiguration, bool $instantRetries = true): FlowTestSupport
    {
        $this->orders = new class () {
            public array $placed = [];
            public array $broken = ['broken'];

            #[PartitionDeadLetter(partitionHeaderName: 'orderId')]
            #[Asynchronous('partitionRelease.orders')]
            #[CommandHandler('partitionRelease.place', endpointId: 'partitionRelease.place.endpoint')]
            public function place(string $item): void
            {
                if (in_array($item, $this->broken, true)) {
                    throw new RuntimeException("cannot place {$item}");
                }

                $this->placed[] = $item;
            }
        };
        $this->connectionLoss = new class () {
            public int $failuresAfterCommit = 0;

            #[Around(precedence: Precedence::BETWEEN_INSTANT_RETRY_AND_TRANSACTION_PRECEDENCE, pointcut: AsynchronousRunningEndpoint::class)]
            public function failAfterTheTransactionCommitted(MethodInvocation $methodInvocation): mixed
            {
                $result = $methodInvocation->proceed();
                if ($this->failuresAfterCommit-- > 0) {
                    throw new RuntimeException('the connection was lost after the commit');
                }

                return $result;
            }
        };

        return $this->bootstrapFlowTesting(
            classesToResolve: [$this->orders::class, $this->connectionLoss::class],
            containerOrAvailableServices: [$this->orders, $this->connectionLoss, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter'),
                $dbalConfiguration->withDeadLetter(true),
                DbalBackedMessageChannelBuilder::create('partitionRelease.orders'),
                InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry($instantRetries),
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
    }
}
