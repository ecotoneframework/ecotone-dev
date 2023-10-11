<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
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
    private function __construct(private int $precedence, private string $channelName, private $referenceName)
    {
    }

    public static function create(string $channelName, $referenceName): self
    {
        return new self(0, $channelName, $referenceName);
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

    public function compile(ContainerMessagingBuilder $builder): object
    {
        return \is_string($this->referenceName) ? Reference::to($this->referenceName) : $this->referenceName;
    }
}
