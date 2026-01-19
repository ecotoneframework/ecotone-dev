<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Obfuscator\MessageObfuscator;
use Ecotone\DataProtection\Obfuscator\Obfuscator;
use Ecotone\DataProtection\OutboundDecryptionChannelBuilder;
use Ecotone\DataProtection\OutboundEncryptionChannelBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\ClassPropertyDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use stdClass;

#[ModuleAnnotation]
final class DataProtectionModule extends NoExternalConfigurationModule
{
    public function __construct(private array $obfuscators)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $obfuscators = [];

        $messagesUsingSensitiveData = $annotationRegistrationService->findAnnotatedClasses(UsingSensitiveData::class);

        foreach ($messagesUsingSensitiveData as $messageUsingSensitiveData) {
            /** @var UsingSensitiveData $attribute */
            $usingSensitiveDataAttribute = $annotationRegistrationService->getAttributeForClass($messageUsingSensitiveData, UsingSensitiveData::class);
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($messageUsingSensitiveData));

            $obfuscators[$messageUsingSensitiveData] = [
                'properties' => $classDefinition->getProperties(),
                'encryptionKey' => $usingSensitiveDataAttribute->encryptionKeyName(),
            ];
        }

        return new self($obfuscators);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        Assert::isTrue(ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects), sprintf('%s was not found.', DataProtectionConfiguration::class));
        Assert::isTrue(ExtensionObjectResolver::contains(JMSConverterConfiguration::class, $extensionObjects), sprintf('%s package require %s package to be enabled. Did you forget to define %s?', ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, JMSConverterConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());
        $jMSConverterConfiguration = ExtensionObjectResolver::resolveUnique(JMSConverterConfiguration::class, $extensionObjects, JMSConverterConfiguration::createWithDefaults());


        $isScalarProperty = static function (ClassPropertyDefinition $property) use ($jMSConverterConfiguration): bool {
            $type = $property->getType();

            return $type->isScalar() || ($type->isEnum() && $jMSConverterConfiguration->isEnumSupportEnabled());
        };

        $obfuscators = array_map(function (array $config) use ($dataProtectionConfiguration, $isScalarProperty): Obfuscator {
            $sensitiveProperties = array_map(
                static fn (ClassPropertyDefinition $property): string => $property->getName(),
                array_filter($config['properties'], static fn (ClassPropertyDefinition $property) => $property->hasAnnotation(Type::create(Sensitive::class)))
            );
            $scalarProperties = array_map(
                static fn (ClassPropertyDefinition $property): string => $property->getName(),
                array_filter($config['properties'], static fn (ClassPropertyDefinition $property) => $isScalarProperty($property))
            );

            return new Obfuscator($sensitiveProperties, $scalarProperties, $dataProtectionConfiguration->key($config['encryptionKey']));
        }, $this->obfuscators);

        $messagingConfiguration->registerServiceDefinition(id: MessageObfuscator::class, definition: new Definition(MessageObfuscator::class, [$obfuscators]));

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundEncryptionChannelBuilder($pollableMessageChannel->getMessageChannelName())
            );
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundDecryptionChannelBuilder($pollableMessageChannel->getMessageChannelName())
            );
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof DataProtectionConfiguration
            || $extensionObject instanceof JMSConverterConfiguration
            || ($extensionObject instanceof MessageChannelWithSerializationBuilder && $extensionObject->isPollable())
        ;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DATA_PROTECTION_PACKAGE;
    }
}
