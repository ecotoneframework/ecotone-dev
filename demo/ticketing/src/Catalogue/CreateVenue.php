<?php

declare(strict_types=1);

namespace App\Catalogue;

final readonly class CreateVenue
{
    /**
     * @param list<string> $sections
     * @param list<string> $vipRows
     */
    public function __construct(
        public string $name,
        public array $sections,
        public int $rowsPerSection,
        public int $seatsPerRow,
        public array $vipRows,
    ) {
    }
}
