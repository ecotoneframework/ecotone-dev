<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use Ecotone\Messaging\Console\ConsoleProgressBar;
use Ecotone\Messaging\Console\ConsoleWriter;
use Tempest\Console\Console;

/**
 * licence Apache-2.0
 */
final class TempestConsoleWriter implements ConsoleWriter
{
    public function __construct(private Console $console)
    {
    }

    public function write(string $message): void
    {
        $this->console->write($message);
    }

    public function writeln(string $message = ''): void
    {
        $this->console->writeln($message);
    }

    public function info(string $message): void
    {
        $this->console->info($message);
    }

    public function success(string $message): void
    {
        $this->console->success($message);
    }

    public function warning(string $message): void
    {
        $this->console->warning($message);
    }

    public function error(string $message): void
    {
        $this->console->error($message);
    }

    public function table(array $columnHeaders, array $rows): void
    {
        $columnWidths = $this->resolveColumnWidths($columnHeaders, $rows);

        $this->console->writeln($this->formatRow($columnHeaders, $columnWidths));
        foreach ($rows as $row) {
            $this->console->writeln($this->formatRow($row, $columnWidths));
        }
    }

    public function progressBar(int $maxSteps = 0): ConsoleProgressBar
    {
        return new TempestConsoleProgressBar($this->console, $maxSteps);
    }

    private function resolveColumnWidths(array $columnHeaders, array $rows): array
    {
        $columnWidths = [];
        foreach (array_merge([$columnHeaders], $rows) as $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $columnWidths[$columnIndex] = max($columnWidths[$columnIndex] ?? 0, strlen((string) $value));
            }
        }

        return $columnWidths;
    }

    private function formatRow(array $row, array $columnWidths): string
    {
        $formattedColumns = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $formattedColumns[] = str_pad((string) $value, $columnWidths[$columnIndex]);
        }

        return rtrim(implode(' | ', $formattedColumns));
    }
}
