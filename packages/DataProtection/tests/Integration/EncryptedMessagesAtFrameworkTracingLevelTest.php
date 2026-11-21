<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Integration;

use DateTimeImmutable;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\DataProtection\DataProtectionConfiguration;
use Ecotone\Api\DataProtection\Key;
use Ecotone\Api\DataProtection\Sensitive;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Tracing\RecordedProcess;
use Ecotone\Api\Tracing\RecordedStep;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\TestCase;
use Test\Ecotone\DataProtection\TestQueueChannel;

/**
 * licence Apache-2.0
 * @internal
 */
final class EncryptedMessagesAtFrameworkTracingLevelTest extends TestCase
{
    public function test_protected_messages_are_encrypted_on_the_channel_and_recorded_without_their_protected_values_at_the_framework_tracing_level(): void
    {
        $orders = new class () {
            public array $placed = [];

            #[Asynchronous('orders')]
            #[CommandHandler(endpointId: 'placeOrder')]
            public function place(OrderWithCardNumber $order): void
            {
                $this->placed[] = $order;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$orders::class, OrderWithCardNumber::class],
            [$orders],
            [
                DataProtectionConfiguration::create('primary', Key::createNewRandomKey()),
                SimpleMessageChannelBuilder::create('orders', $channel = TestQueueChannel::create('orders')),
                JMSConverterConfiguration::createWithDefaults(),
                TracingConfiguration::createWithDefaults()->withLevel(TracingLevel::Framework),
            ],
            enabledModules: [ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable('2026-11-23 10:00:00'),
        );

        $ecotone->sendCommand($sent = new OrderWithCardNumber(orderId: 'order-1', cardNumber: '4111-1111-1111-1111'));
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $recording = $ecotone->popExecutionRecording();

        $onTheChannel = json_decode($channel->getLastSentMessage()->getPayload(), true);
        self::assertNotSame('4111-1111-1111-1111', $onTheChannel['cardNumber']);
        self::assertStringNotContainsString('4111-1111-1111-1111', $channel->getLastSentMessage()->getPayload());
        self::assertEquals([$sent], $orders->placed);
        self::assertSame(
            ['send command ' . OrderWithCardNumber::class, 'receive orders, class@anonymous::place'],
            array_map(fn (RecordedProcess $process): string => $process->title, $recording->processes),
        );
        self::assertContains('convert OrderWithCardNumber (application/x-php) to string (application/json)', array_map(fn (RecordedStep $step): string => $step->label, $recording->processes[0]->steps));
        self::assertContains('convert string (application/json) to OrderWithCardNumber (application/x-php)', array_map(fn (RecordedStep $step): string => $step->label, $recording->processes[1]->steps));
        self::assertSame([], preg_grep('/4111-1111-1111-1111|order-1/', $recording->linesWithEveryHint));
    }
}

#[Sensitive]
final class OrderWithCardNumber
{
    public function __construct(public string $orderId, public string $cardNumber)
    {
    }
}
