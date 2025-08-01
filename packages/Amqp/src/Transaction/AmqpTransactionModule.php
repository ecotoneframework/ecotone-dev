<?php

namespace Ecotone\Amqp\Transaction;

use function array_map;

use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\AnnotationFinder\AnnotationFinder;
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
use Enqueue\AmqpExt\AmqpConnectionFactory;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
class AmqpTransactionModule implements AnnotationModule
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
        $connectionFactories = [AmqpConnectionFactory::class];
        $pointcut = AmqpTransaction::class;
        $amqpConfiguration = ExtensionObjectResolver::resolveUnique(AmqpConfiguration::class, $extensionObjects, AmqpConfiguration::createWithDefaults());
        ;

        if ($amqpConfiguration->isTransactionOnAsynchronousEndpoints()) {
            $pointcut .= '||' . AsynchronousRunningEndpoint::class;
        }
        if ($amqpConfiguration->isTransactionOnCommandBus()) {
            $pointcut .= '||' . CommandBus::class;
        }
        if ($amqpConfiguration->isTransactionOnConsoleCommands()) {
            $pointcut .= '||' . ConsoleCommand::class;
        }
        if ($amqpConfiguration->getDefaultConnectionReferenceNames()) {
            $connectionFactories = $amqpConfiguration->getDefaultConnectionReferenceNames();
        }

        $messagingConfiguration->registerServiceDefinition(AmqpTransactionInterceptor::class, [
            array_map(fn (string $connectionFactory) => Reference::to($connectionFactory), $connectionFactories),
            Reference::to(LoggingGateway::class),
            Reference::to(RetryRunner::class),
        ]);

        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    AmqpTransactionInterceptor::class,
                    $interfaceToCallRegistry->getFor(AmqpTransactionInterceptor::class, 'transactional'),
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE - 1,
                    $pointcut
                )
            );
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof AmqpConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
