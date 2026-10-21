<?php

declare(strict_types=1);

namespace App\Storage;

final readonly class InternalTables
{
    public const SERVICE_RECORD = 'pulse_service_record';
    public const QUERY_RECORD = 'pulse_query_record';
    public const QUERY_POINTER = 'pulse_query_pointer';
    public const HEARTBEAT = 'pulse_heartbeat';

    /**
     * @return list<string>
     */
    public static function createStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS ' . self::SERVICE_RECORD . ' (service TEXT NOT NULL PRIMARY KEY, record TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS ' . self::QUERY_RECORD . ' (service TEXT NOT NULL, query_key TEXT NOT NULL, record TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY (service, query_key))',
            'CREATE TABLE IF NOT EXISTS ' . self::QUERY_POINTER . ' (query_id TEXT NOT NULL PRIMARY KEY, service TEXT NOT NULL, query_key TEXT NOT NULL, expires_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS ' . self::HEARTBEAT . ' (process TEXT NOT NULL PRIMARY KEY, beat_at INTEGER NOT NULL)',
        ];
    }
}
