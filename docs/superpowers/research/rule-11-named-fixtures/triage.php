<?php
$named = json_decode(file_get_contents($argv[1]), true);
$msg = ['EventTag', 'NamedEvent', 'TargetIdentifier', 'Revision', 'TargetVersion'];
$summary = ['files' => 0, 'files_with_free' => 0, 'free' => 0, 'forced' => 0];
foreach ($named as $file => $classes) {
    $behavioural = array_filter($classes, fn ($c) => array_diff(array_unique($c['attrs']), $msg) !== []);
    if (!$behavioural) continue;
    $summary['files']++;
    $tokens = PhpToken::tokenize(file_get_contents($file));
    $n = count($tokens);
    $sig = fn ($i, $dir) => (function () use ($tokens, $i, $dir, $n) { $j = $i + $dir; while ($j >= 0 && $j < $n && $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) $j += $dir; return $j; })();
    $attrDepth = 0; $bracket = 0; $inAttr = [];
    $ctx = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->is(T_ATTRIBUTE)) { $inAttr[] = $bracket; $bracket++; continue; }
        if ($t->text === '[') $bracket++;
        if ($t->text === ']') { $bracket--; if ($inAttr && end($inAttr) === $bracket) array_pop($inAttr); }
        if (!$t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) continue;
        $short = substr($t->text, strrpos('\\' . $t->text, '\\'));
        if (!isset($classes[$short])) continue;
        $p = $sig($i, -1); $nx = $sig($i, 1);
        $prev = $tokens[$p]; $next = $tokens[$nx];
        if ($prev->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT])) continue;
        $kind = null;
        if ($inAttr) $kind = 'attr-arg';
        elseif ($prev->is([T_EXTENDS, T_IMPLEMENTS]) || ($prev->text === ',' && (function () use ($tokens, $p) { for ($k = $p; $k > 0; $k--) { if ($tokens[$k]->is([T_IMPLEMENTS, T_EXTENDS])) return true; if (in_array($tokens[$k]->text, ['{', ';', '(', ')'], true)) return false; } return false; })())) $kind = 'extends';
        elseif ($next->is(T_VARIABLE) || $next->is(T_ELLIPSIS) || $prev->text === ':' && !$next->is(T_DOUBLE_COLON) || $prev->text === '?' || $prev->text === '|' || $prev->text === '&') $kind = 'type';
        elseif ($next->is(T_DOUBLE_COLON)) {
            $const = false;
            for ($k = $i; $k > 0; $k--) { if ($tokens[$k]->is(T_CONST)) { $const = true; break; } if (in_array($tokens[$k]->text, [';', '{', '}'], true)) break; }
            $kind = $const ? 'const-expr' : 'runtime-static';
        } elseif ($prev->is(T_NEW)) $kind = 'new';
        elseif ($prev->is(T_INSTANCEOF)) $kind = 'instanceof';
        else $kind = 'other:' . $prev->text . '_' . $next->text;
        $ctx[$short][$kind] = ($ctx[$short][$kind] ?? 0) + 1;
    }
    $lines = [];
    $free = 0;
    foreach ($behavioural as $name => $c) {
        $k = $ctx[$name] ?? [];
        $forced = array_intersect(array_keys($k), ['attr-arg', 'type', 'extends', 'const-expr']); if ($c['kind'] !== 'class') $forced[] = 'not-a-class:' . $c['kind'];
        $tag = $forced ? 'FORCED' : 'FREE';
        if ($forced) $summary['forced']++; else { $summary['free']++; $free++; }
        $lines[] = sprintf('  %-6s %s [%s] %s', $tag, $name, implode(',', array_values(array_diff(array_unique($c['attrs']), $msg))), json_encode($k));
    }
    if ($free) $summary['files_with_free']++;
    echo ($free ? '* ' : '  ') . $file . "\n" . implode("\n", $lines) . "\n";
}
echo json_encode($summary), "\n";
