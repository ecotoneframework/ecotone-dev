<?php

declare(strict_types=1);

namespace App\Snapshot;

final readonly class ServiceScope
{
    private const SEPARATOR = '@';

    private function __construct(public string $service, public ?string $tenant)
    {
    }

    public static function ofService(string $service): self
    {
        return new self($service, null);
    }

    public static function ofTenant(string $service, string $tenant): self
    {
        return new self($service, $tenant);
    }

    public static function fromKey(string $key): self
    {
        $separatorAt = strpos($key, self::SEPARATOR);

        return $separatorAt === false ? new self($key, null) : new self(substr($key, 0, $separatorAt), substr($key, $separatorAt + 1));
    }

    public function key(): string
    {
        return $this->tenant === null ? $this->service : $this->service . self::SEPARATOR . $this->tenant;
    }

    public function tenantColumn(): string
    {
        return $this->tenant ?? '';
    }
}
