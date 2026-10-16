<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Test\StaticMessageBuilder;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTestingStorageFromProvidedConnectionTest extends DbalMessagingTestCase
{
    public function test_a_document_stored_in_a_flow_test_given_a_connection_is_read_by_another_flow_test_on_that_connection(): void
    {
        $this->flowTest([], withConnection: true)->getGateway(DocumentStore::class)->addDocument('orders', 'o-1', ['product' => 'milk']);

        self::assertSame(['product' => 'milk'], $this->flowTest([], withConnection: true)->getGateway(DocumentStore::class)->getDocument('orders', 'o-1'));
    }

    public function test_a_message_handled_in_a_flow_test_given_a_connection_is_skipped_by_another_flow_test_on_that_connection(): void
    {
        $orders = $this->deduplicatedOrders();

        $this->flowTest([$orders], withConnection: true)->sendCommandWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);
        $this->flowTest([$orders], withConnection: true)->sendCommandWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['coffee'], $orders->placed);
    }

    public function test_a_dead_letter_stored_in_a_flow_test_given_a_connection_is_listed_by_another_flow_test_on_that_connection(): void
    {
        $this->flowTest([], withConnection: true)->getGateway(DeadLetterGateway::class)->store($this->failedMessage('m-1'));

        self::assertSame('order coffee', $this->flowTest([], withConnection: true)->getGateway(DeadLetterGateway::class)->show('m-1')->getPayload());
    }

    public function test_a_flow_test_without_a_connection_keeps_documents_in_memory(): void
    {
        $documentStore = $this->flowTest([], withConnection: false)->getGateway(DocumentStore::class);

        $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

        self::assertSame(['product' => 'milk'], $documentStore->getDocument('orders', 'o-1'));
        self::assertSame(0, $this->flowTest([], withConnection: false)->getGateway(DocumentStore::class)->countDocuments('orders'));
    }

    public function test_a_flow_test_without_a_connection_deduplicates_in_memory(): void
    {
        $orders = $this->deduplicatedOrders();
        $ecotone = $this->flowTest([$orders], withConnection: false);

        $ecotone
            ->sendCommandWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1'])
            ->sendCommandWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);
        $this->flowTest([$orders], withConnection: false)->sendCommandWithRouting('order.place', 'coffee', metadata: [MessageHeaders::MESSAGE_ID => 'm-1']);

        self::assertSame(['coffee', 'coffee'], $orders->placed);
    }

    public function test_a_flow_test_without_a_connection_keeps_dead_letters_in_memory(): void
    {
        $deadLetter = $this->flowTest([], withConnection: false)->getGateway(DeadLetterGateway::class);

        $deadLetter->store($this->failedMessage('m-1'));

        self::assertSame(1, $deadLetter->count());
        self::assertSame(0, $this->flowTest([], withConnection: false)->getGateway(DeadLetterGateway::class)->count());
    }

    public function test_a_dbal_channel_in_a_flow_test_without_its_connection_is_refused_naming_both_ways_out(): void
    {
        try {
            $this->bootstrapFlowTesting(extensionObjects: [DbalBackedMessageChannelBuilder::create('orders')], enabledModules: [ModulePackageList::DBAL_PACKAGE]);
            self::fail('A DBAL channel without its connection was accepted');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('Message Channel `orders` is a DbalBackedMessageChannelBuilder', $exception->getMessage());
            self::assertStringContainsString("EcotoneLite::bootstrapFlowTesting(containerOrAvailableServices: ['" . DbalConnectionFactory::class . "' => new DbalConnectionFactory(\$dsn)])", $exception->getMessage());
            self::assertStringContainsString("SimpleMessageChannelBuilder::createQueueChannel('orders')", $exception->getMessage());
        }
    }

    private function flowTest(array $services, bool $withConnection): FlowTestSupport
    {
        return $this->bootstrapFlowTesting(
            classesToResolve: array_map(fn (object $service) => $service::class, $services),
            containerOrAvailableServices: $withConnection
            ? [...$services, DbalConnectionFactory::class => $this->getConnectionFactory()]
            : $services,
            enabledModules: [ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE],
        );
    }

    private function deduplicatedOrders(): object
    {
        return new class () {
            public array $placed = [];

            #[Deduplicated]
            #[CommandHandler('order.place', endpointId: 'order.place.endpoint')]
            public function place(string $order): void
            {
                $this->placed[] = $order;
            }
        };
    }

    private function failedMessage(string $messageId): Message
    {
        return StaticMessageBuilder::withPayload('order coffee')
            ->setMultipleHeaders([
                MessageHeaders::MESSAGE_ID => $messageId,
                ErrorContext::EXCEPTION_MESSAGE => 'failed order coffee',
                ErrorContext::EXCEPTION_STACKTRACE => '#0 handler.php(1)',
                ErrorContext::EXCEPTION_FILE => 'handler.php',
                ErrorContext::EXCEPTION_LINE => 1,
                ErrorContext::EXCEPTION_CODE => 0,
            ])
            ->build();
    }
}
