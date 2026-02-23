<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Conversion\DataProtectionConversionServiceDecorator;
use Ecotone\DataProtection\Conversion\JsonDecryptionConverter;
use Ecotone\DataProtection\Conversion\JsonEncryptionConverter;
use Ecotone\DataProtection\Conversion\XPhpDecryptionConverter;
use Ecotone\DataProtection\Conversion\XPhpEncryptionConverter;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\DataProtection\Channel\OutboundDecryptionChannelBuilder;
use Ecotone\DataProtection\Channel\OutboundEncryptionChannelBuilder;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Handler\ClassPropertyDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\LicensingException;
use stdClass;

#[ModuleAnnotation]
final class DataProtectionModule extends NoExternalConfigurationModule
{
    final public const KEY_SERVICE_ID_FORMAT = 'ecotone.encryption.key.%s';

    /**
     * @param array<DataProtectorConfig> $dataProtectorConfigs
     */
    public function __construct(private array $dataProtectorConfigs)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            dataProtectorConfigs: self::resolveProtectorConfigsFromAnnotatedClasses($annotationRegistrationService->findAnnotatedClasses(Sensitive::class), $interfaceToCallRegistry)
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        if (! ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects)) {
            return;
        }

        $this->verifyLicense($messagingConfiguration);

        Assert::isTrue(ExtensionObjectResolver::contains(JMSConverterConfiguration::class, $extensionObjects), sprintf('%s package require %s package to be enabled. Did you forget to define %s?', ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, JMSConverterConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());
        $channelProtectionConfigurations = ExtensionObjectResolver::resolve(ChannelProtectionConfiguration::class, $extensionObjects);

        foreach ($dataProtectionConfiguration->keys() as $encryptionKeyName => $key) {
            $messagingConfiguration->registerServiceDefinition(
                id: sprintf(self::KEY_SERVICE_ID_FORMAT, $encryptionKeyName),
                definition: new Definition(
                    Key::class,
                    [$key->saveToAsciiSafeString()],
                    'loadFromAsciiSafeString'
                )
            );
        }

        $channelEncryption = $channelDecryption = [];
        foreach ($channelProtectionConfigurations as $channelProtectionConfiguration) {
            $channelName = $channelProtectionConfiguration->channelName;

            Assert::isTrue($messagingConfiguration->isPollableChannel($channelName), sprintf('`%s` channel must be pollable channel to use Data Protection.', $channelProtectionConfiguration->channelName));

            $encryptionKey = Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $dataProtectionConfiguration->keyName($channelProtectionConfiguration->encryptionKey)));

            $channelEncryption[$channelName] = new OutboundEncryptionChannelBuilder(
                relatedChannel: $channelName,
                encryptionKey: $encryptionKey,
                isPayloadSensitive: $channelProtectionConfiguration->isPayloadSensitive,
                sensitiveHeaders: $channelProtectionConfiguration->sensitiveHeaders,
            );

            $channelDecryption[$channelName] = new OutboundDecryptionChannelBuilder(
                relatedChannel: $channelName,
                encryptionKey: $encryptionKey,
                isPayloadSensitive: $channelProtectionConfiguration->isPayloadSensitive,
                sensitiveHeaders: $channelProtectionConfiguration->sensitiveHeaders,
            );
        }

        $converters = [];
        $encryptionConverters = [JsonEncryptionConverter::class, JsonDecryptionConverter::class, XPhpEncryptionConverter::class, XPhpDecryptionConverter::class];
        foreach ($this->dataProtectorConfigs as $protectorConfig) {
            foreach ($encryptionConverters as $converterClass) {
                $converters[] = new Definition(
                    $converterClass,
                    [
                        $protectorConfig->supportedType,
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $protectorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $protectorConfig->sensitiveProperties,
                        $protectorConfig->scalarProperties,
                    ]
                );
            }
        }

        $messagingConfiguration->registerServiceDefinition('data-protection.conversion-service', new Definition(AutoCollectionConversionService::class, ['converters' => $converters]));
        $conversionServiceDecorator = new Definition(DataProtectionConversionServiceDecorator::class, [Reference::to('data-protection.conversion-service')]);
        $messagingConfiguration->registerConversionServiceDecorator($conversionServiceDecorator);

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            if (! $pollableMessageChannel->isPollable()) {
                continue;
            }

            if (array_key_exists($pollableMessageChannel->getMessageChannelName(), $channelEncryption)) {
                $messagingConfiguration->registerChannelInterceptor($channelEncryption[$pollableMessageChannel->getMessageChannelName()]);
            }

            if (array_key_exists($pollableMessageChannel->getMessageChannelName(), $channelDecryption)) {
                $messagingConfiguration->registerChannelInterceptor($channelDecryption[$pollableMessageChannel->getMessageChannelName()]);
            }
        }
    }

    public function canHandle($extensionObject): bool
    {
        return
            $extensionObject instanceof DataProtectionConfiguration
            || $extensionObject instanceof ChannelProtectionConfiguration
            || $extensionObject instanceof JMSConverterConfiguration
            || ($extensionObject instanceof MessageChannelWithSerializationBuilder && $extensionObject->isPollable())
        ;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DATA_PROTECTION_PACKAGE;
    }

    private static function resolveProtectorConfigsFromAnnotatedClasses(array $sensitiveMessages, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $dataEncryptorConfigs = [];
        foreach ($sensitiveMessages as $message) {
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor($messageType = Type::create($message));
            $encryptionKey = $classDefinition->findSingleClassAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();

            $sensitiveProperties = $classDefinition->getPropertiesWithAnnotation(Type::create(Sensitive::class));
            if ($sensitiveProperties === []) {
                $sensitiveProperties = $classDefinition->getProperties();
            }

            $scalarProperties = array_values(array_filter($sensitiveProperties, static fn (ClassPropertyDefinition $property): bool => $property->getType()->isScalar()));

            $mapper = static fn (ClassPropertyDefinition $property): string => $property->getName();

            $sensitiveProperties = array_map($mapper, $sensitiveProperties);
            $scalarProperties = array_map($mapper, $scalarProperties);

            $dataEncryptorConfigs[$message] = new DataProtectorConfig(supportedType: $messageType, encryptionKey: $encryptionKey, sensitiveProperties: $sensitiveProperties, scalarProperties: $scalarProperties);
        }

        return $dataEncryptorConfigs;
    }

    private function verifyLicense(Configuration $messagingConfiguration): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        throw LicensingException::create('Data Protection module is available only with Ecotone Enterprise Licence.');
    }
}
