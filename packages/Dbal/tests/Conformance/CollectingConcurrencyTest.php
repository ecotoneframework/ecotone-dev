<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Around;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\TestConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Gateway\GatheredGroups;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\CollectedMessages;
use Ecotone\Api\Messaging\Message;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class CollectingConcurrencyTest extends TestCase
{
    use ConcurrentConsumers;

    public function test_batch_is_released_once_when_last_messages_are_recorded_in_a_real_database_race(): void
    {
        $this->proveConcurrentBatch(true);
    }

    public function test_consumers_opening_the_same_generation_in_a_real_database_race_keep_one_batch_and_resend_the_loser(): void
    {
        $this->proveConcurrentBatch(false);
    }


    private function proveConcurrentBatch(bool $alreadyOpen): void
    {
        if ((new DbalConnectionFactory(getenv('DATABASE_DSN')))->createContext()->getDbalConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('SQLite serialises writers with a database-wide lock, so this two-connection race is proved on PostgreSQL, MySQL and MariaDB only.');
        }
        $application = self::concurrentApplication();
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));
        $testId = bin2hex(random_bytes(16));
        $previousFailures = array_map(static fn ($failure): string => $failure->getMessageId(), $application->getGateway(DeadLetterGateway::class)->list(1000, 0));
        if ($alreadyOpen) {
            $application->sendCommandWithRouting('collect.concurrent', ['testId' => $testId, 'value' => 1], metadata: ['testId' => $testId]);
            $application->run('collecting_concurrency');
        }
        foreach (range($alreadyOpen ? 2 : 1, 8) as $value) {
            $application->sendCommandWithRouting('collect.concurrent', ['testId' => $testId, 'value' => $value], metadata: ['testId' => $testId]);
        }
        $this->runConcurrentConsumers($alreadyOpen ? 7 : 8, __FILE__, 'consumeConcurrentMessage');
        $application->run('collecting_concurrency', ExecutionPollingMetadata::createWithFinishWhenNoMessages(stopOnError: false));

        $results = array_values(array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('collecting_concurrent_results'), static fn (string $document): bool => json_decode($document, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId));
        self::assertCount(1, $results);
        $result = json_decode($results[0], true, flags: JSON_THROW_ON_ERROR);
        sort($result['values']);
        self::assertSame(range(1, 8), $result['values']);
        $open = array_filter($application->getGateway(GatheredGroups::class)->listOpenBatches('collect.concurrent.handler'), static fn (array $batch): bool => $batch['groupKey'] === $testId);
        self::assertSame([], $open);
        $closed = array_filter($application->getGateway(GatheredGroups::class)->listClosedBatches('collect.concurrent.handler'), static fn (array $batch): bool => $batch['groupKey'] === $testId);
        self::assertCount(1, $closed);
        $failures = array_filter($application->getGateway(DeadLetterGateway::class)->list(1000, 0), static fn ($failure): bool => ! in_array($failure->getMessageId(), $previousFailures, true));
        self::assertSame([], $failures);
        $deliveries = array_filter($application->getGateway(DocumentStore::class)->getAllDocuments('collecting_concurrent_deliveries'), static fn (string $document): bool => json_decode($document, true, flags: JSON_THROW_ON_ERROR)['testId'] === $testId);
        self::assertGreaterThan(8, count($deliveries));
    }

    public static function consumeConcurrentMessage(): void
    {
        self::concurrentApplication([self::concurrentConsumerBarrier()])->run('collecting_concurrency', ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, stopOnError: false)->withExecutionAmountLimit(1));
    }

    private static function concurrentApplication(array $additionalServices = []): FlowTestSupport
    {
        $handler = new class () {
            #[Collected(maxSize: 8, groupBy: 'payload["testId"]')]
            #[Asynchronous('collecting_concurrency')]
            #[CommandHandler('collect.concurrent', endpointId: 'collect.concurrent.handler')]
            public function collect(CollectedMessages $batch, #[Reference] DocumentStore $documents): void
            {
                $documents->addDocument('collecting_concurrent_results', bin2hex(random_bytes(16)), json_encode(['testId' => $batch->groupKey, 'values' => array_column($batch->payloads, 'value')], JSON_THROW_ON_ERROR));
            }
        };
        $deliveries = new class () {
            #[Around(precedence: Precedence::DATABASE_TRANSACTION_PRECEDENCE + 102, pointcut: AsynchronousRunningEndpoint::class)]
            public function recordDelivery(MethodInvocation $invocation, Message $message, #[Header('testId')] string $testId, #[Reference] DocumentStore $documents): mixed
            {
                $result = $invocation->proceed();
                $documents->addDocument('collecting_concurrent_deliveries', $message->getHeaders()->getMessageId(), json_encode(['testId' => $testId], JSON_THROW_ON_ERROR));
                return $result;
            }
        };
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: [$handler::class, $deliveries::class, ...array_map(static fn (object $service): string => $service::class, $additionalServices)],
            containerOrAvailableServices: [$handler, $deliveries, ...$additionalServices, DbalConnectionFactory::class => new DbalConnectionFactory(getenv('DATABASE_DSN'))],
            extensionObjects: [DbalBackedMessageChannelBuilder::create('collecting_concurrency'), ServiceConfiguration::createWithDefaults()->withDefaultErrorChannel('dbal_dead_letter')],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            testConfiguration: TestConfiguration::createWithDefaults()->withDefaultInstantRetries(false),
            initialTime: new DateTimeImmutable('2026-01-01 12:00:00 UTC'),
        );
    }
}
