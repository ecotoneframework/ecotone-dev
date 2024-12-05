<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\SQL\Helpers;

class PdoDsn
{
    private string $dsn;

    /**
     * Mysql, pgsql, sqlite, etc.
     */
    private string $protocol;

    private array $parameters = [];

    public function __construct(string $dsn)
    {
        $this->dsn = $dsn;
        $this->parseDsn($dsn);
    }

    private function parseDsn(string $dsn): void
    {
        $dsn = trim($dsn);

        if (strpos($dsn, ':') === false) {
            throw new \LogicException(sprintf('The DSN is invalid. It does not have scheme separator ":".'));
        }

        list($prefix, $dsnWithoutPrefix) = preg_split('#\s*:\s*#', $dsn, 2);

        $this->protocol = $prefix;

        if (preg_match('/^[a-z\d]+$/', strtolower($prefix)) == false) {
            throw new \LogicException('The DSN is invalid. Prefix contains illegal symbols.');
        }

        $dsnElements = preg_split('#\s*\;\s*#', $dsnWithoutPrefix);

        $elements = [];
        foreach ($dsnElements as $element) {
            if (strpos($dsnWithoutPrefix, '=') !== false) {
                list($key, $value) = preg_split('#\s*=\s*#', $element, 2);
                $elements[$key] = $value;
            } else {
                $elements = [
                    $dsnWithoutPrefix,
                ];
            }
        }
        $this->parameters = $elements;
    }

    public function getDsn(): string
    {
        return $this->dsn;
    }

    public function getProtocol(): ?string
    {
        return $this->protocol;
    }

    public function getDatabase(): ?string
    {
        return $this->getAttribute('dbname') ?? null;
    }

    public function getHost(): string
    {
        return $this->getAttribute('host');
    }

    public function getPort(): string
    {
        return $this->getAttribute('port');
    }

    public function getCharset(): string
    {
        return $this->getAttribute('charset');
    }

    /**
     * Get an attribute from the $attributes array.
     */
    private function getAttribute(string $key): mixed
    {
        if (isset($this->parameters[$key])) {
            return $this->parameters[$key];
        }

        return null;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }
}