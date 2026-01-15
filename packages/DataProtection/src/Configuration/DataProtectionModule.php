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
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\MessageChannelWithSerializationBuilder;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
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
            /** @var UsingSensitiveData $attribute */
            $usingSensitiveDataAttribute = $annotationRegistrationService->getAttributeForClass($messageUsingSensitiveData, UsingSensitiveData::class);

            $reflectionClass = new \ReflectionClass($messageUsingSensitiveData);
            $sensitiveProperties = array_filter($reflectionClass->getProperties(), fn(\ReflectionProperty $property) => $property->getAttributes(Sensitive::class) !== []);
            $scalarProperties = array_filter($reflectionClass->getProperties(), fn(\ReflectionProperty $property) => Type::create($property->getType()->getName())->isScalar());

            $obfuscators[$messageUsingSensitiveData] = [
                'sensitive' => array_map(fn(\ReflectionProperty $property) => $property->getName(), $sensitiveProperties),
                'scalar' => array_map(fn(\ReflectionProperty $property) => $property->getName(), $scalarProperties),
                'encryptionKey' => $usingSensitiveDataAttribute->encryptionKeyName(),
            ];
        }

        return new self($obfuscators);
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        Assert::isTrue(ExtensionObjectResolver::contains(DataProtectionConfiguration::class, $extensionObjects), sprintf('%s was not found.', DataProtectionConfiguration::class));

        $dataProtectionConfiguration = ExtensionObjectResolver::resolveUnique(DataProtectionConfiguration::class, $extensionObjects, new stdClass());

        $obfuscators = array_map(static fn (array $config) => new Obfuscator($config['sensitive'], $config['scalar'], $dataProtectionConfiguration->key($config['encryptionKey'])), $this->obfuscators);
        $messagingConfiguration->registerServiceDefinition(id: MessageObfuscator::class, definition: new Definition(MessageObfuscator::class, [$obfuscators]));

        $pollableMessageChannels = ExtensionObjectResolver::resolve(MessageChannelWithSerializationBuilder::class, $extensionObjects);

        foreach ($pollableMessageChannels as $pollableMessageChannel) {
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
        return $extensionObject instanceof DataProtectionConfiguration || ($extensionObject instanceof MessageChannelWithSerializationBuilder && $extensionObject->isPollable());
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DATA_PROTECTION_PACKAGE;
    }
}
