<?php

declare(strict_types=1);

namespace Ecotone\DataProtection\Conversion;

use const LIBXML_NOCDATA;

use function simplexml_load_string;

use SimpleXMLElement;

/**
 * licence Enterprise
 */
class XmlHelper
{
    public static function arrayToXml(array $array): string
    {
        $xml = new SimpleXMLElement('<result/>');

        self::addToXml($array, $xml);

        return $xml->asXML();
    }

    public static function xmlToArray(string $xmlString): array
    {
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

        return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function addToXml(array $array, SimpleXMLElement $xml): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $label = $xml->addChild($key);
                self::addToXml($value, $label);
            } else {
                $xml->addChild($key, $value);
            }
        }
    }
}
