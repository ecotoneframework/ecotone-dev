<?php

declare(strict_types=1);

namespace App\Graph;

final class MermaidText
{
    private const ENTITIES = [
        '#' => '#35;', '&' => '#38;', '<' => '#60;', '>' => '#62;', '"' => '#34;', '|' => '#124;', '\\' => '#92;',
        '[' => '#91;', ']' => '#93;', '(' => '#40;', ')' => '#41;', '{' => '#123;', '}' => '#125;', ';' => '#59;', '`' => '#96;',
    ];

    public static function escape(string $text): string
    {
        $withoutControlCharacters = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);

        return strtr($withoutControlCharacters, self::ENTITIES);
    }
}
