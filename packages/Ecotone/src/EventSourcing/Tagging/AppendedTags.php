<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use function array_diff_key;
use function ksort;

/**
 * licence Enterprise
 */
final class AppendedTags
{
    /** @var array<string, array{name: string, value: string}> */
    private readonly array $involved;

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $conditionVersions
     */
    public function __construct(
        private readonly EventsTags $eventsTags,
        private readonly array $conditionVersions,
    ) {
        $involved = $eventsTags->counted();
        foreach ($conditionVersions as $key => $conditionVersion) {
            $involved[$key] = ['name' => $conditionVersion['name'], 'value' => $conditionVersion['value']];
        }

        ksort($involved);
        $this->involved = $involved;
    }

    public function involvesAnyTag(): bool
    {
        return $this->involved !== [] || $this->eventsTags->anyTagged();
    }

    /**
     * @return array<string, array{name: string, value: string}>
     */
    public function needingCapture(): array
    {
        return array_diff_key($this->involved, $this->conditionVersions);
    }

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $captured
     * @return array<string, array{name: string, value: string, expectedVersion: int}>
     */
    public function expectedVersions(array $captured): array
    {
        $expected = [];
        foreach ($this->involved as $key => $tag) {
            $expected[$key] = [
                'name' => $tag['name'],
                'value' => $tag['value'],
                'expectedVersion' => $this->conditionVersions[$key]['expectedVersion'] ?? $captured[$key]['expectedVersion'],
            ];
        }

        return $expected;
    }

    /**
     * @param array<string, array{name: string, value: string, expectedVersion: int}> $expectedVersions
     * @return array<int, array<array{name: string, value: string, sequence: int}>>
     */
    public function sequencedAfterBump(array $expectedVersions): array
    {
        $versionsAfterBump = [];
        foreach ($expectedVersions as $key => $expected) {
            $versionsAfterBump[$key] = $expected['expectedVersion'] + 1;
        }

        return $this->eventsTags->sequencedBy($versionsAfterBump);
    }
}
