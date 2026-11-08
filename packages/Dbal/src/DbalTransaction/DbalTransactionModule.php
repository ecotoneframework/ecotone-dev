<?php

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\Pulse\PulseSavepointIsolation;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Modelling\Config\DatabaseTransaction\CommandsWithoutDatabaseTransaction;
use Ecotone\Modelling\Config\DatabaseTransaction\DatabaseTransactionRegistration;
use Ecotone\Modelling\Config\DatabaseTransaction\TransactionStatusTracker;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSectionIsolationRegistration;
use Ecotone\Projecting\Config\ProjectingConsoleCommands;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class DbalTransactionModule implements AnnotationModule
{
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
        $pointcut            = '(' . DbalTransaction::class . ')';

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        if ($dbalConfiguration->transactionOnAsynchronousEndpoints) {
            $pointcut .= '||(' . AsynchronousRunningEndpoint::class . ')';
        }
        if ($dbalConfiguration->transactionOnCommandBus) {
            $pointcut .= '||(' . CommandBus::class . ')';
        }
        if ($dbalConfiguration->transactionOnConsoleCommands) {
            $pointcut .= '||(' . ConsoleCommand::class . ')';
        }
        foreach (['initProjection', 'deleteProjection', 'backfillProjection'] as $projectionCommandWithoutTransaction) {
            $pointcut .= '&&not(' . ProjectingConsoleCommands::class . '::' . $projectionCommandWithoutTransaction . ')';
        }
        $connectionFactories = $dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT];
        $this->refuseDbalTransactionOnUnconfiguredConnection($connectionFactories);

        $cachedConnectionFactories = array_map(
            fn (string $id) => CachedConnectionFactory::registerIn($messagingConfiguration, $id, DbalReconnectableConnectionFactory::definitionFor($id)),
            $connectionFactories,
        );

        $messagingConfiguration->registerServiceDefinition(PulseSavepointIsolation::class, [
            $cachedConnectionFactories,
        ]);

        $messagingConfiguration->registerServiceDefinition(DbalTransactionInterceptor::class, [
            array_combine($connectionFactories, $cachedConnectionFactories),
            $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames,
            new Reference(RetryRunner::class),
            new Reference(LoggingGateway::class),
            new Reference(TransactionStatusTracker::class),
            new Reference(CommandsWithoutDatabaseTransaction::class),
            $dbalConfiguration->transactionReconnectProtection,
        ]);

        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    DbalTransactionInterceptor::class,
                    $interfaceToCallRegistry->getFor(DbalTransactionInterceptor::class, 'transactional'),
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE,
                    $pointcut
                )
            );
    }

    /**
     * @param string[] $configuredConnectionReferenceNames
     */
    private function refuseDbalTransactionOnUnconfiguredConnection(array $configuredConnectionReferenceNames): void
    {
        $placements = [];
        foreach ($this->annotationFinder->findAnnotatedMethods(DbalTransaction::class) as $annotatedMethod) {
            $placements[$annotatedMethod->getClassName() . '::' . $annotatedMethod->getMethodName() . '()'] = $annotatedMethod->getAnnotationForMethod();
        }
        foreach ($this->annotationFinder->findAnnotatedClasses(DbalTransaction::class) as $className) {
            $placements[$className] = $this->annotationFinder->getAttributeForClass($className, DbalTransaction::class);
        }

        foreach ($placements as $placement => $dbalTransaction) {
            foreach ($dbalTransaction->connectionReferenceNames as $connectionReferenceName) {
                if (in_array($connectionReferenceName, $configuredConnectionReferenceNames, true)) {
                    continue;
                }

                throw ConfigurationException::create(
                    "#[DbalTransaction] on {$placement} names the connection \"{$connectionReferenceName}\", "
                    . 'but Ecotone opens transactions only on the connections in DbalConfiguration::withDefaultConnectionReferenceNames(): "'
                    . implode('", "', $configuredConnectionReferenceNames) . "\". Either add \"{$connectionReferenceName}\" to DbalConfiguration::withDefaultConnectionReferenceNames([...]) "
                    . 'in a #[ServiceContext] method, or name one of the configured connections in #[DbalTransaction(connectionReferenceNames: [...])].'
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());
        $opensTransactions = $dbalConfiguration->transactionOnAsynchronousEndpoints || $dbalConfiguration->transactionOnCommandBus || $dbalConfiguration->transactionOnConsoleCommands;

        $databaseTransactions = new DatabaseTransactionRegistration(
            onCommandBus: $dbalConfiguration->transactionOnCommandBus,
            onAsynchronousEndpoints: $dbalConfiguration->transactionOnAsynchronousEndpoints,
            asynchronousEndpointsWithoutTransaction: $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames,
        );
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [$databaseTransactions];
        }

        return [$databaseTransactions, ...($opensTransactions ? [new PulseSectionIsolationRegistration(PulseSavepointIsolation::class)] : []), $this->transactionsFeature($dbalConfiguration)];
    }

    private function transactionsFeature(DbalConfiguration $dbalConfiguration): PulseFeature
    {
        $connections = implode(', ', $dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT]);
        $excluded = $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames;
        $handlersWithout = [];
        if ($dbalConfiguration->transactionOnCommandBus) {
            foreach ($this->annotationFinder->findAnnotatedMethods(WithoutDatabaseTransaction::class) as $annotatedMethod) {
                if ($annotatedMethod->hasMethodAnnotation(CommandHandler::class)) {
                    $handlersWithout[] = PulseFeature::handlerOf($annotatedMethod);
                }
            }
        }
        if ($dbalConfiguration->transactionOnAsynchronousEndpoints) {
            foreach ($this->annotationFinder->findAnnotatedMethods(Asynchronous::class) as $annotatedMethod) {
                foreach ($annotatedMethod->getMethodAnnotationsWithType(Asynchronous::class) as $asynchronous) {
                    if (array_filter($asynchronous->asynchronousExecution, static fn (object $attribute): bool => $attribute instanceof WithoutDatabaseTransaction) !== []) {
                        $handlersWithout[] = PulseFeature::handlerOf($annotatedMethod);
                    }
                }
            }
        }
        $entryPoints = array_filter([
            'command sent through the command bus' => $dbalConfiguration->transactionOnCommandBus,
            'message an asynchronous endpoint consumes' => $dbalConfiguration->transactionOnAsynchronousEndpoints,
            'console command' => $dbalConfiguration->transactionOnConsoleCommands,
        ]);
        $hint = $entryPoints === []
            ? 'No entry point opens a database transaction, so every handler writes on its own: a failure halfway through leaves what was already written.'
            : sprintf('Each %s runs inside one database transaction on %s: what its handler writes, and the messages it sends to database channels, are committed together or rolled back together, so a failure leaves no half-done work.', implode(', each ', array_keys($entryPoints)), $connections);
        $hint .= $handlersWithout === [] ? '' : ' The handlers listed here have #[WithoutDatabaseTransaction] and run without one.';
        $hint .= $dbalConfiguration->transactionOnCommandBus ? '' : ' A command sent through the command bus does not open a transaction.';
        $hint .= $dbalConfiguration->transactionOnAsynchronousEndpoints ? ($excluded === [] ? '' : ' Endpoints ' . implode(', ', $excluded) . ' are excluded with withoutTransactionOnAsynchronousEndpoints and run without one.') : ' A message an asynchronous endpoint consumes does not open a transaction.';
        $hint .= $dbalConfiguration->transactionOnConsoleCommands ? '' : ' A console command does not open a transaction.';

        return new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Database transactions',
            enabled: $entryPoints !== [],
            settings: [
                'Command bus' => PulseFeature::onOff($dbalConfiguration->transactionOnCommandBus),
                'Asynchronous endpoints' => $dbalConfiguration->transactionOnAsynchronousEndpoints && $excluded !== [] ? 'on, except ' . implode(', ', $excluded) : PulseFeature::onOff($dbalConfiguration->transactionOnAsynchronousEndpoints),
                'Console commands' => PulseFeature::onOff($dbalConfiguration->transactionOnConsoleCommands),
                'Connections' => $connections,
            ],
            handlers: $handlersWithout,
            hint: $hint,
            configuration: 'DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)',
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
