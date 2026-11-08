<?php
/*
 * licence Apache-2.0
 */

declare(strict_types=1);

const CHECKER = __DIR__ . '/check-agent-skills.php';

$cases = [
    'a valid tree passes' => [fn (string $root) => null, null],
    'AGENTS.md over 200 characters' => [fn (string $root) => write($root, 'AGENTS.md', str_repeat('x', 200) . ' .agents/skills/framework-orientation'), 'over the 200-character limit'],
    'AGENTS.md missing' => [fn (string $root) => unlink($root . '/AGENTS.md'), 'AGENTS.md is missing'],
    'AGENTS.md without the pointer' => [fn (string $root) => write($root, 'AGENTS.md', 'Hello.'), 'does not point to .agents/skills/framework-orientation'],
    'CLAUDE.md copied instead of linked' => [fn (string $root) => replaceLinkWithFile($root, 'CLAUDE.md'), 'CLAUDE.md must be a symlink'],
    '.claude/skills dangling' => [fn (string $root) => exec('rm -rf ' . escapeshellarg($root . '/.agents')), '.claude/skills must be a symlink to an existing'],
    'skill without SKILL.md' => [fn (string $root) => mkdir($root . '/.agents/skills/framework-empty'), 'framework-empty/SKILL.md is missing'],
    'name differs from directory' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, 'name: framework-demo', 'name: other'), "frontmatter 'name' must be 'framework-demo'"],
    'description without Use when' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, 'Use when demoing', 'For demoing'), "'Use when ...' sentence"],
    'no Applies when' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '## Applies when', '## Scope'), "no '## Applies when' section"],
    'no Rules section' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '## Rules', '## Notes'), "no '## Rules' section"],
    'malformed rule heading' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '### 1. Demo rule', '### 1 Demo rule'), 'is not a rule heading'],
    'rule without Expected' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '- **Expected:**', '- Expected:'), "has no '- **Expected:**' line"],
    'rule without Verified by' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '- **Verified by:** `eval:demo-case`', ''), "has no '- **Verified by:**' line"],
    'duplicate rule id' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '### 2. Second rule', '### 1. Second rule'), 'reuses id 1'],
    'unknown check id' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '`check:phpstan`', '`check:psalm`'), 'unknown check `check:psalm`'],
    'unknown verifier kind' => [fn (string $root) => edit($root, FRAMEWORK_SKILL, '`check:phpstan`', '`test:ghost`'), 'unknown verifier kind'],
    'eval without the rule grader' => [fn (string $root) => unlink($root . '/.agents/evals/framework-demo/demo-case/graders/rule-1.md'), 'graders/rule-1.md does not exist'],
    'orphan grader' => [fn (string $root) => write($root, '.agents/evals/framework-demo/demo-case/graders/rule-9.md', "---\ntype: llm\n---\nPASS if x.\n"), 'grades a rule that does not cite'],
    'case no rule cites' => [fn (string $root) => copyCase($root, 'other-case'), 'is not named by any rule'],
    'case without skill-loaded grader' => [fn (string $root) => unlink($root . '/.agents/evals/framework-demo/demo-case/graders/skill-loaded.md'), 'has no graders/skill-loaded.md'],
    'case without prompt' => [fn (string $root) => unlink($root . '/.agents/evals/framework-demo/demo-case/prompt.md'), 'has no prompt.md'],
    'broken link' => [fn (string $root) => append($root, FRAMEWORK_SKILL, "\n[x](references/missing.md)\n"), 'links to references/missing.md, which does not exist'],
    'broken anchor' => [fn (string $root) => append($root, FRAMEWORK_SKILL, "\n[x](references/detail.md#nowhere)\n"), 'no heading in'],
    'framework skill linking into docs/' => [fn (string $root) => write($root, 'docs/old.md', "# Old\n") || append($root, FRAMEWORK_SKILL, "\n[x](../../../docs/old.md)\n"), 'under docs/'],
    'stale backticked path' => [fn (string $root) => append($root, FRAMEWORK_SKILL, "\nSee `docs/dev-workflow.md`.\n"), 'names `docs/dev-workflow.md`, which does not exist'],
    'public skill linking outside api/' => [fn (string $root) => append($root, PUBLIC_SKILL, "\n[x](../../../.agents/skills/framework-demo/SKILL.md)\n"), 'outside api/'],
    'public skill mentioning internals' => [fn (string $root) => append($root, PUBLIC_SKILL, "\nRead packages/Ecotone/src/Foo.php.\n"), "mentions 'packages/Ecotone/src/'"],
    'public skill importing a missing class' => [fn (string $root) => append($root, PUBLIC_SKILL, "\n```php\nuse Ecotone\\Modelling\\Attribute\\CommandHandler;\n```\n"), 'references Ecotone\\Modelling\\Attribute\\CommandHandler'],
    'public skill with a missing class in a group import' => [fn (string $root) => append($root, PUBLIC_SKILL, "\n```php\nuse Ecotone\\Api\\Attribute\\{CommandHandler, Gone};\n```\n"), 'references Ecotone\\Api\\Attribute\\Gone'],
    'public skill with a missing fully qualified class' => [fn (string $root) => append($root, PUBLIC_SKILL, "\n```php\n#[\\Ecotone\\Api\\Attribute\\Gone]\n```\n"), 'references Ecotone\\Api\\Attribute\\Gone'],
    'marketplace missing a skill' => [fn (string $root) => mkdir($root . '/api/skills/ecotone-extra') || copy($root . '/' . PUBLIC_SKILL, $root . '/api/skills/ecotone-extra/SKILL.md'), 'marketplace.json lists skills'],
    'marketplace path that does not exist' => [fn (string $root) => edit($root, 'api/.claude-plugin/marketplace.json', './skills/ecotone-demo', './nowhere/ecotone-demo'), 'marketplace.json lists skills'],
    'no framework skill at all' => [fn (string $root) => exec('rm -rf ' . escapeshellarg($root . '/.agents/skills/framework-demo') . ' ' . escapeshellarg($root . '/.agents/evals')), '.agents/skills holds no skill'],
];

const FRAMEWORK_SKILL = '.agents/skills/framework-demo/SKILL.md';
const PUBLIC_SKILL = 'api/skills/ecotone-demo/SKILL.md';

$failures = 0;
foreach ($cases as $name => [$mutate, $expected]) {
    $root = createValidTree();
    $mutate($root);
    exec(sprintf('php %s %s 2>&1', escapeshellarg(CHECKER), escapeshellarg($root)), $output, $exitCode);
    $text = implode("\n", $output);
    $output = [];
    exec('rm -rf ' . escapeshellarg($root));

    $passed = $expected === null
        ? $exitCode === 0
        : $exitCode === 1 && str_contains($text, $expected);
    if (! $passed) {
        $failures++;
        fwrite(STDERR, sprintf("FAIL %s\n  expected %s\n  got exit %d:\n%s\n\n", $name, $expected === null ? 'a pass' : "a refusal containing '{$expected}'", $exitCode, $text));
        continue;
    }
    echo "ok   {$name}\n";
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} of " . count($cases) . " checker cases failed.\n");
    exit(1);
}
echo count($cases) . " checker cases passed.\n";

function createValidTree(): string
{
    $root = sys_get_temp_dir() . '/check-agent-skills-' . bin2hex(random_bytes(6));
    write($root, 'AGENTS.md', "Before any task, load .agents/skills/framework-orientation.\n");
    symlink('AGENTS.md', $root . '/CLAUDE.md');
    mkdir($root . '/.claude');
    symlink('../.agents/skills', $root . '/.claude/skills');

    write($root, FRAMEWORK_SKILL, skill('framework-demo', 'eval:demo-case', '[detail](references/detail.md#details)'));
    write($root, '.agents/skills/framework-demo/references/detail.md', "# Details\n");
    writeCase($root, '.agents/evals/framework-demo/demo-case', 'framework-demo');

    write($root, PUBLIC_SKILL, skill('ecotone-demo', 'eval:demo-case', "```php\nuse Ecotone\\Api\\Attribute\\CommandHandler;\n```"));
    writeCase($root, 'api/evals/ecotone-demo/demo-case', 'ecotone-demo');
    write($root, 'api/.claude-plugin/marketplace.json', json_encode(['plugins' => [['source' => './', 'skills' => ['./skills/ecotone-demo']]]], JSON_UNESCAPED_SLASHES));

    write($root, 'packages/Ecotone/composer.json', json_encode(['autoload' => ['psr-4' => ['Ecotone\\Api\\' => 'Api/']]]));
    write($root, 'packages/Ecotone/Api/Attribute/CommandHandler.php', "<?php\n");

    return $root;
}

function skill(string $name, string $verifier, string $extra): string
{
    return <<<MD
        ---
        name: {$name}
        description: >-
          Demo skill. Use when demoing.
        ---

        # Demo

        ## Applies when

        - Demoing

        ## Rules

        ### 1. Demo rule
        - **Expected:** the demo happens.
        - **Verified by:** `{$verifier}`

        ### 2. Second rule
        - **Expected:** the second demo happens.
        - **Verified by:** `check:phpstan`

        ## References

        {$extra}

        MD;
}

function writeCase(string $root, string $directory, string $skill): void
{
    write($root, $directory . '/prompt.md', "---\ndescription: \"demo\"\n---\nDemo.\n");
    write($root, $directory . '/graders/skill-loaded.md', "---\ntype: tool_used\ntool: Skill\ninput_match: '{$skill}'\n---\n");
    write($root, $directory . '/graders/rule-1.md', "---\ntype: llm\n---\nPASS if the demo happens.\n");
}

function copyCase(string $root, string $case): void
{
    writeCase($root, '.agents/evals/framework-demo/' . $case, 'framework-demo');
}

function write(string $root, string $path, string $content): bool
{
    @mkdir(dirname($root . '/' . $path), 0777, true);
    file_put_contents($root . '/' . $path, $content);

    return false;
}

function append(string $root, string $path, string $content): void
{
    file_put_contents($root . '/' . $path, $content, FILE_APPEND);
}

function edit(string $root, string $path, string $search, string $replace): void
{
    $content = file_get_contents($root . '/' . $path);
    if (! str_contains($content, $search)) {
        throw new RuntimeException("Fixture {$path} has no '{$search}' to replace; the test case is out of date with createValidTree().");
    }
    file_put_contents($root . '/' . $path, str_replace($search, $replace, $content));
}

function replaceLinkWithFile(string $root, string $path): void
{
    unlink($root . '/' . $path);
    file_put_contents($root . '/' . $path, 'copy');
}
