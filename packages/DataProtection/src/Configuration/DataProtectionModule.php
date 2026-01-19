<?php

/**
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\DataProtection\Configuration;

use Defuse\Crypto\Key;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\DataProtection\Attribute\UsingSensitiveData;
use Ecotone\DataProtection\Attribute\WithSensitiveHeader;
use Ecotone\DataProtection\Attribute\WithSensitiveHeaders;
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
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
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
            $classDefinition = $interfaceToCallRegistry->getClassDefinitionFor(Type::create($messageUsingSensitiveData));
            $usingSensitiveDataAttribute = $classDefinition->getSingleClassAnnotation(Type::create(UsingSensitiveData::class));

            $sensitiveHeaders = $classDefinition->findSingleClassAnnotation(Type::create(WithSensitiveHeaders::class))?->headers ?? [];
            foreach ($classDefinition->getClassAnnotations(Type::create(WithSensitiveHeader::class)) as $sensitiveHeader) {
                $sensitiveHeaders[] = $sensitiveHeader->header;
            }

            $obfuscators[$messageUsingSensitiveData] = [
                'encryptionKey' => $usingSensitiveDataAttribute->encryptionKeyName(),
                'sensitiveHeaders' => $sensitiveHeaders,
            ];
        }

        return new self($obfuscators);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        Assert::isTrue(ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects), sprintf('%s was not found.', DataProtectionConfiguration::class));
        Assert::isTrue(ExtensionObjectResolver::contains(JMSConverterConfiguration::class, $extensionObjects), sprintf('%s package require %s package to be enabled. Did you forget to define %s?', ModulePackageList::DATA_PROTECTION_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE, JMSConverterConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());

        foreach ($dataProtectionConfiguration->keys() as $encryptionKeyName => $key) {
            $messagingConfiguration->registerServiceDefinition(
                id: sprintf('ecotone.encryption.key.%s', $encryptionKeyName),
                definition: new Definition(
                    Key::class,
                    [$key->saveToAsciiSafeString()],
                    'loadFromAsciiSafeString'
                )
            );
        }

        $messageObfuscatorDefinition = new Definition(MessageObfuscator::class);

        foreach ($this->obfuscators as $messageClass => $config) {
            $messageObfuscatorDefinition->addMethodCall('withKey', [$messageClass, Reference::to(sprintf('ecotone.encryption.key.%s', $dataProtectionConfiguration->keyName($config['encryptionKey'])))]);
            $messageObfuscatorDefinition->addMethodCall('withSensitiveHeaders', [$messageClass, $config['sensitiveHeaders']]);
        }

        $messagingConfiguration->registerServiceDefinition(id: MessageObfuscator::class, definition: $messageObfuscatorDefinition);

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
