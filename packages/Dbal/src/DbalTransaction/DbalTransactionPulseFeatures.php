<?php

declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\TransactionReconnectProtection;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Dbal\Pulse\PulseSavepointIsolation;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Modelling\Config\DatabaseTransaction\AsynchronousHandlersWithoutTransaction;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseConsumerFeature;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseFeature;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSectionIsolationRegistration;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 *
 * @internal
 */
final class DbalTransactionPulseFeatures
{
    /**
     * @param object[] $serviceExtensions
     * @return PulseSectionIsolationRegistration[]
     */
    public static function sectionIsolationFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration): array
    {
        $opensTransactions = $dbalConfiguration->transactionOnAsynchronousEndpoints || $dbalConfiguration->transactionOnCommandBus || $dbalConfiguration->transactionOnConsoleCommands;

        return $opensTransactions && PulseFeature::isReportedFor($serviceExtensions) ? [new PulseSectionIsolationRegistration(PulseSavepointIsolation::class)] : [];
    }

    /**
     * @param object[] $serviceExtensions
     * @return PulseFeature[]
     */
    public static function reportedFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration, AnnotationFinder $annotationFinder): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }

        $connections = implode(', ', $dbalConfiguration->defaultConnectionReferenceNames ?: [DbalConnectionReference::DEFAULT]);
        $excluded = $dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames;
        $handlersWithout = [];
        if ($dbalConfiguration->transactionOnCommandBus) {
            foreach ($annotationFinder->findAnnotatedMethods(WithoutDatabaseTransaction::class) as $annotatedMethod) {
                if ($annotatedMethod->hasMethodAnnotation(CommandHandler::class)) {
                    $handlersWithout[] = PulseFeature::handlerOf($annotatedMethod);
                }
            }
        }
        if ($dbalConfiguration->transactionOnAsynchronousEndpoints) {
            foreach (AsynchronousHandlersWithoutTransaction::in($annotationFinder) as $annotatedMethod) {
                $handlersWithout[] = PulseFeature::handlerOf($annotatedMethod);
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
        $hint .= $entryPoints === [] ? '' : match ($dbalConfiguration->transactionReconnectProtection) {
            TransactionReconnectProtection::REQUIRED => ' A connection lost inside the transaction is never reconnected mid-message: the message is rolled back and retried as a whole, and a connection without TransactionReconnectProtectionMiddleware is refused before its first transaction.',
            TransactionReconnectProtection::WHEN_AVAILABLE => ' A connection with TransactionReconnectProtectionMiddleware is never reconnected mid-message, so the message is rolled back and retried as a whole; a connection without it may reconnect inside the transaction and carry on outside it.',
            TransactionReconnectProtection::IGNORE => ' A connection lost inside the transaction may reconnect inside the transaction and carry on outside it, so the work before and after the reconnect is not committed together.',
        };

        return [new PulseFeature(
            package: ModulePackageList::DBAL_PACKAGE,
            name: 'Database transactions',
            enabled: $entryPoints !== [],
            settings: [
                'Command bus' => PulseFeature::onOff($dbalConfiguration->transactionOnCommandBus),
                'Asynchronous endpoints' => $dbalConfiguration->transactionOnAsynchronousEndpoints && $excluded !== [] ? 'on, except ' . implode(', ', $excluded) : PulseFeature::onOff($dbalConfiguration->transactionOnAsynchronousEndpoints),
                'Console commands' => PulseFeature::onOff($dbalConfiguration->transactionOnConsoleCommands),
                'Connections' => $connections,
                'Reconnect protection' => $dbalConfiguration->transactionReconnectProtection->value,
            ],
            handlers: $handlersWithout,
            hint: $hint,
            configuration: 'DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionOnAsynchronousEndpoints(true)->withTransactionOnConsoleCommands(true)',
        )];
    }

    /**
     * @param object[] $serviceExtensions
     * @return PulseConsumerFeature[]
     */
    public static function consumerFeaturesFor(array $serviceExtensions, DbalConfiguration $dbalConfiguration, AnnotationFinder $annotationFinder): array
    {
        if (! PulseFeature::isReportedFor($serviceExtensions)) {
            return [];
        }
        $features = [PulseConsumerFeature::global('Database transaction', PulseFeature::onOff($dbalConfiguration->transactionOnAsynchronousEndpoints))];
        if (! $dbalConfiguration->transactionOnAsynchronousEndpoints) {
            return $features;
        }
        foreach ($dbalConfiguration->disableTransactionsOnAsynchronousEndpointNames as $consumerName) {
            $features[] = PulseConsumerFeature::forConsumer($consumerName, 'Database transaction', 'off');
        }
        foreach (AsynchronousHandlersWithoutTransaction::in($annotationFinder) as $annotatedMethod) {
            if ($annotatedMethod->hasMethodAnnotation(IdentifiedAnnotation::class)) {
                $features[] = PulseConsumerFeature::forConsumer($annotatedMethod->getMethodAnnotationsWithType(IdentifiedAnnotation::class)[0]->getEndpointId(), 'Database transaction', 'off');
            }
        }

        return $features;
    }
}
