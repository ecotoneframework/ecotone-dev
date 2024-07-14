<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration;

use Doctrine\Common\Annotations\AnnotationException;
use Ecotone\AnnotationFinder\InMemory\InMemoryAnnotationFinder;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConverterModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\SerializerModule;
use Ecotone\Messaging\Config\Container\ContainerConfig;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\EventDriven\EventDrivenConsumerBuilder;
use Ecotone\Messaging\Gateway\Converter\Serializer;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\TypeDefinitionException;
use Ecotone\Messaging\MessagingException;
use ReflectionException;
use stdClass;
use Test\Ecotone\Messaging\Fixture\Annotation\Converter\ExampleSingleConverterService;

/**
 * Class ConverterModuleTest
 * @package Test\Ecotone\Messaging\Unit\Config\Annotation\ModuleConfiguration
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 *
 * @internal
 */
/**
 * licence Apache-2.0
 */
class SerializerModuleTest extends AnnotationConfigurationTest
{
    /**
     * @throws AnnotationException
     * @throws ReflectionException
     * @throws TypeDefinitionException
     * @throws MessagingException
     */
    public function test_converting_from_php()
    {
        $annotationRegistrationService = InMemoryAnnotationFinder::createEmpty()
            ->registerClassWithAnnotations(ExampleSingleConverterService::class);
        $configuration = $this->createMessagingSystemConfiguration();

        $converterModule = ConverterModule::create($annotationRegistrationService, InterfaceToCallRegistry::createEmpty());
        $converterModule->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $serializerModule = SerializerModule::create($annotationRegistrationService, InterfaceToCallRegistry::createEmpty());
        $serializerModule->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $configuration->registerConsumerFactory(new EventDrivenConsumerBuilder());
        $container = InMemoryPSRContainer::createFromAssociativeArray([
            ExampleSingleConverterService::class => new ExampleSingleConverterService(),
            ServiceCacheConfiguration::REFERENCE_NAME => ServiceCacheConfiguration::noCache(),
        ]);
        $messagingSystem = ContainerConfig::buildMessagingSystemInMemoryContainer($configuration, $container);
        /** @var Serializer $gateway */
        $gateway = $messagingSystem->getGatewayByName(Serializer::class);

        $this->assertEquals(
            new stdClass(),
            $gateway->convertFromPHP('test', MediaType::createApplicationXPHPWithTypeParameter(stdClass::class)->toString())
        );
    }

    public function test_converting_to_php()
    {
        $annotationRegistrationService = InMemoryAnnotationFinder::createEmpty()
            ->registerClassWithAnnotations(ExampleSingleConverterService::class);
        $configuration = $this->createMessagingSystemConfiguration();

        $converterModule = ConverterModule::create($annotationRegistrationService, InterfaceToCallRegistry::createEmpty());
        $converterModule->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $serializerModule = SerializerModule::create($annotationRegistrationService, InterfaceToCallRegistry::createEmpty());
        $serializerModule->prepare($configuration, [], ModuleReferenceSearchService::createEmpty(), InterfaceToCallRegistry::createEmpty());

        $configuration->registerConsumerFactory(new EventDrivenConsumerBuilder());
        $container = InMemoryPSRContainer::createFromAssociativeArray([
            ExampleSingleConverterService::class => new ExampleSingleConverterService(),
            ServiceCacheConfiguration::REFERENCE_NAME => ServiceCacheConfiguration::noCache(),
        ]);
        $messagingSystem = ContainerConfig::buildMessagingSystemInMemoryContainer($configuration, $container);

        /** @var Serializer $gateway */
        $gateway = $messagingSystem->getGatewayByName(Serializer::class);

        $this->assertEquals(
            new stdClass(),
            $gateway->convertToPHP('test', MediaType::APPLICATION_X_PHP, stdClass::class)
        );
    }
}
