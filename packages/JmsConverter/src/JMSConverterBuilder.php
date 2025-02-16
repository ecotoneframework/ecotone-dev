<?php

namespace Ecotone\JMSConverter;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Conversion\Converter;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Naming\CamelCaseNamingStrategy;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\SerializerBuilder;

/**
 * licence Apache-2.0
 */
class JMSConverterBuilder implements CompilableBuilder
{
    /**
     * @param JMSHandlerAdapterBuilder[] $converterHandlerBuilders
     */
    public function __construct(private array $converterHandlerBuilders, private JMSConverterConfiguration $jmsConverterConfiguration)
    {
    }

    /**
     * @param JMSHandlerAdapter[] $convertersHandlers
     */
    public static function buildJMSConverter(JMSConverterConfiguration $jmsConverterConfiguration, ServiceCacheConfiguration $serviceCacheConfiguration, array $convertersHandlers): Converter
    {
        $builder = SerializerBuilder::create()
            ->setPropertyNamingStrategy(
                $jmsConverterConfiguration->getNamingStrategy() === JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY
                    ? new IdenticalPropertyNamingStrategy()
                    : new CamelCaseNamingStrategy()
            )
            ->setDocBlockTypeResolver(true)
            ->enableEnumSupport($jmsConverterConfiguration->isEnumSupportEnabled())
            ->addDefaultHandlers()
            ->configureHandlers(function (HandlerRegistry $registry) use ($convertersHandlers) {
                foreach ($convertersHandlers as $converterHandler) {
                    $registry->registerHandler(
                        $converterHandler->getDirection(),
                        $converterHandler->getRelatedClass(),
                        'json',
                        $converterHandler->getSerializerClosure()
                    );
                    $registry->registerHandler(
                        $converterHandler->getDirection(),
                        $converterHandler->getRelatedClass(),
                        'xml',
                        $converterHandler->getSerializerClosure()
                    );
                }
            });

        if ($serviceCacheConfiguration->shouldUseCache()) {
            $builder->setCacheDir($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'jms');
        }


        return new JMSConverter($builder->build(), $jmsConverterConfiguration);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $configuration = new Definition(JMSConverterConfiguration::class, [
            $this->jmsConverterConfiguration->getNamingStrategy(),
            $this->jmsConverterConfiguration->getDefaultNullSerialization(),
            $this->jmsConverterConfiguration->isEnumSupportEnabled(),
        ]);
        $converterHandlers = [];
        foreach ($this->converterHandlerBuilders as $converterHandlerBuilder) {
            $converterHandlers[] = $converterHandlerBuilder->compile($builder);
        }
        return new Definition(JMSConverter::class, [
            $configuration,
            Reference::to(ServiceCacheConfiguration::class),
            $converterHandlers,
        ], [self::class, 'buildJMSConverter']);
    }
}
