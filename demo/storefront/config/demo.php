<?php

return [
    'amqp_dsn' => env('AMQP_DSN', 'amqp://guest:guest@rabbitmq:5672'),
    'otlp_endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://jaeger:4318'),
    'pulse_url' => env('DEMO_PULSE_URL', 'http://localhost:8080'),
    'jaeger_url' => env('DEMO_JAEGER_URL', 'http://localhost:16686'),
    'rush_buyer_deadline_seconds' => (int) env('RUSH_BUYER_DEADLINE_SECONDS', 120),
    'rush_deadline_seconds' => (int) env('RUSH_DEADLINE_SECONDS', 600),
];
