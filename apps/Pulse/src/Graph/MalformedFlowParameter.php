<?php

declare(strict_types=1);

namespace App\Graph;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

#[WithHttpStatus(400)]
final class MalformedFlowParameter extends InvalidArgumentException
{
    private const SHAPES = [
        'focus' => ['focus={service}:{node id}', 'to see every service'],
        'fold' => ['fold=channels', 'to draw every channel'],
        'find' => ['find={node name}', 'to stop searching'],
        'trace' => ['trace={trace id}', 'to stop highlighting a trace'],
        'service' => ['service={service}', 'to see all services'],
        'view' => ['view=overview or view=detail', 'to let the size of the system decide'],
        'collapse' => ['collapse[]={service}', 'to draw every service in detail'],
        'services' => ['services[]={service}', 'to show every service'],
    ];

    public static function oneValueGivenAList(string $name): self
    {
        [$shape, $without] = self::SHAPES[$name];

        return new self(sprintf('Cannot read %1$s[]: %1$s takes one value, %2$s. Send it once as %2$s, or remove %1$s %3$s.', $name, $shape, $without));
    }

    public static function listGivenOneValue(string $name, string $value): self
    {
        [$shape, $without] = self::SHAPES[$name];

        return new self(sprintf('Cannot read %1$s=%2$s: %1$s takes a list, one %3$s per service. Write %1$s[]=%2$s, or remove %1$s %4$s.', $name, $value, $shape, $without));
    }

    public static function listHoldingAList(string $name, string $example): self
    {
        [$shape, $without] = self::SHAPES[$name];

        return new self(sprintf('Cannot read %1$s[]: each entry is one value, %2$s. Write %1$s[]=%3$s once per service, or remove %1$s %4$s.', $name, $shape, $example, $without));
    }

    public static function listHoldingAnEmptyValue(string $name): self
    {
        [$shape, $without] = self::SHAPES[$name];

        return new self(sprintf('Cannot read %1$s[]: one of its values is empty. Name a service in each %2$s, or remove the empty %1$s[] %3$s.', $name, $shape, $without));
    }

    public static function focusOverriding(string $focus, string $setting, string $parameter, string $chosen): self
    {
        return new self(sprintf('Cannot follow "%s" and show %s at once: following draws the path of the node across whichever services it crosses. Remove %s to follow the node, or remove focus to choose %s.', $focus, $setting, $parameter, $chosen));
    }

    public static function focusWithoutServiceAndNode(string $focus): self
    {
        return new self(sprintf('Cannot follow "%s": focus takes {service}:{node id}, the service and the node joined by a colon. Click the node on the flow graph, type its name into Find, or remove focus %s.', $focus, self::SHAPES['focus'][1]));
    }
}
