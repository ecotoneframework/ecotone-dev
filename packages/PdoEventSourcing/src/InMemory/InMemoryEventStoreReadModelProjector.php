<?php

/**
 * This file is part of prooph/event-store.
 * (c) 2014-2025 prooph software GmbH <contact@prooph.de>
 * (c) 2015-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Ecotone\EventSourcing\InMemory;

use function array_keys;
use function array_merge;
use function array_values;

use Closure;
use Ecotone\EventSourcing\Prooph\ProophInMemoryEventStoreAdapter;

use function extension_loaded;
use function func_get_arg;
use function func_num_args;
use function get_class;
use function is_array;
use function is_callable;
use function is_string;
use function pcntl_signal_dispatch;

use Prooph\EventStore\Exception;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Projection\MetadataAwareReadModelProjector;
use Prooph\EventStore\Projection\ProjectionStatus;
use Prooph\EventStore\Projection\ReadModel;
use Prooph\EventStore\StreamIterator\MergedStreamIterator;
use Prooph\EventStore\StreamName;
use Prooph\EventStore\Util\ArrayCache;
use ReflectionProperty;

use function strlen;
use function substr;
use function trigger_error;
use function usleep;

/**
 * licence Apache-2.0
 */
final class InMemoryEventStoreReadModelProjector implements MetadataAwareReadModelProjector
{
    private string $name;

    private ProjectionStatus $status;

    private ProophInMemoryEventStoreAdapter $eventStore;

    private ReadModel $readModel;

    private ArrayCache $cachedStreamNames;

    private int $eventCounter = 0;

    private int $persistBlockSize;

    private array $streamPositions = [];

    private array $state = [];

    private ?Closure $initCallback = null;

    private ?Closure $handler = null;

    private array $handlers = [];

    private bool $isStopped = false;

    private ?string $currentStreamName = null;

    private int $sleep;

    private bool $triggerPcntlSignalDispatch;

    private ?array $query = null;

    private ?MetadataMatcher $metadataMatcher = null;

    public function __construct(
        ProophInMemoryEventStoreAdapter $eventStore,
        string                    $name,
        ReadModel                 $readModel,
        int                       $cacheSize,
        int                       $persistBlockSize,
        int                       $sleep,
        bool                      $triggerPcntlSignalDispatch = false
    ) {
        if ($cacheSize < 1) {
            throw new Exception\InvalidArgumentException('cache size must be a positive integer');
        }

        if ($persistBlockSize < 1) {
            throw new Exception\InvalidArgumentException('persist block size must be a positive integer');
        }

        if ($sleep < 1) {
            throw new Exception\InvalidArgumentException('sleep must be a positive integer');
        }

        if ($triggerPcntlSignalDispatch && ! extension_loaded('pcntl')) {
            throw Exception\ExtensionNotLoadedException::withName('pcntl');
        }

        $this->eventStore = $eventStore;
        $this->name = $name;
        $this->cachedStreamNames = new ArrayCache($cacheSize);
        $this->persistBlockSize = $persistBlockSize;
        $this->readModel = $readModel;
        $this->sleep = $sleep;
        $this->status = ProjectionStatus::IDLE(); // @phpstan-ignore-line
        $this->triggerPcntlSignalDispatch = $triggerPcntlSignalDispatch;
    }

    public function init(Closure $callback): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->initCallback) {
            throw new Exception\RuntimeException('Projector is already initialized');
        }

        $callback = Closure::bind($callback, $this->createHandlerContext($this->currentStreamName));

        $result = $callback();

        if (is_array($result)) {
            $this->state = $result;
        }

        $this->initCallback = $callback;

        return $this;
    }

    public function withMetadataMatcher(?MetadataMatcher $metadataMatcher = null): InMemoryEventStoreReadModelProjector
    {
        $this->metadataMatcher = $metadataMatcher;

        return $this;
    }

    public function fromStream(string $streamName/**, ?MetadataMatcher $metadataMatcher = null*/): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        if (func_num_args() > 1) {
            trigger_error('The $metadataMatcher parameter is deprecated. Use withMetadataMatcher() instead.', E_USER_DEPRECATED);
            $this->metadataMatcher = func_get_arg(1);
        }

        $this->query['streams'][] = $streamName;

        return $this;
    }

    public function fromStreams(string ...$streamNames): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        foreach ($streamNames as $streamName) {
            $this->query['streams'][] = $streamName;
        }

        return $this;
    }

    public function fromCategory(string $name): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        $this->query['categories'][] = $name;

        return $this;
    }

    public function fromCategories(string ...$names): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        foreach ($names as $name) {
            $this->query['categories'][] = $name;
        }

        return $this;
    }

    public function fromAll(): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->query) {
            throw new Exception\RuntimeException('From was already called');
        }

        $this->query['all'] = true;

        return $this;
    }

    public function when(array $handlers): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->handler || $this->handlers !== []) {
            throw new Exception\RuntimeException('When was already called');
        }

        foreach ($handlers as $eventName => $handler) {
            if (! is_string($eventName)) {
                throw new Exception\InvalidArgumentException('Invalid event name given, string expected');
            }

            if (! $handler instanceof Closure) {
                throw new Exception\InvalidArgumentException('Invalid handler given, Closure expected');
            }

            $this->handlers[$eventName] = Closure::bind($handler, $this->createHandlerContext($this->currentStreamName));
        }

        return $this;
    }

    public function whenAny(Closure $handler): InMemoryEventStoreReadModelProjector
    {
        if (null !== $this->handler || $this->handlers !== []) {
            throw new Exception\RuntimeException('When was already called');
        }

        $this->handler = Closure::bind($handler, $this->createHandlerContext($this->currentStreamName));

        return $this;
    }

    public function readModel(): ReadModel
    {
        return $this->readModel;
    }

    public function delete(bool $deleteProjection): void
    {
        if ($deleteProjection) {
            $this->readModel->delete();
        }

        $this->streamPositions = [];
    }

    public function run(bool $keepRunning = true): void
    {
        if (null === $this->query
            || (null === $this->handler && $this->handlers === [])
        ) {
            throw new Exception\RuntimeException('No handlers configured');
        }

        $this->prepareStreamPositions();
        $this->isStopped = false;
        $this->status = ProjectionStatus::RUNNING();  // @phpstan-ignore-line

        if (! $this->readModel->isInitialized()) {
            $this->readModel->init();
        }

        do {
            $singleHandler = null !== $this->handler;

            $eventCounter = 0;
            $eventStreams = [];

            foreach ($this->streamPositions as $streamName => $position) {
                try {
                    $eventStreams[$streamName] = $this->eventStore->load(new StreamName($streamName), $position + 1, null, $this->metadataMatcher);
                } catch (Exception\StreamNotFound $e) {
                    // ignore
                    continue;
                }
            }

            $streamEvents = new MergedStreamIterator(array_keys($eventStreams), ...array_values($eventStreams));

            if ($singleHandler) {
                $this->handleStreamWithSingleHandler($streamEvents);
            } else {
                $this->handleStreamWithHandlers($streamEvents);
            }

            $this->readModel()->persist();

            if (0 === $eventCounter) {
                usleep($this->sleep);
            }

            if ($this->triggerPcntlSignalDispatch) {
                pcntl_signal_dispatch();
            }
        } while ($keepRunning && ! $this->isStopped);

        $this->status = ProjectionStatus::IDLE();  // @phpstan-ignore-line
    }

    public function stop(): void
    {
        $this->readModel()->persist();
        $this->isStopped = true;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function reset(): void
    {
        $this->streamPositions = [];

        $this->state = [];

        $this->readModel->reset();

        $callback = $this->initCallback;

        if (is_callable($callback)) {
            $result = $callback();

            if (is_array($result)) {
                $this->state = $result;
            }
        }
    }

    private function handleStreamWithSingleHandler(MergedStreamIterator $events): void
    {
        $handler = $this->handler;

        // @var Message $event
        foreach ($events as $event) {
            if ($this->triggerPcntlSignalDispatch) {
                pcntl_signal_dispatch();
            }

            $this->currentStreamName = $events->streamName();
            $this->streamPositions[$this->currentStreamName]++;
            $this->eventCounter++;

            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->readModel()->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function handleStreamWithHandlers(MergedStreamIterator $events): void
    {
        // @var Message $event
        foreach ($events as $event) {
            if ($this->triggerPcntlSignalDispatch) {
                pcntl_signal_dispatch();
            }

            $this->currentStreamName = $events->streamName();
            $this->streamPositions[$this->currentStreamName]++;

            if (! isset($this->handlers[$event->messageName()])) {
                continue;
            }

            $this->eventCounter++;

            $handler = $this->handlers[$event->messageName()];
            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            if ($this->eventCounter === $this->persistBlockSize) {
                $this->readModel()->persist();
                $this->eventCounter = 0;
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    private function createHandlerContext(?string &$streamName)
    {
        return new class ($this, $streamName) {
            private \Prooph\EventStore\Projection\ReadModelProjector $projector;

            private ?string $streamName = null;

            public function __construct(\Prooph\EventStore\Projection\ReadModelProjector $projector, ?string &$streamName)
            {
                $this->projector = $projector;
                $this->streamName = &$streamName;
            }

            public function stop(): void
            {
                $this->projector->stop();
            }

            public function readModel(): ReadModel
            {
                return $this->projector->readModel();
            }

            public function streamName(): ?string
            {
                return $this->streamName;
            }
        };
    }

    private function prepareStreamPositions(): void
    {
        $reflectionProperty = new ReflectionProperty(get_class($this->eventStore->getEcotoneEventStore()), 'streams');
        $reflectionProperty->setAccessible(true);

        $streamPositions = [];
        $streams = array_keys($reflectionProperty->getValue($this->eventStore->getEcotoneEventStore()));

        if (isset($this->query['all'])) {
            foreach ($streams as $stream) {
                if (substr($stream, 0, 1) === '$') {
                    // ignore internal streams
                    continue;
                }
                $streamPositions[$stream] = 0;
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        if (isset($this->query['categories'])) {
            foreach ($streams as $stream) {
                foreach ($this->query['categories'] as $category) {
                    if (substr($stream, 0, strlen($category) + 1) === $category . '-') {
                        $streamPositions[$stream] = 0;

                        break;
                    }
                }
            }

            $this->streamPositions = array_merge($streamPositions, $this->streamPositions);

            return;
        }

        // stream names given
        foreach ($this->query['streams'] as $stream) {
            $streamPositions[$stream] = 0;
        }

        $this->streamPositions = array_merge($streamPositions, $this->streamPositions);
    }
}
