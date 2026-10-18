<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Messaging\Config\Flow;

use PhpToken;
use ReflectionMethod;

final class HandlerSourceScanner
{
    private const IGNORED_NAMES = ['parent', 'class'];
    private const IGNORED_TOKENS = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_INLINE_HTML => true];
    private const NAME_TOKENS = [T_STRING => true, T_NAME_QUALIFIED => true, T_NAME_FULLY_QUALIFIED => true, T_NAME_RELATIVE => true, T_STATIC => true];

    /**
     * @param array<string, array{string, string}> $targets facts key => [class name, method name]
     * @return array<string, MethodSourceFacts>
     */
    public function scan(array $targets): array
    {
        $facts = [];
        $methodsByFile = [];
        foreach ($targets as $key => [$className, $methodName]) {
            $method = new ReflectionMethod($className, $methodName);
            $fileName = $method->getFileName();
            if ($fileName === false || $method->getStartLine() === false || $method->getEndLine() === false) {
                $facts[$key] = MethodSourceFacts::none();
                continue;
            }
            $methodsByFile[$fileName][] = [$key, $className, $method->getStartLine(), $method->getEndLine()];
        }

        foreach ($methodsByFile as $fileName => $methods) {
            $tokens = [];
            $firstIndexByLine = [];
            foreach (PhpToken::tokenize((string) file_get_contents($fileName)) as $token) {
                if (isset(self::IGNORED_TOKENS[$token->id])) {
                    continue;
                }
                $firstIndexByLine[$token->line] ??= count($tokens);
                $tokens[] = $token;
            }
            [$namespace, $imports] = $this->importsOf($tokens);
            $lastLine = $tokens === [] ? 0 : end($tokens)->line;
            foreach ($methods as [$key, $className, $startLine, $endLine]) {
                $start = $this->firstIndexAtOrAfter($firstIndexByLine, $startLine, $lastLine, count($tokens));
                $end = $this->firstIndexAtOrAfter($firstIndexByLine, $endLine + 1, $lastLine, count($tokens));
                $facts[$key] = $this->factsOf(array_slice($tokens, $start, $end - $start), $className, $namespace, $imports);
            }
        }

        return $facts;
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
     * @return array{string, array<string, string>}
     */
    private function importsOf(array $tokens): array
    {
        $namespace = '';
        $imports = [];
        $braceDepth = 0;
        foreach ($tokens as $index => $token) {
            if ($token->text === '{') {
                $braceDepth++;
            } elseif ($token->text === '}') {
                $braceDepth--;
            } elseif ($token->is(T_NAMESPACE) && isset($tokens[$index + 1]) && $this->isName($tokens[$index + 1])) {
                $namespace = $tokens[$index + 1]->text;
            } elseif ($token->is(T_USE) && $braceDepth === 0) {
                $imports = array_merge($imports, $this->importStatementAt($tokens, $index + 1));
            }
        }

        return [$namespace, $imports];
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
        foreach ($tokens as $index => $token) {
            $id = $token->id;
            if ($id === T_NEW && isset($tokens[$index + 1]) && $this->isName($tokens[$index + 1])) {
                $resolved = $this->resolve($tokens[$index + 1], $className, $namespace, $imports);
                if ($resolved !== null) {
                    $instantiations[] = ['class' => $resolved, 'enclosingCall' => $this->innermostCall($enclosingCalls)];
                }
            } elseif ($id === T_DOUBLE_COLON && isset($tokens[$index - 1], $tokens[$index + 1], $tokens[$index + 2]) && $this->isName($tokens[$index - 1]) && $tokens[$index + 1]->is(T_STRING) && $tokens[$index + 2]->text === '(') {
                $resolved = $this->resolve($tokens[$index - 1], $className, $namespace, $imports);
                if ($resolved !== null && $resolved !== $className) {
                    $instantiations[] = ['class' => $resolved, 'enclosingCall' => $this->innermostCall($enclosingCalls)];
                }
            } elseif ($id === 40) {
                $call = $this->callBefore($tokens, $index);
                $enclosingCalls[] = $call['method'] ?? null;
                if ($call !== null) {
                    $calls[] = $call + $this->argumentsAt($tokens, $index + 1, $className, $namespace, $imports);
                }
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
        if ($subject?->is(T_VARIABLE)) {
            $receiver = ltrim($subject->text, '$');
        } elseif ($subject?->is(T_STRING) && $subjectOperator?->is(T_OBJECT_OPERATOR) && $subjectOwner?->text === '$this') {
            $receiver = 'this.' . $subject->text;
        }

        return ['receiver' => $receiver, 'method' => $name->text];
    }

    /**
     * @param list<PhpToken> $tokens
     * @param array<string, string> $imports
     * @return array{literalArguments: list<string>, firstArgumentClass: string|null}
     */
    private function argumentsAt(array $tokens, int $index, string $className, string $namespace, array $imports): array
    {
        $first = $tokens[$index] ?? null;
        if ($first?->is(T_NEW) && isset($tokens[$index + 1]) && $this->isName($tokens[$index + 1])) {
            return ['literalArguments' => [], 'firstArgumentClass' => $this->resolve($tokens[$index + 1], $className, $namespace, $imports)];
        }
        $literals = [];
        while (isset($tokens[$index]) && $tokens[$index]->is(T_CONSTANT_ENCAPSED_STRING)) {
            $literals[] = $this->literal($tokens[$index]);
            if (($tokens[$index + 1] ?? null)?->text !== ',') {
                break;
            }
            $index += 2;
        }

        return ['literalArguments' => $literals, 'firstArgumentClass' => null];
    }

    /**
     * @param list<string|null> $enclosingCalls
     */
    private function innermostCall(array $enclosingCalls): ?string
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
        if (in_array(strtolower($name), ['self', 'static'], true)) {
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
        return stripcslashes(substr($token->text, 1, -1));
    }

    private function shortName(string $name): string
    {
        $segments = explode('\\', $name);

        return end($segments);
    }
}
