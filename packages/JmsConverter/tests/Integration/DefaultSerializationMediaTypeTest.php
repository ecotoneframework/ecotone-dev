<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Integration;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\ExtensionObject\MediaType;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Test\ChannelTestSupport;
use PHPUnit\Framework\TestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class DefaultSerializationMediaTypeTest extends TestCase
{
    public function test_a_queue_channel_serializes_to_json_when_the_jms_converter_is_installed_and_no_media_type_was_chosen(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->handler()::class],
            [$this->handler()],
            extensionObjects: [SimpleMessageChannelBuilder::createQueueChannel('async')],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );

        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'order-1']);

        $this->assertSame(MediaType::APPLICATION_JSON, ChannelTestSupport::of($ecotone)->getMessageChannel('async')->receive()->getHeaders()->getContentType()->toString());
    }

    public function test_the_media_type_chosen_on_service_configuration_wins_over_the_jms_converter_default(): void
    {
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$this->handler()::class],
            [$this->handler()],
            extensionObjects: [
                ServiceConfiguration::createWithDefaults()->withDefaultSerializationMediaType(MediaType::APPLICATION_X_PHP_SERIALIZED),
                SimpleMessageChannelBuilder::createQueueChannel('async'),
            ],
            enabledModules: [ModulePackageList::JMS_CONVERTER_PACKAGE],
        );

        $ecotone->sendCommandWithRouting('order.place', ['orderId' => 'order-1']);

        $this->assertSame(MediaType::APPLICATION_X_PHP_SERIALIZED, ChannelTestSupport::of($ecotone)->getMessageChannel('async')->receive()->getHeaders()->getContentType()->toString());
    }

    private function handler(): object
    {
        return new class () {
            #[Asynchronous('async')]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(array $order): void
            {
            }
        };
    }
}
