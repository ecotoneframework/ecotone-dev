<?php

namespace Test\Ecotone\Amqp\Configuration;

use Ecotone\Amqp\AmqpAdmin;
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Ecotone\Amqp\AmqpBinding;
use Ecotone\Amqp\AmqpExchange;
use Ecotone\Amqp\AmqpQueue;
use Ecotone\Amqp\Configuration\AmqpModule;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\InMemoryModuleMessaging;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\MessagingException;
use Test\Ecotone\Amqp\AmqpMessagingTestCase;

/**
 * Class AmqpModuleTest
 * @package Test\Ecotone\Amqp
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
final class AmqpModuleTest extends AmqpMessagingTestCase
{
    public function test_registering_amqp_backed_message_channel()
    {
        $this->assertEquals(
            AmqpAdmin::createWith([], [AmqpQueue::createWith('some')], []),
            $this->prepareConfigurationAndRetrieveAmqpAdmin(
                [
                    AmqpBackedMessageChannelBuilder::create('some', 'amqpConnection'),
                ]
            )
        );
    }

    public function test_registering_amqp_configuration()
    {
        $amqpExchange = AmqpExchange::createDirectExchange('exchange');
        $amqpQueue    = AmqpQueue::createWith('queue');
        $amqpBinding  = AmqpBinding::createFromNames('exchange', 'queue', 'route');

        $this->assertEquals(
            AmqpAdmin::createWith([$amqpExchange], [$amqpQueue], [$amqpBinding]),
            $this->prepareConfigurationAndRetrieveAmqpAdmin([$amqpExchange, $amqpQueue, $amqpBinding])
        );
    }

    private function prepareConfigurationAndRetrieveAmqpAdmin(array $extensions): AmqpAdmin
    {
        $messagingSystem = $this->prepareConfiguration($extensions);

        return $messagingSystem->getServiceFromContainer(AmqpAdmin::REFERENCE_NAME);
    }

    /**
     * @param array $extensions
     *
     * @throws MessagingException
     */
    private function prepareConfiguration(array $extensions): ConfiguredMessagingSystem
    {
        $cqrsMessagingModule = AmqpModule::create(InMemoryAnnotationFinder::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $extendedConfiguration        = MessagingSystemConfiguration::prepareWithDefaults(InMemoryModuleMessaging::createWith([$cqrsMessagingModule], $extensions));

        return $extendedConfiguration->buildMessagingSystemFromConfiguration();
    }
}
