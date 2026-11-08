<?php

declare(strict_types=1);

namespace Ecotone\JMSConverter\Configuration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class JMSConverterPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $configuration = ExtensionObjectResolver::resolve(JMSConverterConfiguration::class, $serviceExtensions)[0] ?? JMSConverterConfiguration::createWithDefaults();

        return [new PulseFeature(
            package: ModulePackageList::JMS_CONVERTER_PACKAGE,
            name: 'JMS serialization',
            enabled: true,
            settings: [
                'Naming strategy' => $configuration->namingStrategy,
                'Null values' => $configuration->defaultNullSerialization ? 'serialized' : 'left out',
                'Enums' => PulseFeature::onOff($configuration->enableEnumSupport),
            ],
            handlers: [],
            hint: sprintf(
                'JMS Serializer converts objects to and from JSON, which it makes the default serialization media type unless ServiceConfiguration::withDefaultSerializationMediaType names another: property names are kept %s, null values are %s and enums are %s.',
                $configuration->namingStrategy === JMSConverterConfiguration::IDENTICAL_PROPERTY_NAMING_STRATEGY ? 'as they are' : 'converted to ' . $configuration->namingStrategy,
                $configuration->defaultNullSerialization ? 'serialized' : 'left out',
                $configuration->enableEnumSupport ? 'converted to their values' : 'not converted',
            ),
            configuration: 'JMSConverterConfiguration::createWithDefaults()->withNamingStrategy(JMSConverterConfiguration::CAMEL_CASE_PROPERTY_NAMING_STRATEGY)->withDefaultNullSerialization(false)',
        )];
    }
}
