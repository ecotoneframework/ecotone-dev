<?php

namespace Ecotone\Amqp\Transaction;

use function array_map;

use Ecotone\Amqp\AmqpReconnectableConnectionFactory;
use Ecotone\Amqp\Configuration\AmqpConfiguration;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Amqp\AmqpConnectionReference;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryRunner;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class AmqpTransactionModule implements AnnotationModule
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
        $connectionFactories = [AmqpConnectionReference::DEFAULT];
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
            array_map(
                fn (string $connectionFactory) => CachedConnectionFactory::registerIn(
                    $messagingConfiguration,
                    AmqpReconnectableConnectionFactory::connectionKeyFor($connectionFactory, false),
                    AmqpReconnectableConnectionFactory::definitionFor($connectionFactory, false),
                ),
                $connectionFactories,
            ),
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
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $amqpConfiguration = ExtensionObjectResolver::resolveUnique(AmqpConfiguration::class, $serviceExtensions, AmqpConfiguration::createWithDefaults());
        $connections = implode(', ', $amqpConfiguration->getDefaultConnectionReferenceNames() ?: [AmqpConnectionReference::DEFAULT]);
        $entryPoints = array_keys(array_filter([
            'command sent through the command bus' => $amqpConfiguration->isTransactionOnCommandBus(),
            'message an asynchronous endpoint consumes' => $amqpConfiguration->isTransactionOnAsynchronousEndpoints(),
            'console command' => $amqpConfiguration->isTransactionOnConsoleCommands(),
        ]));

        return [new PulseFeature(
            package: ModulePackageList::AMQP_PACKAGE,
            name: 'RabbitMQ transactions',
            enabled: $entryPoints !== [],
            settings: [
                'Command bus' => PulseFeature::onOff($amqpConfiguration->isTransactionOnCommandBus()),
                'Asynchronous endpoints' => PulseFeature::onOff($amqpConfiguration->isTransactionOnAsynchronousEndpoints()),
                'Console commands' => PulseFeature::onOff($amqpConfiguration->isTransactionOnConsoleCommands()),
                'Connections' => $connections,
            ],
            handlers: [],
            hint: $entryPoints === []
                ? 'No entry point opens a RabbitMQ transaction, so a message a handler publishes to RabbitMQ is sent at once, even when the handler fails after publishing it.'
                : sprintf('Each %s runs inside a RabbitMQ transaction on %s: the messages its handler publishes to RabbitMQ are sent when it succeeds, and none of them when it fails.', implode(', each ', $entryPoints), $connections),
            configuration: 'AmqpConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)',
        )];
    }

    /**
     * @inheritDoc
     */
    public function getModulePackageName(): string
    {
        return ModulePackageList::AMQP_PACKAGE;
    }
}
