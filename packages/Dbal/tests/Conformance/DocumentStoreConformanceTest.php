<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\DocumentStore;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Messaging\Store\Document\DocumentException;
use Ecotone\Messaging\Store\Document\DocumentNotFound;
use Ecotone\Messaging\Support\ConcurrencyException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class DocumentStoreConformanceTest extends DbalMessagingTestCase
{
    private const KNOWN_DIVERGENCES = [
        'D1' => [
            'sides' => 'a document string that is not JSON — PostgreSQL, MySQL and MariaDB refuse it on add and on update, in-memory refuses it on add only, SQLite accepts it on both',
            'cases' => [
                'test_adding_a_string_that_is_not_json_is_refused' => ['dbal:sqlite'],
                'test_updating_with_a_string_that_is_not_json_is_refused' => ['in-memory', 'dbal:sqlite'],
            ],
        ],
        'D3' => [
            'sides' => 'an object changed after addDocument() — in-memory holds the stored object by reference so the stored document changes too, DBAL stored a copy',
            'cases' => ['test_changing_an_object_after_storing_it_does_not_change_the_stored_document' => ['in-memory']],
        ],
        'D4' => [
            'sides' => 'document ids differing only in case — MySQL and MariaDB collide on them through the case-insensitive collation of the primary key, in-memory, PostgreSQL and SQLite keep two documents',
            'cases' => ['test_document_ids_differing_only_in_case_are_distinct_documents' => ['dbal:mysql', 'dbal:mariadb']],
        ],
        'D5' => [
            'sides' => 'an array document mixing value types — in-memory returns it, DBAL stores it as array<string,mixed> and cannot read it back through JMS (Class "mixed" does not exist)',
            'cases' => ['test_an_array_document_mixing_value_types_is_returned_as_it_was_stored' => ['dbal']],
        ],
    ];

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'dbal' => ['dbal'];
    }

    public function test_every_known_divergence_names_a_case_of_this_suite(): void
    {
        foreach (self::KNOWN_DIVERGENCES as $knownDivergence) {
            foreach (array_keys($knownDivergence['cases']) as $case) {
                self::assertContains($case, get_class_methods($this));
            }
        }
    }

    #[DataProvider('implementations')]
    public function test_an_added_document_is_returned_as_it_was_stored(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk', 'size' => 'large']);
            $documentStore->addDocument('orders', 'o-2', '{"product":"water"}');
            $documentStore->addDocument('orders', 'o-3', new OrderForDocumentStoreConformance('bread', 1));

            self::assertEquals(['product' => 'milk', 'size' => 'large'], $documentStore->getDocument('orders', 'o-1'));
            self::assertEquals(['product' => 'milk', 'size' => 'large'], $documentStore->findDocument('orders', 'o-1'));
            self::assertJsonStringEqualsJsonString('{"product":"water"}', $documentStore->getDocument('orders', 'o-2'));
            self::assertEquals(new OrderForDocumentStoreConformance('bread', 1), $documentStore->getDocument('orders', 'o-3'));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_array_document_mixing_value_types_is_returned_as_it_was_stored(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk', 'quantity' => 2]);

            self::assertEquals(['product' => 'milk', 'quantity' => 2], $documentStore->getDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_empty_document_is_found_as_empty_rather_than_missing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', []);

            self::assertSame([], $documentStore->findDocument('orders', 'o-1'));
            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_adding_a_document_id_that_is_already_stored_is_refused_and_keeps_the_first(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            try {
                $documentStore->addDocument('orders', 'o-1', ['product' => 'water']);
                self::fail('Expected the duplicate document to be refused');
            } catch (DocumentException) {
            }

            self::assertSame(['product' => 'milk'], $documentStore->getDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_adding_a_string_that_is_not_json_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            try {
                $documentStore->addDocument('orders', 'o-1', 'not a json');
                self::fail('Expected a string that is not JSON to be refused');
            } catch (DocumentException) {
            }

            self::assertNull($documentStore->findDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_updating_with_a_string_that_is_not_json_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', '{"product":"milk"}');

            try {
                $documentStore->updateDocument('orders', 'o-1', 'not a json', expectedVersion: 1);
                self::fail('Expected a string that is not JSON to be refused');
            } catch (DocumentException) {
            }

            self::assertJsonStringEqualsJsonString('{"product":"milk"}', $documentStore->getDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_updated_document_replaces_the_stored_one(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);

            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_updating_a_document_that_is_not_stored_is_refused_and_stores_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            try {
                $documentStore->updateDocument('orders', 'o-1', ['product' => 'milk'], expectedVersion: 1);
                self::fail('Expected the missing document to be reported');
            } catch (DocumentNotFound) {
            }

            self::assertNull($documentStore->findDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_upserting_adds_a_missing_document_and_replaces_a_stored_one(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'milk'], expectedVersion: 0);
            self::assertSame(['product' => 'milk'], $documentStore->getDocument('orders', 'o-1'));

            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);
            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_an_added_document_is_at_version_one_and_a_missing_one_at_version_zero(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            self::assertSame(1, $documentStore->getDocumentVersion('orders', 'o-1'));
            self::assertSame(0, $documentStore->getDocumentVersion('orders', 'o-404'));
            self::assertSame(0, $documentStore->getDocumentVersion('invoices', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_each_write_under_the_current_version_moves_the_version_by_one(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'milk'], expectedVersion: 0);
            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'bread'], expectedVersion: 2);

            self::assertSame(3, $documentStore->getDocumentVersion('orders', 'o-1'));
            self::assertSame(['product' => 'bread'], $documentStore->getDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_updating_under_a_stale_version_is_refused_and_keeps_the_stored_document(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);

            try {
                $documentStore->updateDocument('orders', 'o-1', ['product' => 'bread'], expectedVersion: 1);
                self::fail('Expected the stale version to be refused');
            } catch (ConcurrencyException) {
            }

            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(2, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_upserting_under_a_stale_version_is_refused_and_keeps_the_stored_document(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);

            try {
                $documentStore->upsertDocument('orders', 'o-1', ['product' => 'bread'], expectedVersion: 1);
                self::fail('Expected the stale version to be refused');
            } catch (ConcurrencyException) {
            }

            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(2, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_upserting_a_new_document_under_an_id_that_is_already_stored_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            try {
                $documentStore->upsertDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 0);
                self::fail('Expected the stored document to be kept');
            } catch (ConcurrencyException) {
            }

            self::assertSame(['product' => 'milk'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(1, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_upserting_under_a_version_of_a_document_that_is_no_longer_stored_is_refused_and_stores_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->deleteDocument('orders', 'o-1');

            try {
                $documentStore->upsertDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);
                self::fail('Expected the version of a deleted document to be refused');
            } catch (ConcurrencyException) {
            }

            self::assertNull($documentStore->findDocument('orders', 'o-1'));
            self::assertSame(0, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_document_added_again_after_its_deletion_starts_at_version_one(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);
            $documentStore->deleteDocument('orders', 'o-1');

            $documentStore->addDocument('orders', 'o-1', ['product' => 'bread']);

            self::assertSame(1, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_last_write_wins_replaces_whatever_is_stored_and_still_moves_the_version(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'milk'], expectedVersion: DocumentStore::LAST_WRITE_WINS);
            self::assertSame(1, $documentStore->getDocumentVersion('orders', 'o-1'));

            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);
            $documentStore->upsertDocument('orders', 'o-1', ['product' => 'bread'], expectedVersion: DocumentStore::LAST_WRITE_WINS);
            $documentStore->updateDocument('orders', 'o-1', ['product' => 'cheese'], expectedVersion: DocumentStore::LAST_WRITE_WINS);

            self::assertSame(['product' => 'cheese'], $documentStore->getDocument('orders', 'o-1'));
            self::assertSame(4, $documentStore->getDocumentVersion('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_stale_version_names_both_versions_and_the_way_out(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->updateDocument('orders', 'o-1', ['product' => 'water'], expectedVersion: 1);

            try {
                $documentStore->updateDocument('orders', 'o-1', ['product' => 'bread'], expectedVersion: 1);
                self::fail('Expected the stale version to be refused');
            } catch (ConcurrencyException $exception) {
                self::assertStringContainsString('Document o-1 in collection orders was expected at version 1, but it is at version 2 now', $exception->getMessage());
                self::assertStringContainsString('DocumentStore::getDocument() and DocumentStore::getDocumentVersion()', $exception->getMessage());
                self::assertStringContainsString('DocumentStore::LAST_WRITE_WINS', $exception->getMessage());
            }
        });
    }

    #[DataProvider('implementations')]
    public function test_a_deleted_document_is_no_longer_found(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->addDocument('orders', 'o-2', ['product' => 'water']);

            $documentStore->deleteDocument('orders', 'o-1');

            self::assertNull($documentStore->findDocument('orders', 'o-1'));
            self::assertSame([['product' => 'water']], $documentStore->getAllDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_deleting_a_document_that_is_not_stored_changes_nothing(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            $documentStore->deleteDocument('orders', 'o-404');
            $documentStore->deleteDocument('invoices', 'o-1');

            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_getting_a_document_that_is_not_stored_is_refused_while_finding_it_returns_null(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);

            self::assertNull($documentStore->findDocument('orders', 'o-404'));
            self::assertNull($documentStore->findDocument('invoices', 'o-1'));

            try {
                $documentStore->getDocument('orders', 'o-404');
                self::fail('Expected getting a document that is not stored to be refused');
            } catch (DocumentNotFound) {
            }

            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_collection_nothing_was_stored_in_is_empty(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            self::assertSame([], $documentStore->getAllDocuments('orders'));
            self::assertSame(0, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_all_documents_of_a_collection_are_returned(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-2', ['product' => 'milk']);
            $documentStore->addDocument('orders', 'o-1', ['product' => 'water']);
            $documentStore->addDocument('orders', 'o-3', ['product' => 'bread']);

            $documentStore->updateDocument('orders', 'o-2', ['product' => 'cheese'], expectedVersion: 1);

            self::assertEqualsCanonicalizing(
                [['product' => 'cheese'], ['product' => 'water'], ['product' => 'bread']],
                $documentStore->getAllDocuments('orders'),
            );
            self::assertSame(3, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_collections_keep_their_documents_apart(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'd-1', ['kind' => 'order']);
            $documentStore->addDocument('invoices', 'd-1', ['kind' => 'invoice']);

            self::assertSame(['kind' => 'order'], $documentStore->getDocument('orders', 'd-1'));
            self::assertSame(['kind' => 'invoice'], $documentStore->getDocument('invoices', 'd-1'));
            self::assertSame(1, $documentStore->countDocuments('orders'));
        });
    }

    #[DataProvider('implementations')]
    public function test_dropping_a_collection_removes_only_its_documents(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'd-1', ['kind' => 'order']);
            $documentStore->addDocument('invoices', 'd-1', ['kind' => 'invoice']);

            $documentStore->dropCollection('orders');
            $documentStore->dropCollection('never_used');

            self::assertSame([], $documentStore->getAllDocuments('orders'));
            self::assertSame(0, $documentStore->countDocuments('orders'));
            self::assertSame(['kind' => 'invoice'], $documentStore->getDocument('invoices', 'd-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_dropped_collection_accepts_documents_again(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'o-1', ['product' => 'milk']);
            $documentStore->dropCollection('orders');

            $documentStore->addDocument('orders', 'o-1', ['product' => 'water']);

            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'o-1'));
        });
    }

    #[DataProvider('implementations')]
    public function test_document_ids_differing_only_in_case_are_distinct_documents(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $documentStore->addDocument('orders', 'order-a', ['product' => 'milk']);
            $documentStore->addDocument('orders', 'ORDER-A', ['product' => 'water']);

            self::assertSame(['product' => 'milk'], $documentStore->getDocument('orders', 'order-a'));
            self::assertSame(['product' => 'water'], $documentStore->getDocument('orders', 'ORDER-A'));
        });
    }

    #[DataProvider('implementations')]
    public function test_changing_an_object_after_storing_it_does_not_change_the_stored_document(string $implementation): void
    {
        $this->conformanceCase($implementation, function (DocumentStore $documentStore): void {
            $order = new MutableOrderForDocumentStoreConformance('milk');

            $documentStore->addDocument('orders', 'o-1', $order);
            $order->product = 'water';

            self::assertEquals(new MutableOrderForDocumentStoreConformance('milk'), $documentStore->getDocument('orders', 'o-1'));
        });
    }

    private function conformanceCase(string $implementation, Closure $case): void
    {
        $target = $this->targetOf($implementation);
        $knownDivergence = $this->knownDivergenceOf($this->name(), $target);
        if ($knownDivergence === null) {
            $case($this->bootstrap($implementation));

            return;
        }

        try {
            $case($this->bootstrap($implementation));
        } catch (Throwable $divergence) {
            self::markTestSkipped("Known divergence {$knownDivergence['id']} on {$target}: {$knownDivergence['sides']}. This run: " . strtok($divergence->getMessage(), "\n"));
        }

        self::fail(
            "Known divergence {$knownDivergence['id']} is recorded for '{$knownDivergence['selector']}' on {$this->name()}, but the case now passes on {$target}. "
            . "Remove '{$knownDivergence['selector']}' from {$knownDivergence['id']} in KNOWN_DIVERGENCES, so the case runs there and a new divergence fails the suite again."
        );
    }

    /**
     * @return array{id: string, selector: string, sides: string}|null
     */
    private function knownDivergenceOf(string $case, string $target): ?array
    {
        foreach (self::KNOWN_DIVERGENCES as $id => $knownDivergence) {
            foreach ($knownDivergence['cases'][$case] ?? [] as $selector) {
                if ($selector === $target || str_starts_with($target, $selector . ':')) {
                    return ['id' => $id, 'selector' => $selector, 'sides' => $knownDivergence['sides']];
                }
            }
        }

        return null;
    }

    private function targetOf(string $implementation): string
    {
        if ($implementation !== 'dbal') {
            return $implementation;
        }

        $platform = $this->getConnection()->getDatabasePlatform();

        return 'dbal:' . match (true) {
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof SQLitePlatform => 'sqlite',
        };
    }

    private function bootstrap(string $implementation): DocumentStore
    {
        return $this->bootstrapFlowTesting(
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDocumentStore(inMemoryDocumentStore: match ($implementation) {
                        'in-memory' => true,
                        'dbal' => false,
                    }),
                ]),
        )->getGateway(DocumentStore::class);
    }
}

final readonly class OrderForDocumentStoreConformance
{
    public function __construct(public string $product, public int $quantity)
    {
    }
}

final class MutableOrderForDocumentStoreConformance
{
    public function __construct(public string $product)
    {
    }
}
