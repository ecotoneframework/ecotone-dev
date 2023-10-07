<?php

namespace Monorepo\Benchmark;


use Monorepo\Benchmark\Fixtures\ClassWithAnnotation;
use Monorepo\Benchmark\Fixtures\Endpoint;

class ReflectionAttributeBench
{
    private const ITERATIONS = 1000;
    public function bench_reflection() {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $reflectionClass = new \ReflectionClass(ClassWithAnnotation::class);
            $attribute = $reflectionClass->getAttributes()[0];
            $a = $attribute->newInstance();
        }
    }

    public function bench_dump() {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = new Endpoint("endpointId1", new Endpoint("endpointId2"));
        }
    }

    public function bench_var_exporter() {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $a = \Symfony\Component\VarExporter\Internal\Hydrator::hydrate(
                $o = [
                    clone(($p = &\Symfony\Component\VarExporter\Internal\Registry::$prototypes)[Endpoint::class] ?? \Symfony\Component\VarExporter\Internal\Registry::p(Endpoint::class)),
                    clone $p[Endpoint::class],
                ],
                null,
                [
                    'stdClass' => [
                        'endpointId' => [
                            'endpointId1',
                            'endpointId2',
                        ],
                        'endpoint' => [
                            $o[1],
                            null,
                        ],
                    ],
                ],
                $o[0],
                []
            );
        }
    }
}