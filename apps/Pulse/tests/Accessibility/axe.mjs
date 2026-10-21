import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import {chromium} from 'playwright-core';

const require = createRequire(import.meta.url);
const axeSource = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const base = (process.argv[2] ?? process.env.PULSE_URL ?? '').replace(/\/$/, '');
if (base === '') {
    console.error('Usage: node axe.mjs <pulse url>   (or PULSE_URL=...). Set CHROME_PATH when Chrome is not /usr/bin/google-chrome.');
    process.exit(2);
}

const viewports = [{name: '1440', width: 1440, height: 900}, {name: '390', width: 390, height: 844}];
const schemes = ['light', 'dark'];
const maxPagesPerPattern = 2;

const browser = await chromium.launch({executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome', args: ['--no-sandbox']});

const patternOf = (path) => path.split('/').map((segment, index) => (index > 1 ? '*' : segment)).join('/');

const discover = async () => {
    const page = await (await browser.newContext({viewport: {width: 1440, height: 900}})).newPage();
    const queue = ['/', '/dead-letters', '/flow', '/topology', '/catalogue', '/traces'];
    const seen = new Set();
    const perPattern = new Map();
    const pages = [];
    while (queue.length > 0) {
        const path = queue.shift();
        if (seen.has(path)) {
            continue;
        }
        seen.add(path);
        const pattern = patternOf(path.split('?')[0]);
        if ((perPattern.get(pattern) ?? 0) >= maxPagesPerPattern) {
            continue;
        }
        perPattern.set(pattern, (perPattern.get(pattern) ?? 0) + 1);
        const response = await page.goto(base + path, {waitUntil: 'networkidle'});
        if (response === null || response.status() >= 400) {
            console.error(`Skipping ${path}: HTTP ${response?.status()}`);
            continue;
        }
        pages.push(path);
        const hrefs = await page.$$eval('a[href^="/"]', (links) => links.map((link) => link.getAttribute('href')));
        for (const href of hrefs) {
            if (!href.startsWith('/assets') && !href.includes('/refresh') && !seen.has(href)) {
                queue.push(href);
            }
        }
    }

    const scoped = ['/services/orders', '/flow?service=orders', '/topology?service=orders', '/catalogue?service=orders', ...(process.env.PULSE_AXE_EXTRA ?? '').split(',').filter((path) => path !== '')];
    for (const path of scoped) {
        if (!pages.includes(path)) {
            pages.push(path);
        }
    }

    return pages;
};

const pages = await discover();
let problems = 0;
const rows = [];

for (const scheme of schemes) {
    for (const viewport of viewports) {
        const page = await (await browser.newContext({viewport, colorScheme: scheme})).newPage();
        for (const path of pages) {
            await page.goto(base + path, {waitUntil: 'networkidle'});
            for (let attempt = 0; attempt < 15 && (await page.$('meta[http-equiv="refresh"]')) !== null; attempt++) {
                await page.waitForTimeout(1000);
                await page.reload({waitUntil: 'networkidle'});
            }
            await page.waitForTimeout(500);
            await page.evaluate(axeSource);
            const result = await page.evaluate(() => window.axe.run(document, {resultTypes: ['violations']}));
            const serious = result.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact));
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            const label = `${scheme}/${viewport.name} ${path}`;
            for (const violation of serious) {
                problems++;
                console.error(`${label}: ${violation.impact} ${violation.id} (${violation.nodes.length} nodes) ${violation.nodes[0].target.join(' ')}`);
            }
            if (overflow > 0) {
                problems++;
                console.error(`${label}: page scrolls horizontally by ${overflow}px`);
            }
            rows.push(`${label}: ${serious.length} serious/critical, overflow ${Math.max(overflow, 0)}px`);
        }
    }
}

await browser.close();
console.log(rows.join('\n'));
console.log(`\n${pages.length} pages x ${schemes.length} schemes x ${viewports.length} viewports, ${problems} problems`);
process.exit(problems === 0 ? 0 : 1);
