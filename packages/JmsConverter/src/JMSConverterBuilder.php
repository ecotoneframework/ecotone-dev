<?php

namespace Ecotone\JMSConverter;

use DateTimeInterface;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Conversion\Converter;
use JMS\Serializer\Handler\DateHandler;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Naming\CamelCaseNamingStrategy;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\SerializerBuilder;

/**
 * licence Apache-2.0
 */
class JMSConverterBuilder implements CompilableBuilder
{
    public const DATE_TIME_FORMAT_WITH_MICROSECONDS = 'Y-m-d\\TH:i:s.uP';

    /**
     * @param JMSHandlerAdapterBuilder[] $converterHandlerBuilders
     */
    public function __construct(private array $converterHandlerBuilders, private JMSConverterConfiguration $jmsConverterConfiguration, private bool $checkMetadataCacheFreshness)
    {
    }

    /**
     * @param JMSHandlerAdapter[] $convertersHandlers
     */
    public static function buildJMSConverter(JMSConverterConfiguration $jmsConverterConfiguration, ServiceCacheConfiguration $serviceCacheConfiguration, array $convertersHandlers, bool $checkMetadataCacheFreshness): Converter
    {
        $builder = SerializerBuilder::create()
            ->setPropertyNamingStrategy(
                $jmsConverterConfiguration->namingStrategy === JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY
                    ? new IdenticalPropertyNamingStrategy()
                    : new CamelCaseNamingStrategy()
            )
            ->setDocBlockTypeResolver(true)
            ->enableEnumSupport($jmsConverterConfiguration->enableEnumSupport)
            ->addDefaultHandlers()
            ->configureHandlers(function (HandlerRegistry $registry) use ($convertersHandlers) {
                $registry->registerSubscribingHandler(new DateHandler(self::DATE_TIME_FORMAT_WITH_MICROSECONDS, 'UTC', true, [self::DATE_TIME_FORMAT_WITH_MICROSECONDS, DateTimeInterface::ATOM]));
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
            $builder
                ->setCacheDir($serviceCacheConfiguration->getPath() . DIRECTORY_SEPARATOR . 'jms')
                ->setDebug($checkMetadataCacheFreshness);
        }

        return new JMSConverter($builder->build(), $jmsConverterConfiguration);
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        $configuration = new Definition(JMSConverterConfiguration::class, [
            'namingStrategy' => $this->jmsConverterConfiguration->namingStrategy,
            'defaultNullSerialization' => $this->jmsConverterConfiguration->defaultNullSerialization,
            'enableEnumSupport' => $this->jmsConverterConfiguration->enableEnumSupport,
            'metadataCacheFreshnessCheck' => $this->jmsConverterConfiguration->metadataCacheFreshnessCheck,
        ]);
        $converterHandlers = [];
        foreach ($this->converterHandlerBuilders as $converterHandlerBuilder) {
            $converterHandlers[] = $converterHandlerBuilder->compile($builder);
        }
        return new Definition(JMSConverter::class, [
            $configuration,
            Reference::to(ServiceCacheConfiguration::class),
            $converterHandlers,
            $this->checkMetadataCacheFreshness,
        ], [self::class, 'buildJMSConverter']);
    }
}
