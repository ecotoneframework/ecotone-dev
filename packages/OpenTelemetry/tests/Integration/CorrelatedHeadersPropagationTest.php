<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use ArrayObject;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\MessageHeaders;
use InvalidArgumentException;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use Symfony\Component\Uid\Uuid;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\CreateMerchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\Merchant;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantCreated;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\MerchantSubscriberOne;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\RegisterUser;
use Test\Ecotone\OpenTelemetry\Fixture\CommandEventFlow\User;

/**
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class CorrelatedHeadersPropagationTest extends TracingTestCase
{
    public function test_tracing_with_single_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
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

        $this->assertSpanWith('process User::register', $spans[0], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('send command', $spans[1], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_two_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter), new MerchantSubscriberOne()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->publishEvent(new MerchantCreated('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        $this->assertCount(5, $spans);

        $this->assertChildSpan('process User::register', $spans[0], $messageId, $correlationId);
        $this->assertChildSpan('send command', $spans[1], $messageId, $correlationId);

        $this->assertSpanWith('process MerchantSubscriberOne::merchantToUser', $spans[2], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('route merchant.subscriber.one', $spans[3], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('send event', $spans[4], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_with_three_levels_of_nesting()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [Merchant::class, User::class, MerchantSubscriberOne::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter), new MerchantSubscriberOne()],
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
        )
            ->sendCommand(new CreateMerchant('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ]);

        /** @var ImmutableSpan[] $spans */
        $spans = $exporter->getSpans();
        $this->assertCount(7, $spans);

        $this->assertChildSpan('process User::register', $spans[0], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);
        $this->assertChildSpan('send command', $spans[1], $spans[2]->getAttributes()->get(MessageHeaders::MESSAGE_ID), $correlationId);

        $this->assertChildSpan('process MerchantSubscriberOne::merchantToUser', $spans[2], $messageId, $correlationId);
        $this->assertChildSpan('route merchant.subscriber.one', $spans[3], $messageId, $correlationId);
        $this->assertChildSpan('send event', $spans[4], $messageId, $correlationId);

        $this->assertSpanWith('process Merchant::create', $spans[5], $messageId, $correlationId, $timestamp);
        $this->assertSpanWith('send command', $spans[6], $messageId, $correlationId, $timestamp);
    }

    public function test_tracing_same_message_for_asynchronous_scenario()
    {
        $exporter = new InMemoryExporter(new ArrayObject());

        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $timestamp = 1680436648;

        EcotoneLite::bootstrapFlowTesting(
            [\Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow\User::class],
            [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async_channel')],
            enabledModules: [ModulePackageList::TRACING_PACKAGE, ],
        )
            ->sendCommand(new RegisterUser('1'), [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
                MessageHeaders::TIMESTAMP => $timestamp,
            ])
            ->run('async_channel', ExecutionPollingMetadata::createWithTestingSetup());

        $node = $this->getNodeAtTargetedSpan(
            [
                'details' => ['name' => 'send command'],
                'child' => [
                    'details' => ['name' => 'send async_channel'],
                    'child' => [
                        'details' => ['name' => 'receive async_channel'],
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

        $messageId = Uuid::v7()->toRfc4122();
        $correlationId = Uuid::v7()->toRfc4122();
        $timestamp = 1680436648;

        try {
            EcotoneLite::bootstrapFlowTesting(
                [\Test\Ecotone\OpenTelemetry\Fixture\ExceptionFlow\User::class],
                [TracerProviderInterface::class => TracingTestCase::prepareTracer($exporter)],
                enabledModules: [ModulePackageList::TRACING_PACKAGE],
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
        $event = current(array_filter($spans[0]->getEvents(), fn ($event): bool => $event->getName() === 'exception'));
        $this->assertSame('exception', $event->getName());
        $this->assertSame('User already registered', $event->getAttributes()->get('exception.message'));

        /** Command Bus */
        $this->assertSame(StatusCode::STATUS_ERROR, $spans[1]->getStatus()->getCode());
        $event = current(array_filter($spans[1]->getEvents(), fn ($event): bool => $event->getName() === 'exception'));
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
