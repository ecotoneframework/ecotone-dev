<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(404)]
final class UnknownCatalogueNode extends InvalidArgumentException
{
    /**
     * @param list<string> $available
     */
    public static function ofService(string $service, array $available): self
    {
        sort($available);

        return new self(sprintf('Cannot open a catalogue page in "%s": it is not among the services that answered Pulse. Choose one of: %s, or search the catalogue for the name.', $service, $available === [] ? 'none has answered yet' : implode(', ', $available)));
    }

    public static function ofNode(string $service, string $nodeId): self
    {
        return new self(sprintf('%s reports no node "%s", so its handlers may have changed since the link was made. Search the catalogue for the name, or pass the id of a node as ?id=, for example ?id=message:order.place.', $service, $nodeId));
    }

    public static function withoutId(string $service): self
    {
        return new self(sprintf('This page needs the id of a node of %s as ?id=, for example ?id=message:order.place. Search the catalogue for the name to get the link.', $service));
    }
}
