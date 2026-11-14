<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Messaging\Support\InvalidArgumentException;

/**
 * licence Apache-2.0
 */
final class FlowSnapshots
{
    public function __construct(public readonly string $location)
    {
    }

    public function store(FlowSnapshot $snapshot): void
    {
        if (! is_dir($this->location) && ! @mkdir($this->location, 0o775, true) && ! is_dir($this->location)) {
            throw InvalidArgumentException::create("Flow snapshot of scenario `{$snapshot->scenario}` could not be stored, because the location {$this->location} does not exist and could not be created. Create that directory, or pass a --location the command can write to.");
        }
        $file = $this->location . '/' . $snapshot->fileName();
        if (@file_put_contents($file, $snapshot->toJson()) === false) {
            throw InvalidArgumentException::create("Flow snapshot of scenario `{$snapshot->scenario}` could not be written to {$file}. Make the location {$this->location} writable for the user running this command, or pass another --location.");
        }
    }

    /**
     * @return array<string, FlowSnapshot> file name => snapshot
     */
    public function all(): array
    {
        if (! is_dir($this->location)) {
            throw InvalidArgumentException::create("Flow snapshots were expected in {$this->location}, but that directory does not exist. Pass the directory the snapshots were written to, or write them first with `php bin/internal-flow.php snapshot --location={$this->location}`.");
        }
        $snapshots = [];
        foreach (scandir($this->location) as $name) {
            if (str_ends_with($name, '.json') && is_file($this->location . '/' . $name)) {
                $snapshots[$name] = FlowSnapshot::fromJson((string) file_get_contents($this->location . '/' . $name), $this->location . '/' . $name);
            }
        }
        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }
}
