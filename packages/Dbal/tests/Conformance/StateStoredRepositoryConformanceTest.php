<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Closure;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\TargetIdentifier;
use Ecotone\Api\Attribute\TargetVersion;
use Ecotone\Api\Attribute\Version;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Dbal\Connection\DbalConnectionFactory;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Support\ConcurrencyException;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class StateStoredRepositoryConformanceTest extends DbalMessagingTestCase
{
    private const KNOWN_DIVERGENCES = [
        'R1' => [
            'sides' => 'a save refused for a stale version — the in-memory repository and the in-memory document store hand the handler the stored instance (DocumentStoreConformanceTest D3), so the change the handler made stays in it; the DBAL document store hands it a copy read from the table, so the stored aggregate is unchanged',
            'cases' => ['test_a_refused_save_leaves_the_stored_aggregate_as_it_was' => ['in-memory', 'document-store-in-memory']],
        ],
    ];

    public static function implementations(): iterable
    {
        yield 'in-memory' => ['in-memory'];
        yield 'document-store-in-memory' => ['document-store-in-memory'];
        yield 'document-store-dbal' => ['document-store-dbal'];
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
    public function test_a_command_carrying_the_current_version_changes_the_aggregate(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'One'));

            $ecotone->sendCommand(new RenameTicketForRepositoryConformance('t-1', 1, 'Renamed'));

            self::assertSame('Renamed', $ecotone->sendQueryWithRouting('repositoryConformance.title', metadata: ['aggregate.id' => 't-1']));
            self::assertSame(2, $ecotone->sendQueryWithRouting('repositoryConformance.version', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_command_carrying_a_stale_version_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'One'));
            $ecotone->sendCommand(new RenameTicketForRepositoryConformance('t-1', 1, 'Renamed'));

            try {
                $ecotone->sendCommand(new RenameTicketForRepositoryConformance('t-1', 1, 'Renamed again'));
                self::fail('Expected the stale version to be refused');
            } catch (ConcurrencyException $exception) {
                self::assertStringContainsString('Aggregate ' . TicketForRepositoryConformance::class . ' t-1 was loaded at version 1, but it is at version 2 now', $exception->getMessage());
                self::assertStringContainsString('Load it again and retry the command', $exception->getMessage());
            }

            self::assertSame(2, $ecotone->sendQueryWithRouting('repositoryConformance.version', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_refused_save_leaves_the_stored_aggregate_as_it_was(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'One'));
            $ecotone->sendCommand(new RenameTicketForRepositoryConformance('t-1', 1, 'Renamed'));

            try {
                $ecotone->sendCommand(new RenameTicketForRepositoryConformance('t-1', 1, 'Renamed again'));
            } catch (ConcurrencyException) {
            }

            self::assertSame('Renamed', $ecotone->sendQueryWithRouting('repositoryConformance.title', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_creating_an_aggregate_under_an_id_that_is_already_stored_is_refused(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'One'));

            try {
                $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'Two'));
                self::fail('Expected the stored aggregate to be kept');
            } catch (ConcurrencyException) {
            }

            self::assertSame('One', $ecotone->sendQueryWithRouting('repositoryConformance.title', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_handler_chained_after_creating_a_versioned_aggregate_saves_it_again(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('repositoryConformance.openLabelled', metadata: ['ticketId' => 't-1', 'label' => 'Labelled']);

            self::assertSame('Labelled', $ecotone->sendQueryWithRouting('repositoryConformance.title', metadata: ['aggregate.id' => 't-1']));
            self::assertSame(2, $ecotone->sendQueryWithRouting('repositoryConformance.version', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_handler_chained_after_changing_a_versioned_aggregate_saves_it_again(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new OpenTicketForRepositoryConformance('t-1', 'One'));

            $ecotone->sendCommandWithRouting('repositoryConformance.relabel', metadata: ['ticketId' => 't-1', 'label' => 'Relabelled']);

            self::assertSame('Relabelled', $ecotone->sendQueryWithRouting('repositoryConformance.title', metadata: ['aggregate.id' => 't-1']));
            self::assertSame(3, $ecotone->sendQueryWithRouting('repositoryConformance.version', metadata: ['aggregate.id' => 't-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_handler_chained_after_creating_an_aggregate_without_a_version_saves_it_again(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('repositoryConformance.note.openLabelled', metadata: ['noteId' => 'n-1', 'label' => 'Labelled']);

            self::assertSame('Labelled', $ecotone->sendQueryWithRouting('repositoryConformance.note.text', metadata: ['aggregate.id' => 'n-1']));
        });
    }

    #[DataProvider('implementations')]
    public function test_a_handler_chained_after_changing_an_aggregate_without_a_version_saves_it_again(string $implementation): void
    {
        $this->conformanceCase($implementation, function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommandWithRouting('repositoryConformance.note.openLabelled', metadata: ['noteId' => 'n-1', 'label' => 'Labelled']);

            $ecotone->sendCommandWithRouting('repositoryConformance.note.relabel', metadata: ['noteId' => 'n-1', 'label' => 'Relabelled']);

            self::assertSame('Relabelled', $ecotone->sendQueryWithRouting('repositoryConformance.note.text', metadata: ['aggregate.id' => 'n-1']));
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
        if ($implementation !== 'document-store-dbal') {
            return $implementation;
        }

        $platform = $this->getConnection()->getDatabasePlatform();

        return 'document-store-dbal:' . match (true) {
            $platform instanceof MariaDBPlatform => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'pgsql',
            $platform instanceof SQLitePlatform => 'sqlite',
        };
    }

    private function bootstrap(string $implementation): FlowTestSupport
    {
        $classesToResolve = [TicketForRepositoryConformance::class, OpenTicketForRepositoryConformance::class, RenameTicketForRepositoryConformance::class, NoteForRepositoryConformance::class];

        if ($implementation === 'in-memory') {
            return $this->bootstrapFlowTesting(
                classesToResolve: $classesToResolve,
                configuration: ServiceConfiguration::createWithDefaults()->withModulePackages([]),
            );
        }

        return $this->bootstrapFlowTesting(
            classesToResolve: $classesToResolve,
            containerOrAvailableServices: [DbalConnectionFactory::class => $this->getConnectionFactory()],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([ModulePackageList::DBAL_PACKAGE, ModulePackageList::JMS_CONVERTER_PACKAGE])
                ->withExtensionObjects([
                    DbalConfiguration::createWithDefaults()->withDocumentStore(
                        inMemoryDocumentStore: $implementation === 'document-store-in-memory',
                        enableDocumentStoreStateStoredRepository: true,
                    ),
                ]),
            addInMemoryStateStoredRepository: false,
        );
    }
}

#[Aggregate]
final class TicketForRepositoryConformance
{
    #[Version]
    private int $version = 0;

    private function __construct(
        #[Identifier] private string $ticketId,
        private string $title,
    ) {
    }

    #[CommandHandler]
    public static function open(OpenTicketForRepositoryConformance $command): self
    {
        return new self($command->ticketId, $command->title);
    }

    #[CommandHandler]
    public function rename(RenameTicketForRepositoryConformance $command): void
    {
        $this->title = $command->title;
    }

    #[CommandHandler(routingKey: 'repositoryConformance.openLabelled', outputChannelName: 'repositoryConformance.label', identifierMetadataMapping: ['ticketId' => 'ticketId'])]
    public static function openLabelled(#[Header('ticketId')] string $ticketId): self
    {
        return new self($ticketId, 'Untitled');
    }

    #[CommandHandler(routingKey: 'repositoryConformance.relabel', outputChannelName: 'repositoryConformance.label', identifierMetadataMapping: ['ticketId' => 'ticketId'])]
    public function relabel(#[Header('label')] string $label): string
    {
        return $label;
    }

    #[CommandHandler(routingKey: 'repositoryConformance.label')]
    public function label(#[Header('label')] string $label): void
    {
        $this->title = $label;
    }

    #[QueryHandler('repositoryConformance.title')]
    public function title(): string
    {
        return $this->title;
    }

    #[QueryHandler('repositoryConformance.version')]
    public function version(): int
    {
        return $this->version;
    }
}

#[Aggregate]
final class NoteForRepositoryConformance
{
    private function __construct(
        #[Identifier] private string $noteId,
        private string $text,
    ) {
    }

    #[CommandHandler(routingKey: 'repositoryConformance.note.openLabelled', outputChannelName: 'repositoryConformance.note.label', identifierMetadataMapping: ['noteId' => 'noteId'])]
    public static function openLabelled(#[Header('noteId')] string $noteId): self
    {
        return new self($noteId, 'Untitled');
    }

    #[CommandHandler(routingKey: 'repositoryConformance.note.relabel', outputChannelName: 'repositoryConformance.note.label', identifierMetadataMapping: ['noteId' => 'noteId'])]
    public function relabel(#[Header('label')] string $label): string
    {
        return $label;
    }

    #[CommandHandler(routingKey: 'repositoryConformance.note.label')]
    public function label(#[Header('label')] string $label): void
    {
        $this->text = $label;
    }

    #[QueryHandler('repositoryConformance.note.text')]
    public function text(): string
    {
        return $this->text;
    }
}

final readonly class OpenTicketForRepositoryConformance
{
    public function __construct(public string $ticketId, public string $title)
    {
    }
}

final readonly class RenameTicketForRepositoryConformance
{
    public function __construct(
        #[TargetIdentifier] public string $ticketId,
        #[TargetVersion] public int $version,
        public string $title,
    ) {
    }
}
