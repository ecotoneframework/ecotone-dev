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
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\ArrayToJson\ArrayToJsonConverterBuilder;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\InMemoryReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;
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

    public function test_registering_amqp_backed_message_channel_with_application_media_type()
    {
        $amqpChannelBuilder = AmqpBackedMessageChannelBuilder::create('amqpChannel');
        $messagingSystem    = MessagingSystemConfiguration::prepareWithDefaults(
            InMemoryModuleMessaging::createWith(
                [AmqpModule::create(InMemoryAnnotationFinder::createEmpty(), InterfaceToCallRegistry::createEmpty())],
                [
                    ServiceConfiguration::createWithDefaults()
                        ->withDefaultSerializationMediaType(MediaType::APPLICATION_JSON),
                    $amqpChannelBuilder,
                ]
            )
        )
            ->registerMessageChannel($amqpChannelBuilder)
            ->registerConverter(new ArrayToJsonConverterBuilder())
            ->buildMessagingSystemFromConfiguration(
                InMemoryReferenceSearchService::createWith(
                    [
                        AmqpConnectionFactory::class => $this->getCachedConnectionFactory(),
                    ]
                )
            );

        /** @var PollableChannel $channel */
        $channel = $messagingSystem->getMessageChannelByName('amqpChannel');
        $channel->send(MessageBuilder::withPayload([1, 2, 3])->setContentType(MediaType::createApplicationXPHPArray())->build());

        $this->assertEquals(
            '[1,2,3]',
            $channel->receive()->getPayload()
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
