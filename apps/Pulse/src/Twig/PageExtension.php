<?php

declare(strict_types=1);

namespace App\Twig;

use App\Home\Severity;
use App\Ui\Concept;
use App\Ui\ConceptGlossary;
use App\Ui\Status;
use App\Ui\StatusView;
use JsonException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class PageExtension extends AbstractExtension
{
    public function __construct(private readonly ConceptGlossary $glossary)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('status_view', $this->statusView(...)),
            new TwigFunction('concept', $this->concept(...)),
            new TwigFunction('concept_terms', $this->glossary->terms(...)),
        ];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('pretty_json', $this->prettyJson(...))];
    }

    public function statusView(StatusView|Status|Severity|string|null $status, ?string $reason = null): StatusView
    {
        return StatusView::of($status, $reason);
    }

    public function concept(string $term): Concept
    {
        return $this->glossary->concept($term);
    }

    public function prettyJson(?string $content): string
    {
        $content ??= '';
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $content;
        }
        if (! is_array($decoded) && ! is_object($decoded)) {
            return $content;
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $content;
    }
}
