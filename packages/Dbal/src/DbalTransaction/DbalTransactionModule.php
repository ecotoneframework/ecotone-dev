<?php

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Dbal\Pulse\PulseSavepointIsolation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
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

        [$commandRoutingKeysWithoutTransaction, $commandClassesWithoutTransaction] = $dbalConfiguration->transactionOnCommandBus
            ? $this->findCommandHandlersWithoutTransaction($interfaceToCallRegistry)
            : [[], []];

        $messagingConfiguration->registerServiceDefinition(PulseSavepointIsolation::class, [
            array_map(fn (string $id) => new Reference($id), $connectionFactories),
            new Reference(EcotoneClockInterface::class),
        ]);

        $messagingConfiguration->registerServiceDefinition(DbalTransactionInterceptor::class, [
            array_map(fn (string $id) => new Reference($id), $connectionFactories),
            $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames,
            new Reference(RetryRunner::class),
            new Reference(LoggingGateway::class),
            new Reference(TransactionStatusTracker::class),
            new Reference(EcotoneClockInterface::class),
            $commandRoutingKeysWithoutTransaction,
            $commandClassesWithoutTransaction,
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
     * @return array{0: string[], 1: string[]}
     */
    private function findCommandHandlersWithoutTransaction(InterfaceToCallRegistry $interfaceToCallRegistry): array
    {
        $routingKeys = [];
        $commandClasses = [];
        foreach ($this->annotationFinder->findAnnotatedMethods(WithoutDatabaseTransaction::class) as $annotatedMethod) {
            if (! $annotatedMethod->hasMethodAnnotation(CommandHandler::class)) {
                continue;
            }

            foreach ($annotatedMethod->getMethodAnnotationsWithType(CommandHandler::class) as $commandHandler) {
                if ($commandHandler->getInputChannelName() !== '') {
                    $routingKeys[] = $commandHandler->getInputChannelName();
                    continue;
                }

                $interfaceToCall = $interfaceToCallRegistry->getFor($annotatedMethod->getClassName(), $annotatedMethod->getMethodName());
                if ($interfaceToCall->hasNoParameters()) {
                    continue;
                }

                foreach ($interfaceToCall->getFirstParameter()->getTypeDescriptor()->getUnionTypes() as $unionType) {
                    $commandClasses[] = (string) $unionType;
                }
            }
        }

        return [$routingKeys, $commandClasses];
    }

    /**
     * @inheritDoc
     */
    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $serviceExtensions, DbalConfiguration::createWithDefaults());
        $opensTransactions = $dbalConfiguration->transactionOnAsynchronousEndpoints || $dbalConfiguration->transactionOnCommandBus || $dbalConfiguration->transactionOnConsoleCommands;

        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        return [...($opensTransactions ? [new PulseSectionIsolationRegistration(PulseSavepointIsolation::class)] : []), $this->transactionsFeature($dbalConfiguration)];
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
        $entryPoints = array_filter([
            'command sent through the command bus' => $dbalConfiguration->transactionOnCommandBus,
            'message an asynchronous endpoint consumes' => $dbalConfiguration->transactionOnAsynchronousEndpoints,
            'console command' => $dbalConfiguration->transactionOnConsoleCommands,
        ]);
        $hint = $entryPoints === []
            ? 'No entry point opens a database transaction, so every handler writes on its own: a failure halfway through leaves what was already written.'
            : sprintf('Each %s runs inside one database transaction on %s: what its handler writes, and the messages it sends to database channels, are committed together or rolled back together, so a failure leaves no half-done work.', implode(', each ', array_keys($entryPoints)), $connections);
        $hint .= $dbalConfiguration->transactionOnCommandBus ? ' A command handler with #[WithoutDatabaseTransaction] runs without one.' : ' A command sent through the command bus does not open a transaction.';
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
