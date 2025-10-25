<?php

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Dbal\Configuration\DbalConfiguration;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\ConsoleCommand;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\CommandBus;
use Ecotone\Projecting\Config\ProjectingConsoleCommands;
use Enqueue\Dbal\DbalConnectionFactory;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class DbalTransactionModule implements AnnotationModule
{
    private function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    /**
     * @inheritDoc
     */
    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $pointcut            = '(' . DbalTransaction::class . ')';

        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        if ($dbalConfiguration->isTransactionOnAsynchronousEndpoints()) {
            $pointcut .= '||(' . AsynchronousRunningEndpoint::class . ')';
        }
        if ($dbalConfiguration->isTransactionOnCommandBus()) {
            $pointcut .= '||(' . CommandBus::class . ')';
        }
        if ($dbalConfiguration->isTransactionOnConsoleCommands()) {
            $pointcut .= '||(' . ConsoleCommand::class . ')';
        }
        $pointcut = '(' . $pointcut . ')&&not(' . WithoutDbalTransaction::class . ')';
        $pointcut .= '&&not('. ProjectingConsoleCommands::class . '::backfillProjection)';
        $connectionFactories = $dbalConfiguration->getDefaultConnectionReferenceNames() ?: [DbalConnectionFactory::class];

        $messagingConfiguration->registerServiceDefinition(DbalTransactionInterceptor::class, [
            array_map(fn (string $id) => new Reference($id), $connectionFactories),
            $dbalConfiguration->getDisabledTransactionsOnAsynchronousEndpointNames(),
            new Reference(RetryRunner::class),
            new Reference(LoggingGateway::class),
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
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof DbalConfiguration;
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }
}
