<?php

declare(strict_types=1);

namespace App\Catalogue;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\AggregateType;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Modelling\WithEvents;
use Symfony\Component\Uid\Uuid;

#[Aggregate]
#[AggregateType('venue')]
#[Entity]
#[Table(name: 'venues')]
final class Venue
{
    use WithEvents;

    private const ROW_LABELS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    #[Version]
    #[Column(type: 'integer')]
    private int $version = 1;

    /**
     * @param list<array{section: string, row: string}> $rows
     * @param list<string> $vipRows
     */
    private function __construct(
        #[Identifier]
        #[Id]
        #[Column(type: 'guid')]
        private string $venueId,
        #[Column(type: 'string')]
        private string $name,
        #[Column(type: 'json')]
        private array $rows,
        #[Column(type: 'integer')]
        private int $seatsPerRow,
        #[Column(type: 'json')]
        private array $vipRows,
    ) {
    }

    #[CommandHandler]
    public static function create(CreateVenue $command): self
    {
        $name = trim($command->name);
        $sections = array_values(array_filter(array_map(trim(...), $command->sections), static fn (string $section): bool => $section !== ''));
        if ($name === '') {
            throw CatalogueRefusal::because('A venue needs a name. Give the venue a name and create it again.');
        }
        if ($sections === [] || $command->rowsPerSection < 1 || $command->seatsPerRow < 1) {
            throw CatalogueRefusal::because(sprintf('Venue "%s" needs at least one section, one row per section and one seat per row. Name its sections and give rows per section and seats per row of 1 or more.', $name));
        }
        $rowCount = count($sections) * $command->rowsPerSection;
        if ($rowCount > strlen(self::ROW_LABELS)) {
            throw CatalogueRefusal::because(sprintf('Venue "%s" would have %d rows, but rows are lettered A to Z, so a venue has at most %d. Use fewer sections or fewer rows per section.', $name, $rowCount, strlen(self::ROW_LABELS)));
        }

        $rows = [];
        foreach ($sections as $sectionIndex => $section) {
            for ($rowIndex = 0; $rowIndex < $command->rowsPerSection; $rowIndex++) {
                $rows[] = ['section' => $section, 'row' => self::ROW_LABELS[$sectionIndex * $command->rowsPerSection + $rowIndex]];
            }
        }
        $rowLabels = array_column($rows, 'row');
        $vipRows = array_values(array_unique(array_filter(array_map(static fn (string $row): string => strtoupper(trim($row)), $command->vipRows), static fn (string $row): bool => $row !== '')));
        foreach ($vipRows as $vipRow) {
            if (! in_array($vipRow, $rowLabels, true)) {
                throw CatalogueRefusal::because(sprintf('Venue "%s" names VIP row "%s", but its rows are %s. Name only rows of the layout as VIP rows.', $name, $vipRow, implode(', ', $rowLabels)));
            }
        }

        $venue = new self(
            venueId: Uuid::v7()->toRfc4122(),
            name: $name,
            rows: $rows,
            seatsPerRow: $command->seatsPerRow,
            vipRows: $vipRows,
        );
        $venue->recordThat(new VenueCreated($venue->venueId, $name));

        return $venue;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<array{seatId: string, seatCode: string, tier: string}>
     */
    public function seatsFor(string $concertId): array
    {
        $seats = [];
        foreach ($this->rows as $row) {
            $tier = in_array($row['row'], $this->vipRows, true) ? Tier::VIP : Tier::STANDARD;
            for ($seatNumber = 1; $seatNumber <= $this->seatsPerRow; $seatNumber++) {
                $seatCode = $row['row'] . '-' . $seatNumber;
                $seats[] = ['seatId' => $concertId . '/' . $seatCode, 'seatCode' => $seatCode, 'tier' => $tier->value];
            }
        }

        return $seats;
    }
}
