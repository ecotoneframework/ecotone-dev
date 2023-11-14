<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\MessageHeaders;
use InvalidArgumentException;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberOne;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

/**
 * @internal
 */
final class CorrelatedHeadersPropagationTest extends TracingTest
{
    public function test_tracing_with_single_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new RegisterUser('1'), metadata: [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(2, $spans);

        $this->assertSpanWith('Command Handler: ' . User::class . '::register', $spans[0], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Command Bus', $spans[1], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_two_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->publishEvent(new MerchantCreated('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(4, $spans);

        $this->assertChildSpan('Command Handler: ' . User::class . '::register', $spans[0], $messageId, $correlationId);
        $this->assertChildSpan('Command Bus', $spans[1], $messageId, $correlationId);

        $this->assertSpanWith('Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser', $spans[2], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Event Bus', $spans[3], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_three_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter), new MerchantSubscriberOne()],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
        )
            ->sendCommand(new CreateMerchant('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(6, $spans);

        $this->assertChildSpan('Command Handler: ' . User::class . '::register', $spans[0], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);
        $this->assertChildSpan('Command Bus', $spans[1], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);

        $this->assertChildSpan('Event Handler: ' . MerchantSubscriberOne::class . '::merchantToUser', $spans[2], $messageId, $correlationId);
        $this->assertChildSpan('Event Bus', $spans[3], $messageId, $correlationId);

        $this->assertSpanWith('Command Handler: ' . Merchant::class . '::create', $spans[4], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('Command Bus', $spans[5], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_same_message_for_asynchronous_scenario()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
            ServiceConfiguration::createWithDefaults()
                ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE, ModulePackageList::ASYNCHRONOUS_PACKAGE]))
                ->withExtensionObjects([
                    SimpleMessageChannelBuilder::createQueueChannel('async_channel'),
                ])
        )
            ->sendCommand(new RegisterUser('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $node = $this->getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'Command Bus'],
                'child' => [
                    'details' => ['name' => 'Sending to Channel: async_channel'],
                    'child' => [
                        'details' => ['name' => 'Receiving from channel: async_channel'],
                    ],
                ],
            ],
            self::buildTree($exporter)
        );

        $this->assertSame(
            $correlationId,
            $node['details']['attributes'][MessageHeaders::MESSAGE_CORRELATION_ID]
        );
        $this->assertSame(
            $correlationId,
            $node['children'][array_key_first($node['children'])]['details']['attributes'][MessageHeaders::MESSAGE_CORRELATION_ID]
        );
        $this->assertSame(
            $messageId,
            $node['details']['attributes'][MessageHeaders::MESSAGE_ID]
        );
        $this->assertSame(
            $messageId,
            $node['children'][array_key_first($node['children'])]['details']['attributes'][MessageHeaders::MESSAGE_ID]
        );
    }

    public function test_tracing_with_exception()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::uuid4()->toString();
        $correlationId = Uuid::uuid4()->toString();
        $timestamp = 1680436648;

        try {
            EcotoneLite::bootstrapFlowTesting(
                [\Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow\User::class],
                [TracerProviderInterface::class => TracingTest::prepareTracer($exporter)],
                ServiceConfiguration::createWithDefaults()
                    ->withSkippedModulePackageNames(ModulePackageList::allPackagesExcept([ModulePackageList::TRACING_PACKAGE]))
            )
                ->sendCommand(new RegisterUser('1'), metadata: [
                    MessageHeaders::MESSAGE_ID => $messageId,
                    MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                    MessageHeaders::TIMESTAMP => $timestamp,
                ]);
        } catch (InvalidArgumentException) {
        }

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        /** Command Bus and Command Handler */
        $this->assertCount(2, $spans);

        /** Command Handler */
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
        $event = $spans[0]->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('User already registered', $event->getAttributes()->get('exception.message'));

        /** Command Bus */
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[1]->getStatus()->getCode());
        $event = $spans[1]->getEvents()[0];
        $this->assertSame('exception', $event->getName());
        $this->assertSame('User already registered', $event->getAttributes()->get('exception.message'));
    }

    private function assertSpanWith(string $expectedName, ImmutableSpan $span, string $messageId, string $correlationId, int $timestamp): void
    {
        $this->assertSame(
            $expectedName,
            $span->getName()
        );
        $attributes = $span->getAttributes()->toArray();
        $this->assertSame($messageId, $attributes[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($correlationId, $attributes[MessageHeaders::MESSAGE_CORRELATION_ID]);
        $this->assertSame($timestamp, $attributes[MessageHeaders::TIMESTAMP]);
        $this->assertSame(StatusCode::STATUS_OK, $span->getStatus()->getCode());
    }

    private function assertChildSpan(string $expectedName, ImmutableSpan $span, string $messageId, string $correlationId): void
    {
        $this->assertSame(
            $expectedName,
            $span->getName()
        );
        $attributes = $span->getAttributes()->toArray();
        $this->assertNotSame($messageId, $attributes[MessageHeaders::MESSAGE_ID]);
        $this->assertSame($messageId, $attributes[MessageHeaders::PARENT_MESSAGE_ID]);
        $this->assertSame($correlationId, $attributes[MessageHeaders::MESSAGE_CORRELATION_ID]);
    }
}
