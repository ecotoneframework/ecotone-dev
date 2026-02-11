<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Ecotone\AnnotationFinder\AnnotatedMethod;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\Sensitive;
use Ecotone\DataProtection\Attribute\WithEncryptionKey;
use Ecotone\DataProtection\Encryption\Key;
use Ecotone\DataProtection\OutboundDecryptionChannelBuilder;
use Ecotone\DataProtection\OutboundEncryptionChannelBuilder;
use Ecotone\DataProtection\Protector\ChannelProtector;
use Ecotone\DataProtection\Protector\DataDecryptor;
use Ecotone\DataProtection\Protector\DataEncryptor;
use Ecotone\DataProtection\Protector\DataProtectorConfig;
use Ecotone\JMSConverter\JMSConverterConfiguration;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\ClassPropertyDefinition;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use stdClass;

#[ModuleAnnotation]
final class DataProtectionModule extends NoExternalConfigurationModule
{
    final public const ENCRYPTOR_SERVICE_ID_FORMAT = 'ecotone.data-protection.encryptor.%s';
    final public const KEY_SERVICE_ID_FORMAT = 'ecotone.encryption.key.%s';

    /**
     * @param array<DataProtectorConfig> $dataProtectorConfigs
     */
    public function __construct(private array $dataProtectorConfigs)
    {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        $dataProtectorConfigs = self::resolveProtectorConfigsFromAnnotatedClasses($annotationRegistrationService->findAnnotatedClasses(Sensitive::class), $interfaceToCallRegistry);
        $dataProtectorConfigs = self::resolveProtectorConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(CommandHandler::class), $dataProtectorConfigs, $interfaceToCallRegistry);
        $dataProtectorConfigs = self::resolveProtectorConfigsFromAnnotatedMethods($annotationRegistrationService->findAnnotatedMethods(EventHandler::class), $dataProtectorConfigs, $interfaceToCallRegistry);

        return new self($dataProtectorConfigs);
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

        $channelProtectorReferences = $messageEncryptorReferences = [];
        foreach ($channelProtectionConfigurations as $channelProtectionConfiguration) {
            Assert::isTrue($messagingConfiguration->isPollableChannel($channelProtectionConfiguration->channelName()), sprintf('`%s` channel must be pollable channel to use Data Protection.', $channelProtectionConfiguration->channelName()));

            $messagingConfiguration->registerServiceDefinition(
                id: $id = sprintf(self::ENCRYPTOR_SERVICE_ID_FORMAT, $channelProtectionConfiguration->channelName),
                definition: new Definition(
                    ChannelProtector::class,
                    [
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $dataProtectionConfiguration->keyName($channelProtectionConfiguration->encryptionKey))),
                        $channelProtectionConfiguration->isPayloadSensitive,
                        $channelProtectionConfiguration->sensitiveHeaders,
                    ],
                )
            );

            $channelProtectorReferences[$channelProtectionConfiguration->channelName()] = Reference::to($id);
        }

        foreach ($this->dataProtectorConfigs as $protectorConfig) {
            $messagingConfiguration->registerDataProtector(
                new Definition(
                    DataEncryptor::class,
                    [
                        $protectorConfig->supportedType,
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $protectorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $protectorConfig->sensitiveProperties,
                        $protectorConfig->scalarProperties,
                    ],
                )
            );
            $messagingConfiguration->registerDataProtector(
                new Definition(
                    DataDecryptor::class,
                    [
                        $protectorConfig->supportedType,
                        Reference::to(sprintf(self::KEY_SERVICE_ID_FORMAT, $protectorConfig->encryptionKeyName($dataProtectionConfiguration))),
                        $protectorConfig->sensitiveProperties,
                        $protectorConfig->scalarProperties,
                    ],
                )
            );
        }

        foreach (ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects) as $pollableMessageChannel) {
            if (! $pollableMessageChannel->isPollable()) {
                continue;
            }

            $messagingConfiguration->registerChannelInterceptor(
                new OutboundEncryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelEncryptorReference: $channelProtectorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageEncryptorReferences: $messageEncryptorReferences,
                )
            );
            $messagingConfiguration->registerChannelInterceptor(
                new OutboundDecryptionChannelBuilder(
                    relatedChannel: $pollableMessageChannel->getMessageChannelName(),
                    channelEncryptionReference: $channelProtectorReferences[$pollableMessageChannel->getMessageChannelName()] ?? null,
                    messageEncryptionReferences: $messageEncryptorReferences,
                )
            );
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
                $sensitiveProperties = array_map(static fn (ClassPropertyDefinition $property): string => $property->getName(), $classDefinition->getProperties());
            }

            $scalarProperties = array_values(array_filter($sensitiveProperties, static fn (string $propertyName): bool => $classDefinition->getProperty($propertyName)->getType()->isScalar()));

            $dataEncryptorConfigs[$message] = new DataProtectorConfig(supportedType: $messageType, encryptionKey: $encryptionKey, sensitiveProperties: $sensitiveProperties, scalarProperties: $scalarProperties);
        }

        return $dataEncryptorConfigs;
    }

    private static function resolveProtectorConfigsFromAnnotatedMethods(array $annotatedMethods, array $encryptionConfigs, InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        /** @var AnnotatedMethod $method */
        foreach ($annotatedMethods as $method) {
            $methodDefinition = $interfaceToCallRegistry->getFor($method->getClassName(), $method->getMethodName());
            $payload = $methodDefinition->getFirstParameter();

            if (
                $payload->hasAnnotation(Header::class)
                || $payload->hasAnnotation(Headers::class)
                || $payload->hasAnnotation(Reference::class)
                || array_key_exists($payload->getTypeHint(), $encryptionConfigs)
                || ! $payload->hasAnnotation(Sensitive::class)
            ) {
                continue;
            }

            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor($payload->getTypeDescriptor());
            $encryptionKey = $payload->findSingleAnnotation(Type::create(WithEncryptionKey::class))?->encryptionKey();
            $sensitiveProperties = array_map(static fn (ClassPropertyDefinition $property): string => $property->getName(), $classDefinition->getProperties());
            $scalarProperties = array_values(array_filter($sensitiveProperties, static fn (string $propertyName): bool => $classDefinition->getProperty($propertyName)->getType()->isScalar()));

            $encryptionConfigs[$payload->getTypeHint()] = new DataProtectorConfig(supportedType: $payload->getTypeDescriptor(), encryptionKey: $encryptionKey, sensitiveProperties: $sensitiveProperties, scalarProperties: $scalarProperties);
        }

        return $encryptionConfigs;
    }

    private function verifyLicense(Configuration $messagingConfiguration): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        throw LicensingException::create('Data Protection module is available only with Ecotone Enterprise Licence.');
    }
}
