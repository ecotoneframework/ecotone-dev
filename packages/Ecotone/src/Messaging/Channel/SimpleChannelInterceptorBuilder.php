<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class SimpleChannelInterceptorBuilder
 * @package Ecotone\Messaging\Channel
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class SimpleChannelInterceptorBuilder implements ChannelInterceptorBuilder
{
    private int $precedence;
    private string $channelName;
    private string $referenceName;

    /**
     * SimpleChannelInterceptorBuilder constructor.
     * @param int $precedence
     * @param string $channelName
     * @param string $referenceName
     */
    private function __construct(int $precedence, string $channelName, string $referenceName)
    {
        $this->precedence = $precedence;
        $this->channelName = $channelName;
        $this->referenceName = $referenceName;
    }

    /**
     * @param string $channelName
     * @param string $referenceName
     * @return SimpleChannelInterceptorBuilder
     */
    public static function create(string $channelName, string $referenceName): self
    {
        return new self(0, $channelName, $referenceName);
    }

    /**
     * @deprecated
     */
    public static function createWithDirectObject(string $channelName, object $object): self
    {
        throw new \InvalidArgumentException("Direct object is not supported anymore");
    }

    /**
     * @inheritDoc
     */
    public function relatedChannelName(): string
    {
        return $this->channelName;
    }

    /**
     * @param int $precedence
     * @return SimpleChannelInterceptorBuilder
     */
    public function withPrecedence(int $precedence): self
    {
        $this->precedence = $precedence;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPrecedence(): int
    {
        return $this->precedence;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferenceNames(): array
    {
        return $this->referenceName ? [$this->referenceName] : [];
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): ChannelInterceptor
    {
        return $this->directObject ? $this->directObject : $referenceSearchService->get($this->referenceName);
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return Reference::to($this->referenceName);
    }
}
