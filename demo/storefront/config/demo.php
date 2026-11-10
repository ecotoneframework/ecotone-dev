<?php

return [
    'amqp_dsn' => env('AMQP_DSN', 'amqp://guest:guest@rabbitmq:5672'),
    'pulse_url' => env('DEMO_PULSE_URL', 'http://localhost:8080'),
    'jaeger_url' => env('DEMO_JAEGER_URL', 'http://localhost:16686'),
    'database_url' => env('DEMO_DATABASE_URL', 'http://localhost:8081'),
    'ticketing_url' => env('DEMO_TICKETING_URL', 'http://localhost:8001'),
    'rush_buyer_deadline_seconds' => (int) env('RUSH_BUYER_DEADLINE_SECONDS', 120),
    'rush_deadline_seconds' => (int) env('RUSH_DEADLINE_SECONDS', 600),
];
