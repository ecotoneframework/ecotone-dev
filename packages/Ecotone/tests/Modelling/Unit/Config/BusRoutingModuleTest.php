<?php

namespace Test\Ecotone\Modelling\Unit\Config;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Messaging\Attribute\PropagateHeaders;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\InMemoryModuleMessaging;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypointWithHeadersPropagation;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\Config\MessageHandlerLogger;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\AllHeadersBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\Config\BusRouterBuilder;
use Ecotone\Modelling\Config\MessageHandlerRoutingModule;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Modelling\QueryBus;
use stdClass;
use Test\Ecotone\Messaging\Unit\MessagingTestCase;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\AggregateCommandHandlerExample;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\AggregateCommandHandlerWithDoubledActionMethod;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\AggregateCommandHandlerWithDoubledFactoryMethod;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\AggregateCommandHandlerWithFactoryMethod;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\ServiceCommandHandlerWithInputChannelName;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate\ServiceCommandHandlerWithInputChannelNameAndIgnoreMessage;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\AggregateCommandHandlerWithClass;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\AggregateCommandHandlerWithInputChannelName;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\AggregateCommandHandlerWithInputChannelNameAndIgnoreMessage;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\AggregateCommandHandlerWithInputChannelNameAndObject;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\CommandHandlerWithNoInputChannelName;
use Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Service\CommandHandlerWithUnionType;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Aggregate\AggregateEventHandlerWithClass;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Aggregate\AggregateEventHandlerWithListenToRegex;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\EventHandlerForUnionType;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service\ServiceEventHandlerWithClass;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service\ServiceEventHandlerWithListenTo;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service\ServiceEventHandlerWithListenToAndObject;
use Test\Ecotone\Modelling\Fixture\Annotation\EventHandler\Service\ServiceEventHandlerWithListenToToRegex;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Aggregate\AggregateQueryHandlerWithClass;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Aggregate\AggregateQueryHandlerWithInputChannel;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Aggregate\AggregateQueryHandlerWithInputChannelAndIgnoreMessage;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlersWithAllowedNotUniqueClass;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlersWithAllowedNotUniqueClassAndInputChannels;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlersWithNotUniqueClass;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlerWithClass;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlerWithInputChannel;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlerWithInputChannelAndIgnoreMessage;
use Test\Ecotone\Modelling\Fixture\Annotation\QueryHandler\Service\ServiceQueryHandlerWithInputChannelAndObject;
use Test\Ecotone\Modelling\Fixture\Handler\ServiceWithCommandAndQueryHandlersUnderSameClass;
use Test\Ecotone\Modelling\Fixture\Handler\ServiceWithCommandAndQueryHandlersUnderSameName;

/**
 * Class AggregateMessageRouterModuleTest
 * @package Test\Ecotone\Modelling\Config
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 * @internal
 */
class BusRoutingModuleTest extends MessagingTestCase
{
    public function test_registering_service_command_handler_for_union_type()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                CommandHandlerWithUnionType::class,
            ])
        );
    }

    private function prepareModule(AnnotationFinder $annotationRegistrationService): Configuration
    {
        $module = MessageHandlerRoutingModule::create($annotationRegistrationService, InterfaceToCallRegistry::createEmpty());

        $extendedConfiguration = MessagingSystemConfiguration::prepareWithDefaultsForTesting();
        $module->prepare(
            $extendedConfiguration,
            [],
            ModuleReferenceSearchService::createEmpty(),
            InterfaceToCallRegistry::createEmpty()
        );
        return $extendedConfiguration;
    }

    public function test_throwing_configuration_exception_if_command_handler_has_no_information_about_channel()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                CommandHandlerWithNoInputChannelName::class,
            ])
        );
    }

    public function test_throwing_exception_when_registering_non_unique_query_handlers()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                ServiceQueryHandlersWithNotUniqueClass::class,
            ])
        );
    }

    public function test_throwing_exception_when_query_and_command_are_non_unique_by_class_name()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                ServiceWithCommandAndQueryHandlersUnderSameClass::class,
            ])
        );
    }

    public function test_throwing_exception_when_query_and_command_are_non_unique_by_name()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                ServiceWithCommandAndQueryHandlersUnderSameName::class,
            ])
        );
    }

    public function test_throwing_exception_when_not_unique_aggregate_factory_methods()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                AggregateCommandHandlerWithDoubledFactoryMethod::class,
            ])
        );
    }

    public function test_throwing_exception_when_not_unique_aggregate_action_methods()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                AggregateCommandHandlerWithDoubledActionMethod::class,
            ])
        );
    }

    public function test_throwing_when_factory_and_action_channels_are_same_between_different_aggregates()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                AggregateCommandHandlerExample::class,
                AggregateCommandHandlerWithFactoryMethod::class,
            ])
        );
    }

    public function test_throwing_exception_if_event_handler_with_listen_regex_registered_for_aggregate()
    {
        $this->expectException(ConfigurationException::class);

        $this->prepareModule(
            InMemoryAnnotationFinder::createFrom([
                AggregateEventHandlerWithListenToRegex::class,
            ])
        );
    }
}
