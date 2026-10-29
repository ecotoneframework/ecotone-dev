<?php

/*
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use BackedEnum;
use Error;
use PhpToken;
use ReflectionMethod;

final class HandlerSourceScanner
{
    private const IGNORED_NAMES = ['parent', 'class'];
    private const IGNORED_TOKENS = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_INLINE_HTML => true];
    private const UNRESOLVED = ['kind' => 'unresolved', 'value' => ''];
    private const NAME_TOKENS = [T_STRING => true, T_NAME_QUALIFIED => true, T_NAME_FULLY_QUALIFIED => true, T_NAME_RELATIVE => true, T_STATIC => true];

    /**
     * @param array<string, array{string, string}> $targets facts key => [class name, method name]
     * @return array<string, MethodSourceFacts>
     */
    public function scan(array $targets): array
    {
        $files = [];
        $methodFacts = [];
        $reflections = [];

        return $this->scanMethods($targets, [], $files, $methodFacts, $reflections);
    }

    /**
     * @param array<string, array{string, string}> $targets
     * @param array<string, bool> $visited
     * @param array<string, array{list<PhpToken>, array<int, int>, int, array<int, array{string, array<string, string>}>}> $files
     * @param array<string, MethodSourceFacts> $methodFacts
     * @param array<string, ReflectionMethod> $reflections
     * @return array<string, MethodSourceFacts>
     */
    private function scanMethods(array $targets, array $visited, array &$files, array &$methodFacts, array &$reflections): array
    {
        $facts = [];
        $methodsByFile = [];
        foreach ($targets as $key => [$className, $methodName]) {
            $methodKey = $className . '::' . $methodName;
            if (isset($methodFacts[$methodKey])) {
                $facts[$key] = $methodFacts[$methodKey];
                continue;
            }
            $method = $reflections[$methodKey] ??= new ReflectionMethod($className, $methodName);
            $fileName = $method->getFileName();
            if ($fileName === false || ! is_file($fileName) || ! is_readable($fileName) || $method->getStartLine() === false || $method->getEndLine() === false) {
                $facts[$key] = $methodFacts[$methodKey] = MethodSourceFacts::none();
                continue;
            }
            $methodsByFile[$fileName][] = [$key, $className, $method->getStartLine(), $method->getEndLine(), $method->getDeclaringClass()->getName()];
        }

        foreach ($methodsByFile as $fileName => $methods) {
            if (! isset($files[$fileName])) {
                $tokens = [];
                $firstIndexByLine = [];
                foreach (PhpToken::tokenize((string) file_get_contents($fileName)) as $token) {
                    if (isset(self::IGNORED_TOKENS[$token->id])) {
                        continue;
                    }
                    $firstIndexByLine[$token->line] ??= count($tokens);
                    $tokens[] = $token;
                }
                $contexts = $this->importContextsOf($tokens);
                $lastLine = $tokens === [] ? 0 : end($tokens)->line;
                $files[$fileName] = [$tokens, $firstIndexByLine, $lastLine, $contexts];
            }
            [$tokens, $firstIndexByLine, $lastLine, $contexts] = $files[$fileName];
            foreach ($methods as [$key, $className, $startLine, $endLine, $declaringClass]) {
                $start = $this->firstIndexAtOrAfter($firstIndexByLine, $startLine, $lastLine, count($tokens));
                $end = $this->firstIndexAtOrAfter($firstIndexByLine, $endLine + 1, $lastLine, count($tokens));
                [$namespace, $imports] = $this->contextAt($contexts, $start);
                $imports['self'] = $declaringClass;
                $facts[$key] = $methodFacts[$className . '::' . $targets[$key][1]] = $this->factsOf(array_slice($tokens, $start, $end - $start), $className, $namespace, $imports);
            }
        }

        foreach ($targets as $key => [$className, $methodName]) {
            $path = $visited + [$className . '::' . $methodName => true];
            $instantiations = $facts[$key]->instantiations;
            $calls = $facts[$key]->calls;
            $literals = $facts[$key]->stringLiterals;
            foreach ($facts[$key]->calls as $call) {
                $helperKey = $className . '::' . $call['method'];
                if (! in_array($call['receiver'], ['this', 'self', 'static'], true) || isset($path[$helperKey]) || ! method_exists($className, $call['method'])) {
                    continue;
                }
                $helper = $this->scanMethods([$helperKey => [$className, $call['method']]], $path, $files, $methodFacts, $reflections)[$helperKey];
                $bindings = $this->bindingsOf($reflections[$helperKey] ??= new ReflectionMethod($className, $call['method']), $call);
                foreach ($helper->instantiations as $instantiation) {
                    if ($instantiation['enclosingCall'] === null) {
                        $instantiation['enclosingCall'] = $call['enclosingCall'];
                        $instantiation['receiver'] = $call['receiver'];
                    } else {
                        $instantiation['receiver'] = $this->boundReceiver($instantiation['receiver'], $bindings);
                    }
                    $instantiations[] = $instantiation;
                }
                foreach ($helper->calls as $helperCall) {
                    $calls[] = $this->boundCall($helperCall, $bindings);
                }
                $literals = [...$literals, ...$helper->stringLiterals];
            }
            $facts[$key] = new MethodSourceFacts($instantiations, $calls, array_values(array_unique($literals)));
        }

        return $facts;
    }

    /**
     * @param array{arguments: array<int|string, array{kind: string, value: string}>, unresolvedFrom: int|null} $call
     * @return array<string, array{kind: string, value: string}|null>
     */
    private function bindingsOf(ReflectionMethod $helper, array $call): array
    {
        $bindings = [];
        foreach ($helper->getParameters() as $parameter) {
            $argument = $call['arguments'][$parameter->getName()] ?? $call['arguments'][$parameter->getPosition()] ?? null;
            if ($argument === null && $call['unresolvedFrom'] !== null && $parameter->getPosition() >= $call['unresolvedFrom']) {
                $argument = self::UNRESOLVED;
            }
            if ($argument === null && $parameter->isDefaultValueAvailable() && is_string($parameter->getDefaultValue())) {
                $argument = ['kind' => 'literal', 'value' => $parameter->getDefaultValue()];
            }
            $bindings[$parameter->getName()] = $argument;
        }

        return $bindings;
    }

    /**
     * @param array{receiver: string|null, method: string, enclosingCall: string|null, arguments: array<int|string, array{kind: string, value: string}>, literalArguments: list<string>, firstArgumentClass: string|null, unresolvedFrom: int|null} $call
     * @param array<string, array{kind: string, value: string}|null> $bindings
     * @return array{receiver: string|null, method: string, enclosingCall: string|null, arguments: array<int|string, array{kind: string, value: string}>, literalArguments: list<string>, firstArgumentClass: string|null, unresolvedFrom: int|null}
     */
    private function boundCall(array $call, array $bindings): array
    {
        $arguments = [];
        foreach ($call['arguments'] as $key => $argument) {
            $arguments[$key] = $argument['kind'] === 'variable' ? ($bindings[$argument['value']] ?? self::UNRESOLVED) : $argument;
        }

        return ['receiver' => $this->boundReceiver($call['receiver'], $bindings), 'arguments' => $arguments, 'literalArguments' => $this->leadingLiteralsOf($arguments)] + $call;
    }

    /**
     * @param array<string, array{kind: string, value: string}|null> $bindings
     */
    private function boundReceiver(?string $receiver, array $bindings): ?string
    {
        if ($receiver === null || ! array_key_exists($receiver, $bindings)) {
            return $receiver;
        }

        return ($bindings[$receiver]['kind'] ?? null) === 'variable' ? $bindings[$receiver]['value'] : null;
    }

    /**
     * @param array<int|string, array{kind: string, value: string}> $arguments
     * @return list<string>
     */
    private function leadingLiteralsOf(array $arguments): array
    {
        $literals = [];
        for ($position = 0; ($arguments[$position]['kind'] ?? null) === 'literal'; $position++) {
            $literals[] = $arguments[$position]['value'];
        }

        return $literals;
    }

    /**
     * @param array<int, int> $firstIndexByLine
     */
    private function firstIndexAtOrAfter(array $firstIndexByLine, int $line, int $lastLine, int $tokenCount): int
    {
        for ($current = $line; $current <= $lastLine; $current++) {
            if (isset($firstIndexByLine[$current])) {
                return $firstIndexByLine[$current];
            }
        }

        return $tokenCount;
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array<int, array{string, array<string, string>}>
     */
    private function importContextsOf(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $braceDepth = 0;
        $namespaceDepth = 0;
        $contexts = [0 => ['', []]];
        foreach ($tokens as $index => $token) {
            $id = $token->id;
            if ($id === 123 || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braceDepth++;
            } elseif ($id === 125) {
                $braceDepth--;
            } elseif ($id === T_NAMESPACE) {
                $name = $tokens[$index + 1] ?? null;
                $namespace = $name !== null && $this->isName($name) ? $name->text : '';
                $delimiter = $tokens[$index + ($namespace === '' ? 1 : 2)] ?? null;
                $namespaceDepth = $delimiter?->text === '{' ? 1 : 0;
                $imports = [];
                $contexts[$index] = [$namespace, $imports];
            } elseif ($id === T_USE && $braceDepth === $namespaceDepth && ($tokens[$index + 1] ?? null)?->text !== '(') {
                $imports = array_merge($imports, $this->importStatementAt($tokens, $index + 1));
                $contexts[$index] = [$namespace, $imports];
            }
        }

        return $contexts;
    }

    /**
     * @param array<int, array{string, array<string, string>}> $contexts
     * @return array{string, array<string, string>}
     */
    private function contextAt(array $contexts, int $index): array
    {
        $result = ['', []];
        foreach ($contexts as $position => $context) {
            if ($position > $index) {
                break;
            }
            $result = $context;
        }

        return $result;
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array<string, string>
     */
    private function importStatementAt(array $tokens, int $index): array
    {
        $imports = [];
        if (isset($tokens[$index]) && ($tokens[$index]->is(T_FUNCTION) || $tokens[$index]->is(T_CONST))) {
            return $imports;
        }
        $groupPrefix = '';
        $current = '';
        for ($i = $index; isset($tokens[$i]) && $tokens[$i]->text !== ';'; $i++) {
            $token = $tokens[$i];
            if ($this->isName($token)) {
                $current = $groupPrefix . ltrim($token->text, '\\');
                $imports[strtolower($this->shortName($current))] = $current;
            } elseif ($token->is(T_NS_SEPARATOR) || $token->text === '{') {
                $groupPrefix = rtrim($current, '\\') . '\\';
                unset($imports[strtolower($this->shortName($current))]);
            } elseif ($token->is(T_AS) && isset($tokens[$i + 1])) {
                unset($imports[strtolower($this->shortName($current))]);
                $imports[strtolower($tokens[$i + 1]->text)] = $current;
                $i++;
            }
        }

        return $imports;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param array<string, string> $imports
     */
    private function factsOf(array $tokens, string $className, string $namespace, array $imports): MethodSourceFacts
    {
        $instantiations = [];
        $calls = [];
        $literals = [];
        $enclosingCalls = [];
        $variables = [];
        $assigned = $this->assignedVariablesOf($tokens);
        $returning = false;
        foreach ($tokens as $index => $token) {
            $id = $token->id;
            if ($id === T_RETURN || $id === T_YIELD) {
                $returning = true;
            } elseif ($id === 59) {
                $returning = false;
            }
            if ($id === T_VARIABLE && ($tokens[$index + 1] ?? null)?->id === 61) {
                unset($variables[$token->text]);
                if (($tokens[$index + 2] ?? null)?->is(T_NEW) && isset($tokens[$index + 3]) && $this->isName($tokens[$index + 3])) {
                    $variables[$token->text] = $this->resolve($tokens[$index + 3], $className, $namespace, $imports);
                }
            }
            if ($id === T_NEW && isset($tokens[$index + 1]) && $this->isName($tokens[$index + 1])) {
                $resolved = $this->resolve($tokens[$index + 1], $className, $namespace, $imports);
                if ($resolved !== null) {
                    $instantiations[] = ['class' => $resolved, 'returned' => $returning, 'enclosingCall' => $this->innermostCall($enclosingCalls)['method'] ?? null, 'receiver' => $this->innermostCall($enclosingCalls)['receiver'] ?? null];
                }
            } elseif ($id === T_DOUBLE_COLON && isset($tokens[$index - 1], $tokens[$index + 1], $tokens[$index + 2]) && $this->isName($tokens[$index - 1]) && $tokens[$index + 1]->is(T_STRING) && $tokens[$index + 2]->text === '(') {
                $resolved = $this->resolve($tokens[$index - 1], $className, $namespace, $imports);
                if ($resolved !== null && $resolved !== $className) {
                    $instantiations[] = ['class' => $resolved, 'returned' => $returning, 'enclosingCall' => $this->innermostCall($enclosingCalls)['method'] ?? null, 'receiver' => $this->innermostCall($enclosingCalls)['receiver'] ?? null];
                }
            } elseif ($id === 40) {
                $call = $this->callBefore($tokens, $index);
                if ($call !== null) {
                    $argument = $tokens[$index + 1] ?? null;
                    if ($argument?->is(T_VARIABLE) && isset($variables[$argument->text]) && in_array($call['method'], ['publish', 'recordThat', 'send'], true)) {
                        array_unshift($instantiations, ['class' => $variables[$argument->text], 'returned' => false, 'enclosingCall' => $call['method'], 'receiver' => $call['receiver']]);
                    }
                    $calls[] = $call + ['enclosingCall' => $this->innermostCall($enclosingCalls)['method'] ?? null, 'receiver' => $this->innermostCall($enclosingCalls)['receiver'] ?? null] + $this->argumentsAt($tokens, $index + 1, $assigned, $className, $namespace, $imports);
                }
                $enclosingCalls[] = $call;
            } elseif ($id === 41) {
                array_pop($enclosingCalls);
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = $this->literal($token);
            }
        }

        return new MethodSourceFacts($instantiations, $calls, array_values(array_unique($literals)));
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array{receiver: string|null, method: string}|null
     */
    private function callBefore(array $tokens, int $parenthesisIndex): ?array
    {
        $name = $tokens[$parenthesisIndex - 1] ?? null;
        $operator = $tokens[$parenthesisIndex - 2] ?? null;
        if ($name === null || $operator === null || ! $name->is(T_STRING)) {
            return null;
        }
        if (! ($operator->is(T_OBJECT_OPERATOR) || $operator->is(T_NULLSAFE_OBJECT_OPERATOR) || $operator->is(T_DOUBLE_COLON))) {
            return null;
        }
        $receiver = null;
        $subject = $tokens[$parenthesisIndex - 3] ?? null;
        $subjectOperator = $tokens[$parenthesisIndex - 4] ?? null;
        $subjectOwner = $tokens[$parenthesisIndex - 5] ?? null;
        if ($operator->is(T_DOUBLE_COLON) && in_array(strtolower($subject?->text ?? ''), ['self', 'static'], true)) {
            $receiver = strtolower($subject->text);
        } elseif ($subject?->is(T_VARIABLE)) {
            $receiver = ltrim($subject->text, '$');
        } elseif ($subject?->is(T_STRING) && $subjectOperator?->is(T_OBJECT_OPERATOR) && $subjectOwner?->text === '$this') {
            $receiver = 'this.' . $subject->text;
        }

        return ['receiver' => $receiver, 'method' => $name->text];
    }

    /**
     * @param list<PhpToken> $tokens
     * @return array<string, bool>
     */
    private function assignedVariablesOf(array $tokens): array
    {
        $assigned = [];
        $body = false;
        foreach ($tokens as $index => $token) {
            $body = $body || $token->text === '{';
            if (! $body || ! $token->is(T_VARIABLE)) {
                continue;
            }
            $next = $tokens[$index + 1] ?? null;
            $previous = $tokens[$index - 1] ?? null;
            $assignedByOperator = $next !== null && ($next->text === '=' || str_ends_with($next->text, '=') && ! in_array($next->text, ['==', '===', '!=', '!==', '<=', '>=', '<=>'], true));
            $assignedByForeach = $previous !== null && ($previous->is(T_AS) || $previous->is(T_DOUBLE_ARROW) && ($tokens[$index - 3] ?? null)?->is(T_AS));
            if ($assignedByOperator || $assignedByForeach || $previous?->text === '&') {
                $assigned[$token->text] = true;
            }
        }
        foreach ($this->destructuringRangesOf($tokens) as [$open, $close]) {
            for ($index = $open; $index <= $close; $index++) {
                if ($tokens[$index]->is(T_VARIABLE)) {
                    $assigned[$tokens[$index]->text] = true;
                }
            }
        }

        return $assigned;
    }

    /**
     * @param list<PhpToken> $tokens
     * @return list<array{int, int}>
     */
    private function destructuringRangesOf(array $tokens): array
    {
        $ranges = [];
        $openers = [];
        foreach ($tokens as $index => $token) {
            if ($token->text === '[' || $token->text === '(') {
                $openers[] = $index;
            } elseif (($token->text === ']' || $token->text === ')') && $openers !== []) {
                $open = array_pop($openers);
                $before = $tokens[$open - 1] ?? null;
                $destructuring = $token->text === ']'
                    ? $before === null || ! ($before->is(T_VARIABLE) || $this->isName($before) || in_array($before->text, [']', ')', '}'], true))
                    : $before?->is(T_LIST) === true;
                $leadingIndex = $token->text === ')' ? $open - 2 : $open - 1;
                $foreachValue = ($tokens[$leadingIndex] ?? null)?->is(T_AS) === true
                    || ($tokens[$leadingIndex] ?? null)?->is(T_DOUBLE_ARROW) === true && ($tokens[$leadingIndex - 2] ?? null)?->is(T_AS) === true;
                $assignedTo = ($tokens[$index + 1] ?? null)?->text === '=' || $foreachValue;
                if ($destructuring && $assignedTo) {
                    $ranges[] = [$open, $index];
                }
            }
        }

        return $ranges;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param array<string, bool> $assigned
     * @param array<string, string> $imports
     * @return array{arguments: array<int|string, array{kind: string, value: string}>, literalArguments: list<string>, firstArgumentClass: string|null, unresolvedFrom: int|null}
     */
    private function argumentsAt(array $tokens, int $index, array $assigned, string $className, string $namespace, array $imports): array
    {
        $first = $tokens[$index] ?? null;
        if ($first?->is(T_NEW) && isset($tokens[$index + 1]) && $this->isName($tokens[$index + 1])) {
            return ['arguments' => [], 'literalArguments' => [], 'firstArgumentClass' => $this->resolve($tokens[$index + 1], $className, $namespace, $imports), 'unresolvedFrom' => 0];
        }
        $arguments = [];
        $position = 0;
        $unresolvedFrom = null;
        while (isset($tokens[$index]) && $tokens[$index]->text !== ')') {
            if ($tokens[$index]->is(T_ELLIPSIS)) {
                $unresolvedFrom = $position;
                break;
            }
            $name = null;
            if ($tokens[$index]->is(T_STRING) && ($tokens[$index + 1] ?? null)?->text === ':') {
                $name = $tokens[$index]->text;
                $index += 2;
            }
            $end = $this->argumentEndAt($tokens, $index);
            if ($end === null) {
                $unresolvedFrom = $position;
                break;
            }
            $arguments[$name ?? $position] = $this->argumentValueAt($tokens, $index, $end, $assigned, $className, $namespace, $imports);
            if ($name === null) {
                $position++;
            }
            $index = $tokens[$end]->text === ',' ? $end + 1 : $end;
        }

        return ['arguments' => $arguments, 'literalArguments' => $this->leadingLiteralsOf($arguments), 'firstArgumentClass' => null, 'unresolvedFrom' => $unresolvedFrom];
    }

    /**
     * @param list<PhpToken> $tokens
     */
    private function argumentEndAt(array $tokens, int $index): ?int
    {
        $depth = 0;
        for ($current = $index; isset($tokens[$current]); $current++) {
            $token = $tokens[$current];
            if (in_array($token->text, ['(', '[', '{'], true) || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES) || $token->is(T_ATTRIBUTE)) {
                $depth++;
            } elseif ($depth === 0 && in_array($token->text, [',', ')'], true)) {
                return $current;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
        }

        return null;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param array<string, bool> $assigned
     * @param array<string, string> $imports
     * @return array{kind: string, value: string}
     */
    private function argumentValueAt(array $tokens, int $index, int $end, array $assigned, string $className, string $namespace, array $imports): array
    {
        $token = $tokens[$index];
        if ($end === $index + 1 && $token->is(T_VARIABLE)) {
            return $token->text === '$this' || isset($assigned[$token->text]) ? self::UNRESOLVED : ['kind' => 'variable', 'value' => ltrim($token->text, '$')];
        }
        [$literal, $literalEnd] = $this->literalArgumentAt($tokens, $index, $className, $namespace, $imports);

        return $literal !== null && $literalEnd === $end - 1 ? ['kind' => 'literal', 'value' => $literal] : self::UNRESOLVED;
    }

    private function literalArgumentAt(array $tokens, int $index, string $className, string $namespace, array $imports): array
    {
        $token = $tokens[$index];
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return [$this->literal($token), $index];
        }
        if (! $this->isName($token) || ! ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON) || ! ($tokens[$index + 2] ?? null)?->is(T_STRING)) {
            return [null, $index];
        }
        $class = $this->resolve($token, $className, $namespace, $imports);
        $constant = $class . '::' . $tokens[$index + 2]->text;
        if ($class === null || ! defined($constant)) {
            return [null, $index];
        }
        try {
            $value = constant($constant);
        } catch (Error) {
            return [null, $index];
        }
        $end = $index + 2;
        if ($value instanceof BackedEnum && ($tokens[$end + 1] ?? null)?->is(T_OBJECT_OPERATOR) && ($tokens[$end + 2] ?? null)?->text === 'value') {
            $value = $value->value;
            $end += 2;
        }

        return [is_string($value) ? $value : null, $end];
    }

    /**
     * @param list<array{receiver: string|null, method: string}|null> $enclosingCalls
     * @return array{receiver: string|null, method: string}|null
     */
    private function innermostCall(array $enclosingCalls): ?array
    {
        for ($i = count($enclosingCalls) - 1; $i >= 0; $i--) {
            if ($enclosingCalls[$i] !== null) {
                return $enclosingCalls[$i];
            }
        }

        return null;
    }

    private function isName(PhpToken $token): bool
    {
        return isset(self::NAME_TOKENS[$token->id]);
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolve(PhpToken $token, string $className, string $namespace, array $imports): ?string
    {
        $name = $token->text;
        if (strtolower($name) === 'self') {
            return $imports['self'] ?? $className;
        }
        if (strtolower($name) === 'static') {
            return $className;
        }
        if (in_array(strtolower($name), self::IGNORED_NAMES, true)) {
            return null;
        }
        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name, '\\');
        }
        if ($token->is(T_NAME_RELATIVE)) {
            return $namespace . '\\' . substr($name, strlen('namespace\\'));
        }
        $firstSegment = strtolower(explode('\\', $name)[0]);
        if (isset($imports[$firstSegment])) {
            $rest = substr($name, strlen(explode('\\', $name)[0]));

            return $imports[$firstSegment] . $rest;
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    private function literal(PhpToken $token): string
    {
        $text = substr($token->text, 1, -1);
        if ($token->text[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $text);
        }

        return preg_replace_callback('~\\\\(?:u\{([0-9a-fA-F]+)\}|[nrtvef\\\\$"]|[0-7]{1,3}|x[0-9a-fA-F]{1,2})~', function (array $match): string {
            if (($match[1] ?? '') !== '') {
                $codePoint = hexdec($match[1]);
                $escaped = $codePoint <= 0xffff
                    ? sprintf('"\u%04x"', $codePoint)
                    : sprintf('"\u%04x\u%04x"', 0xd800 + (($codePoint - 0x10000) >> 10), 0xdc00 + (($codePoint - 0x10000) & 0x3ff));

                return json_decode($escaped, flags: JSON_THROW_ON_ERROR);
            }

            return $match[0] === '\e' ? "\x1b" : stripcslashes($match[0]);
        }, $text);
    }

    private function shortName(string $name): string
    {
        $segments = explode('\\', $name);

        return end($segments);
    }
}
