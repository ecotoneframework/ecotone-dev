<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Conversion;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ReferenceSearchService;

/**
 * Class ConverterReferenceBuilder
 * @package Ecotone\Messaging\Conversion
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class ConverterReferenceBuilder implements ConverterBuilder
{
    private string $referenceName;

    /**
     * ConverterReferenceBuilder constructor.
     * @param string $referenceName
     */
    private function __construct(string $referenceName)
    {
        $this->referenceName = $referenceName;
    }

    /**
     * @param string $referenceName
     * @return ConverterReferenceBuilder
     */
    public static function create(string $referenceName): self
    {
        return new self($referenceName);
    }

    /**
     * @inheritDoc
     */
    public function build(ReferenceSearchService $referenceSearchService): Converter
    {
        return $referenceSearchService->get($this->referenceName);
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return new Reference($this->referenceName);
    }

    /**
     * @inheritDoc
     */
    public function getRequiredReferences(): array
    {
        return [$this->referenceName];
    }
}
