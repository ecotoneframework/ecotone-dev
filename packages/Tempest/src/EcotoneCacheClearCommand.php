<?php

declare(strict_types=1);

namespace Ecotone\Tempest;

use const DIRECTORY_SEPARATOR;

use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;

/**
 * licence Apache-2.0
 */
final class EcotoneCacheClearCommand
{
    public function __construct(private readonly Console $console)
    {
    }

    #[ConsoleCommand(name: 'ecotone:cache:clear')]
    public function __invoke(): void
    {
        $cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecotone_tempest';

        $this->removeCacheDirectory($cacheDirectory);

        $this->console->writeln('Ecotone cache cleared.');
    }

    private function removeCacheDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $item) {
            if (is_dir($item)) {
                $this->removeCacheDirectory($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($directory);
    }
}
