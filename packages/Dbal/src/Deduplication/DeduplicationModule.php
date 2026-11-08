<?php

namespace Ecotone\Dbal\Deduplication;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\Database\DeduplicationTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\NullableMessageChannel;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DeduplicationModule implements AnnotationModule
{
    public const REMOVE_MESSAGE_AFTER_7_DAYS = 1000 * 60 * 60 * 24 * 7;

    private function __construct(private AnnotationFinder $annotationFinder)
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self($annotationRegistrationService);
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $this->verifyEnterpriseFeatures($messagingConfiguration);

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());
        $serviceConfiguration = ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults());

        $isDeduplicatedEnabled = $dbalConfiguration->deduplicatedEnabled;
        $hasDeduplicatedHandlers = ! empty($this->annotationFinder->findAnnotatedMethods(Deduplicated::class));
        $connectionFactory     = $dbalConfiguration->getDeduplicationConnectionReference();

        $pointcut = Deduplicated::class;
        if ($isDeduplicatedEnabled) {
            $pointcut .= '||' . AsynchronousRunningEndpoint::class;
        }

        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix(ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults()));

        $messagingConfiguration->registerServiceDefinition(
            DeduplicationTableManager::class,
            new Definition(DeduplicationTableManager::class, [
                DbalHandledMessages::DEFAULT_DEDUPLICATION_TABLE,
                ($isDeduplicatedEnabled || $hasDeduplicatedHandlers) && ! $dbalConfiguration->deduplicationInMemory,
                $consoleInvocationPrefix,
                $connectionFactory,
            ])
        );

        $messagingConfiguration->registerServiceDefinition(
            HandledMessages::class,
            $dbalConfiguration->deduplicationInMemory
                ? new Definition(InMemoryHandledMessages::class)
                : new Definition(DbalHandledMessages::class, [
                    CachedConnectionFactory::registerIn($messagingConfiguration, $connectionFactory, DbalReconnectableConnectionFactory::definitionFor($connectionFactory)),
                    $dbalConfiguration->deduplicationRemovalBatchSize,
                    new Reference(DeduplicationTableManager::class),
                ])
        );

        $messagingConfiguration->registerServiceDefinition(
            DeduplicationInterceptor::class,
            new Definition(
                DeduplicationInterceptor::class,
                [
                    new Reference(HandledMessages::class),
                    new Reference(EcotoneClockInterface::class),
                    $dbalConfiguration->minimumTimeToRemoveMessageInMilliseconds,
                    new Reference(LoggingGateway::class),
                ]
            )
        );

        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    DeduplicationInterceptor::class,
                    $interfaceToCallRegistry->getFor(DeduplicationInterceptor::class, 'deduplicate'),
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE + 100,
                    $pointcut
                )
            )
            ->registerMessageHandler(
                MessageProcessorActivatorBuilder::createForMethod(
                    DeduplicationInterceptor::class,
                    'removeExpiredMessages'
                )->withInputChannelName($inputChannelName = 'ecotone.deduplication.removeExpiredMessages')
            )
            ->registerConsoleCommand(ConsoleCommandConfiguration::create(
                $inputChannelName,
                'ecotone:deduplication:remove-expired-messages',
                [],
                'Removes expired message deduplication entries'
            ));

        if ($isDeduplicatedEnabled) {
            $messagingConfiguration->registerConsumer(
                InboundChannelAdapterBuilder::create(
                    NullableMessageChannel::CHANNEL_NAME,
                    DeduplicationInterceptor::class,
                    $interfaceToCallRegistry->getFor(DeduplicationInterceptor::class, 'removeExpiredMessages'),
                )
                    ->withEndpointId('ecotone.deduplication.cleanup')
                    ->withEndpointAnnotations([new AttributeDefinition(WithoutDatabaseTransaction::class)]),
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());

        $extensions = [
            new DbalTableManagerReference(DeduplicationTableManager::class, $dbalConfiguration->getDeduplicationConnectionReference()),
        ];
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return $extensions;
        }

        $storage = $dbalConfiguration->deduplicationInMemory ? 'in memory' : 'database, connection ' . $dbalConfiguration->getDeduplicationConnectionReference();
        $keptFor = PulseFeature::durationOf($dbalConfiguration->minimumTimeToRemoveMessageInMilliseconds);
        $handlers = [];
        foreach ($this->annotationFinder->findAnnotatedMethods(Deduplicated::class) as $annotatedMethod) {
            $deduplicated = $annotatedMethod->getAnnotationForMethod();
            $handlers[] = PulseFeature::handlerOf($annotatedMethod) . match (true) {
                $deduplicated->hasExpression() => ' (by expression)',
                $deduplicated->deduplicationHeaderName !== '' => ' (by header ' . $deduplicated->deduplicationHeaderName . ')',
                default => ' (by message id)',
            };
        }

        return [...$extensions, new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Deduplication',
            enabled: $dbalConfiguration->deduplicatedEnabled,
            settings: ['Storage' => $storage, 'Expiration time' => $keptFor, 'Removal batch size' => (string) $dbalConfiguration->deduplicationRemovalBatchSize],
            handlers: $handlers,
            hint: $dbalConfiguration->deduplicatedEnabled
                ? sprintf('A message an asynchronous endpoint has already handled that arrives again with the same message id, such as a broker redelivery or a resent outbox message, is skipped instead of being handled twice. Handled message ids are kept (%s) for %s, then removed. A handler with #[Deduplicated] is deduplicated too, by the header or expression it names, whichever way it is called.', $storage, $keptFor)
                : 'Asynchronous endpoints do not check whether a message was handled before, so a redelivered message is handled again. A handler with #[Deduplicated] is still deduplicated, by the header or expression it names.',
            configuration: 'DbalConfiguration::createWithDefaults()->withDeduplication(isDeduplicatedEnabled: true)',
        )];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    private function verifyEnterpriseFeatures(Configuration $messagingConfiguration): void
    {
        if ($messagingConfiguration->isRunningForEnterpriseLicence()) {
            return;
        }

        // Check for Deduplicated attribute on interfaces/gateways (class-level usage)
        $deduplicatedClasses = $this->annotationFinder->findAnnotatedClasses(Deduplicated::class);

        if (! empty($deduplicatedClasses)) {
            $classNames = implode(', ', $deduplicatedClasses);
            throw LicensingException::create("Deduplicated attribute on interfaces/gateways ({$classNames}) is available only with Ecotone Enterprise licence. This functionality requires enterprise mode to ensure proper gateway-level deduplication. See https://docs.ecotone.tech/enterprise for details.");
        }
    }
}
