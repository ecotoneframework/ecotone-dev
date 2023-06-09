<?php

namespace Ecotone\SymfonyBundle\Proxy;

use Laminas\Code\Generator\ClassGenerator;
use Laminas\Code\Generator\FileGenerator;
use Laminas\Code\Generator\MethodGenerator;
use Laminas\Code\Generator\ParameterGenerator;
use Laminas\Code\Generator\PropertyGenerator;
use Laminas\Code\Generator\TypeGenerator;
use Psr\Container\ContainerInterface;

class ProxyFactory
{
    public function __construct(private string $namespace)
    {
    }

    public function getClassNameFor(string $interfaceName): string
    {
        return \str_replace("\\", "_", $interfaceName);
    }

    public function getFullClassNameFor(string $interfaceName): string
    {
        return $this->namespace . "\\" . $this->getClassNameFor($interfaceName);
    }

    public function generateProxyFor(string $interfaceName): string
    {
        $className = $this->getClassNameFor($interfaceName);
        $classGenerator = new ClassGenerator($className, $this->namespace);
        $classGenerator->setImplementedInterfaces([$interfaceName]);
        $gatewaysParameter = new ParameterGenerator("gateways", ContainerInterface::class);
        $gatewaysProperty = new PropertyGenerator("gateways", null, PropertyGenerator::FLAG_PRIVATE);
        $gatewaysProperty->omitDefaultValue(true);
        $classGenerator->addPropertyFromGenerator($gatewaysProperty);
        $classGenerator->addMethod("__construct", [
            $gatewaysParameter
        ], MethodGenerator::FLAG_PUBLIC, '$this->gateways = $gateways;');

        $reflectionClass = new \ReflectionClass($interfaceName);
        foreach ($reflectionClass->getMethods() as $method) {
            $methodGenerator = new MethodGenerator($method->getName());
            $methodGenerator->setReturnType($method->getReturnType()?->getName());
            $methodGenerator->setParameters(array_map(function(\ReflectionParameter $parameter) {
                return new ParameterGenerator($parameter->getName(), $parameter->getType()?->getName(), $parameter->isOptional() ? $parameter->getDefaultValue() : null);
            }, $method->getParameters()));
            $return = $method->getReturnType()?->getName() === 'void' ? "" : "return ";
            $parameterNames = array_map(function(\ReflectionParameter $parameter) {
                return '$' . $parameter->getName();
            }, $method->getParameters());
            $executeCallParameters = implode(", ", $parameterNames);
            $methodGenerator->setBody("$return\$this->gateways->get('{$method->getName()}')->execute([$executeCallParameters]);");
            $classGenerator->addMethodFromGenerator($methodGenerator);
        }

        $file = new FileGenerator();
        $file->setClass($classGenerator);

        return $file->generate();
    }
}