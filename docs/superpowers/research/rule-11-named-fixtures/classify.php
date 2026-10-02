<?php
$files = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$out = [];
foreach ($files as $file) {
    $tokens = PhpToken::tokenize(file_get_contents($file));
    $n = count($tokens);
    $classes = [];
    $depth = 0;
    $current = null;
    $pendingAttrs = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->is(T_ATTRIBUTE)) {
            $j = $i + 1;
            while ($tokens[$j]->is(T_WHITESPACE)) $j++;
            $name = $tokens[$j]->text;
            $name = substr($name, strrpos('\\' . $name, '\\'));
            if ($current !== null) {
                $classes[$current]['attrs'][] = $name;
            } else {
                $pendingAttrs[] = $name;
            }
        }
        if ($t->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT])) {
            $k = $i - 1;
            while ($k >= 0 && ($tokens[$k]->is([T_WHITESPACE, T_FINAL, T_ABSTRACT, T_READONLY, T_COMMENT, T_DOC_COMMENT]) || $tokens[$k]->text === ']' )) $k--;
            $prev = $tokens[$k] ?? null;
            if ($prev && $prev->is(T_DOUBLE_COLON)) continue;
            $isNew = false;
            $k2 = $i - 1;
            while ($k2 >= 0 && $tokens[$k2]->is(T_WHITESPACE)) $k2--;
            for ($m = $i - 1; $m >= max(0, $i - 200); $m--) {
                if ($tokens[$m]->is(T_NEW)) { $isNew = true; break; }
                if ($tokens[$m]->text === ';' || $tokens[$m]->text === '{' || $tokens[$m]->text === '}') break;
            }
            if ($depth === 0 && !$isNew) {
                $j = $i + 1;
                while ($tokens[$j]->is(T_WHITESPACE)) $j++;
                $name = $tokens[$j]->text;
                $ext = '';
                for ($m = $j; $tokens[$m]->text !== '{'; $m++) $ext .= $tokens[$m]->text;
                $classes[$name] = ['kind' => $t->text, 'attrs' => $pendingAttrs, 'decl' => trim($ext)];
                $current = $name;
                $pendingAttrs = [];
            }
        }
        if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) $depth++;
        if ($t->text === '}') { $depth--; if ($depth === 0) $current = null; }
        if ($t->text === ';' && $current === null) $pendingAttrs = [];
    }
    $named = array_filter($classes, fn ($c, $name) => !preg_match('/extends\s+\S*(TestCase|Test)$/', $c['decl']), ARRAY_FILTER_USE_BOTH);
    if ($named) $out[$file] = $named;
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
