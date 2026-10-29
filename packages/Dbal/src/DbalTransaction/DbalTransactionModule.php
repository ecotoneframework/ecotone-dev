<?php

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Dbal\Attribute\DbalTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Dbal\Pulse\PulseSavepointIsolation;
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

        $messagingConfiguration->registerServiceDefinition(PulseSavepointIsolation::class, [
            array_map(fn (string $id) => new Reference($id), $connectionFactories),
            new Reference(EcotoneClockInterface::class),
        ]);

        $messagingConfiguration->registerServiceDefinition(DbalTransactionInterceptor::class, [
            array_combine($connectionFactories, array_map(fn (string $id) => new Reference($id), $connectionFactories)),
            $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames,
            new Reference(RetryRunner::class),
            new Reference(LoggingGateway::class),
            new Reference(TransactionStatusTracker::class),
            new Reference(EcotoneClockInterface::class),
            new Reference(CommandsWithoutDatabaseTransaction::class),
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

        return $opensTransactions && ExtensionObjectResolver::resolve(PulseConfiguration::class, $serviceExtensions) !== []
            ? [$databaseTransactions, new PulseSectionIsolationRegistration(PulseSavepointIsolation::class)]
            : [$databaseTransactions];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
