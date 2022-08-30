<?php

namespace Test\Ecotone\Dbal\Configuration;

use Doctrine\Common\Annotations\AnnotationException;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Dbal\Configuration\DbalMessagePublisherConfiguration;
use Ecotone\Dbal\Configuration\DbalPublisherModule;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConverterModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ErrorHandlerModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\InMemoryModuleMessaging;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Conversion\ReferenceServiceConverterBuilder;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderValueBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Recoverability\ErrorHandlerConfiguration;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\Support\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use Test\Ecotone\Messaging\Fixture\Annotation\Converter\ExampleConverterService;

/**
 * @internal
 */
class ErrorHandlerModuleTest extends AnnotationConfigurationTest
{
    public function test_registering_module_with_default_error_handling()
    {
        $errorHandlerModuleWithCustom = $this->createMessagingSystemConfiguration();
        ErrorHandlerModule::create(InMemoryAnnotationFinder::createEmpty(),InterfaceToCallRegistry::createEmpty())
            ->prepare($errorHandlerModuleWithCustom, [
                ErrorHandlerConfiguration::createDefault(),
                DbalConfiguration::createWithDefaults()->withDeadLetter(true)
            ], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $errorHandlerModuleWithDefault = $this->createMessagingSystemConfiguration();
        ErrorHandlerModule::create(InMemoryAnnotationFinder::createEmpty(),InterfaceToCallRegistry::createEmpty())
            ->prepare($errorHandlerModuleWithDefault, [
                DbalConfiguration::createWithDefaults()->withDeadLetter(true)
            ], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $this->assertEquals(
            $errorHandlerModuleWithCustom,
            $errorHandlerModuleWithDefault
        );
    }
}
