<?php

declare(strict_types=1);

namespace App\Doctor;

final readonly class DoctorCheck
{
    private function __construct(
        public string $name,
        public string $status,
        public string $message,
        public ?string $fix,
    ) {
    }

    public static function ok(string $name, string $message): self
    {
        return new self($name, 'ok', $message, null);
    }

    public static function info(string $name, string $message): self
    {
        return new self($name, 'info', $message, null);
    }

    public static function fail(string $name, string $message, string $fix): self
    {
        return new self($name, 'fail', $message, $fix);
    }

    public function failed(): bool
    {
        return $this->status === 'fail';
    }

    /**
     * @return array{name: string, status: string, message: string, fix?: string}
     */
    public function toArray(): array
    {
        return array_filter(['name' => $this->name, 'status' => $this->status, 'message' => $this->message, 'fix' => $this->fix], static fn (?string $value) => $value !== null);
    }
}
