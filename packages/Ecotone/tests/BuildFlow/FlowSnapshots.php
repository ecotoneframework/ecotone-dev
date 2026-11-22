<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class FlowSnapshots
{
    public const SHARED_FILE = 'shared.json';
    public const BASELINE = 'packages/Ecotone/tests/BuildFlow/baseline';

    public function __construct(public readonly string $location)
    {
    }

    /**
     * @return list<string>
     */
    public function fileNames(): array
    {
        return array_values(array_filter(is_dir($this->location) ? scandir($this->location) : [], fn (string $name): bool => str_ends_with($name, '.json') && is_file($this->location . '/' . $name)));
    }

    /**
     * @param array<string, FlowSnapshot> $snapshots
     * @return array<string, string>
     */
    public static function filesOf(array $snapshots, FlowSnapshot $shared): array
    {
        $files = [self::SHARED_FILE => $shared->sharedJson()];
        foreach ($snapshots as $fileName => $snapshot) {
            $files[$fileName] = $snapshot->toJsonBeside($shared);
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $keeping
     */
    public function replaceWith(array $files, array $keeping): void
    {
        $this->createLocation('Flow snapshots');
        foreach ($files as $fileName => $content) {
            if (@file_put_contents($this->location . '/' . $fileName, $content) === false) {
                throw InvalidArgumentException::create("Flow snapshot file {$this->location}/{$fileName} could not be written. Make the location {$this->location} writable for the user running this command, or pass another --location.");
            }
        }
        foreach (array_diff($this->fileNames(), array_keys($files), $keeping) as $noLongerProduced) {
            unlink($this->location . '/' . $noLongerProduced);
        }
    }

    /**
     * @param list<string> $fileNames
     * @return array<string, FlowSnapshot>
     */
    public function allBut(array $fileNames): array
    {
        return array_diff_key($this->all(), array_flip($fileNames));
    }

    /**
     * @return array<string, FlowSnapshot>
     */
    public function all(): array
    {
        $this->refuseWhenMissing();
        $shared = $this->shared();
        $snapshots = [];
        foreach ($this->fileNames() as $name) {
            if ($name !== self::SHARED_FILE) {
                $snapshots[$name] = FlowSnapshot::fromJson(json: (string) file_get_contents($this->location . '/' . $name), file: $this->location . '/' . $name, shared: $shared);
            }
        }
        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }

    public function shared(): ?FlowSnapshot
    {
        $sharedFile = $this->location . '/' . self::SHARED_FILE;

        return is_file($sharedFile) ? FlowSnapshot::sharedFromJson((string) file_get_contents($sharedFile), $sharedFile) : null;
    }

    public function refuseWhenMissing(): void
    {
        if (is_dir($this->location)) {
            return;
        }
        if (str_ends_with(rtrim($this->location, '/'), self::BASELINE)) {
            throw InvalidArgumentException::create("Flow snapshots were expected in {$this->location}, the committed baseline, but this checkout has none. A pull request leaves the baseline alone: main's CI commits it after the merge, so do not write it here. Snapshot the base instead, into a directory of your own: on main, run `php bin/build-flow.php snapshot --location=var/build-flow-main`. Then, on your branch, compare with it: `php bin/build-flow.php compare --presentation=build --from=var/build-flow-main`. Check compares a directory with what the checked-out code produces, so `php bin/build-flow.php check --location=var/build-flow-main` passes on main and, on your branch, names what your change alters. Once main's CI has committed the baseline, merge main and the defaults work again.");
        }
        throw InvalidArgumentException::create("Flow snapshots were expected in {$this->location}, but that directory does not exist. Pass the directory the snapshots were written to, or write them first with `php bin/build-flow.php snapshot --location={$this->location}`.");
    }

    private function createLocation(string $stored): void
    {
        if (! is_dir($this->location) && ! @mkdir($this->location, 0o775, true) && ! is_dir($this->location)) {
            throw InvalidArgumentException::create("{$stored} could not be stored, because the location {$this->location} does not exist and could not be created. Create that directory, or pass a --location the command can write to.");
        }
    }
}
