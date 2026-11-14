import {chromium} from 'playwright-core';
import {execFileSync, spawn} from 'node:child_process';
import {mkdirSync, writeFileSync} from 'node:fs';

const pulse = (process.env.PULSE_URL ?? 'http://localhost:8280').replace(/\/$/, '');
const ticketing = (process.env.TICKETING_URL ?? 'http://localhost:8201').replace(/\/$/, '');
const screenshots = process.env.SCREENSHOTS ?? 'var/pulse-verification';
const compose = ['compose', '-f', process.env.DEMO_COMPOSE_FILE ?? 'demo/docker-compose.yml'];
const projection = 'concert_sales_ledger';
const wantedConcerts = Number(process.env.CONCERTS ?? 12);
mkdirSync(screenshots, {recursive: true});

const failures = [];
const report = [];
const check = (name, condition, detail = '') => {
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`);
    if (!condition) {
        failures.push(name);
    }
};
const docker = (...args) => execFileSync('docker', [...compose, ...args], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore']});
const sql = (query) => docker('exec', '-T', 'postgres', 'sh', '-c', `psql -U "$POSTGRES_USER" -d ticketing -Atc "${query}"`).trim();
const consumeAsync = (limit, environment = {}) => docker('exec', '-T', ...Object.entries(environment).flatMap(([name, value]) => ['-e', `${name}=${value}`]), 'ticketing', 'bin/console', 'ecotone:run', 'ticketing_async', `--handledMessageLimit=${limit}`, '--executionTimeLimit=60000', '--finishWhenNoMessages=true');
const drainAsync = (environment = {}) => consumeAsync(100000, environment);
const drainRequests = () => docker('exec', '-T', 'ticketing', 'bin/console', 'ecotone:run', 'ticketing', '--handledMessageLimit=100', '--executionTimeLimit=10000', '--finishWhenNoMessages=true');
const pendingAsync = () => Number(sql("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'ticketing_async' AND delivered_at IS NULL AND available_at <= NOW()"));

const browser = await chromium.launch({executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome', args: ['--no-sandbox']});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
await context.addInitScript(() => localStorage.setItem('ecotone-pulse.intro-seen-on', `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`));
const page = await context.newPage();
page.setDefaultTimeout(90000);
page.setDefaultNavigationTimeout(90000);
const visit = async (url) => {
    for (let attempt = 1; ; attempt++) {
        try {
            return await page.goto(url);
        } catch (error) {
            if (attempt === 3 || !String(error).includes('ERR_ABORTED')) {
                throw error;
            }
            await page.waitForTimeout(1000);
        }
    }
};
const projectionsPage = `${pulse}/services/ticketing/projections`;
const row = () => page.locator(`tr[data-projection="${projection}"]`).first();

const observe = async (until, description) => {
    for (let attempt = 0; attempt < 40; attempt++) {
        await visit(projectionsPage);
        if (await until()) {
            return true;
        }
        drainRequests();
        await page.waitForTimeout(1500);
    }
    console.log(`gave up waiting for ${description}: row "${await rowText()}", latest operation "${await latestProgress()}"`);

    return false;
};
const rowState = async () => (await row().locator('[data-rebuild-state]').count()) === 0 ? null : row().locator('[data-rebuild-state]').getAttribute('data-rebuild-state');
const rowText = async () => (await row().locator('[data-rebuild-state]').count()) === 0 ? '' : (await row().locator('[data-rebuild-state]').textContent()).replace(/\s+/g, ' ').trim();
const latestProgress = async () => (await page.locator('[data-recent-rebuilds] tr[data-rebuild] [data-rebuild-progress]').count()) === 0 ? '' : (await page.locator('[data-recent-rebuilds] tr[data-rebuild] [data-rebuild-progress]').first().textContent()).trim();
const waitsBehindQueuedBatches = async () => (await row().locator('[data-rebuild-queued-records]').count()) === 1;
const rowBatches = async () => (await row().locator('[data-rebuild-batches]').count()) === 0 ? null : (await row().locator('[data-rebuild-batches]').first().textContent()).trim();
const shot = async (name) => {
    const path = `${screenshots}/${name}.png`;
    if ((await row().isVisible()) === false && (await page.locator('details[data-projections-quiet] > summary').count()) > 0) {
        await page.locator('details[data-projections-quiet] > summary').click();
    }
    await row().scrollIntoViewIfNeeded();
    await page.screenshot({path, fullPage: true});
    console.log(`     screenshot ${path}`);
};
const record = async (scenario, moment) => {
    await visit(projectionsPage);
    await page.waitForTimeout(500);
    const entry = {scenario, moment, executedBatches: null, page: await rowText()};
    report.push(entry);
    console.log(`     ${scenario} / ${moment}: ${entry.page}`);

    return entry;
};
const rebuildFromPulse = async () => {
    await visit(projectionsPage);
    if ((await page.locator(`tr[data-projection="${projection}"] a[data-projection-actions]:visible`).count()) === 0) {
        await page.locator('details[data-projections-quiet] > summary').click();
    }
    await page.locator(`tr[data-projection="${projection}"] a[data-projection-actions]:visible`).first().click();
    const panel = page.locator(`[data-rebuild-confirm="${projection}"]`);
    if ((await panel.getAttribute('open')) === null) {
        await panel.locator('summary').click();
    }
    await panel.locator('button[data-rebuild-submit]').click();
    await page.waitForLoadState('load');
    drainRequests();
};

const ticketingConsumer = spawn('docker', [...compose, 'exec', '-T', 'ticketing', 'bin/console', 'ecotone:run', 'ticketing', '--executionTimeLimit=3600000'], {stdio: 'ignore'});
try {
    docker('stop', 'ticketing-worker');

    const concerts = Number(sql('SELECT COUNT(*) FROM concerts'));
    for (let concert = concerts + 1; concert <= wantedConcerts; concert++) {
        await visit(`${ticketing}/admin/concerts`);
        await page.locator('input[name="artist"]').fill(`Rebuild Verification ${concert}`);
        await page.locator('input[name="onSaleRightAway"]').check();
        await page.locator('form.admin-form button[type="submit"], form.admin-form input[type="submit"]').first().click();
        await page.waitForLoadState('load');
    }
    drainAsync();
    const partitions = Number(sql("SELECT COUNT(DISTINCT concert_id) FROM concert_sales_ledger"));
    check('the sales ledger has at least eight concerts with sales, so it rebuilds in at least eight batches of one concert', partitions >= 8, `${partitions} concerts with sales`);
    check('nothing else waits on ticketing_async before the rebuild', pendingAsync() === 0, `${pendingAsync()} pending`);

    await rebuildFromPulse();
    check('a rebuild started from Pulse shows its running time and that its batches are counted after the queued ones', await observe(async () => (await waitsBehindQueuedBatches()) && (await latestProgress()).startsWith('running for'), 'the running rebuild'), await rowText());
    const batches = partitions;
    await shot('01-rebuild-started');
    report.push({scenario: 'shared channel, 1 consumer', moment: 'started', executedBatches: 0, page: await rowText()});
    let executed = 0;
    for (const [percent, name] of [[25, '02-shared-one-consumer-25'], [50, '03-shared-one-consumer-50'], [75, '04-shared-one-consumer-75'], [100, '05-shared-one-consumer-all-batches-executed']]) {
        const target = Math.round(batches * percent / 100);
        consumeAsync(target - executed);
        executed = target;
        const entry = await record('shared channel, 1 consumer', `${percent}% of batches executed`);
        entry.executedBatches = executed;
        await shot(name);
    }
    drainAsync();
    check('the rebuild ends at done once its part reports are consumed', await observe(async () => (await rowState()) === 'done', 'done'), await rowText());
    const done = (await page.locator(`[data-recent-rebuilds] tr[data-rebuild] [data-rebuild-progress]`).first().textContent()).trim();
    check('the recent operations list the finished rebuild with every batch', done.startsWith(`${batches} of ${batches} batches`), done);
    await record('shared channel, 1 consumer', 'all part reports consumed');
    await shot('06-rebuild-done');

    await rebuildFromPulse();
    await observe(async () => (await waitsBehindQueuedBatches()) && (await latestProgress()).startsWith('running for'), 'the second rebuild');
    report.push({scenario: 'shared channel, 4 consumers', moment: 'started', executedBatches: 0, page: await rowText()});
    for (const round of [1, 2, 3]) {
        const consumers = Array.from({length: 4}, () => new Promise((resolve) => spawn('docker', [...compose, 'exec', '-T', 'ticketing', 'bin/console', 'ecotone:run', 'ticketing_async', '--handledMessageLimit=1', '--executionTimeLimit=60000', '--finishWhenNoMessages=true'], {stdio: 'ignore'}).on('exit', resolve)));
        await Promise.all(consumers);
        const entry = await record('shared channel, 4 consumers', `round ${round} of 4 parallel messages`);
        entry.executedBatches = Math.min(batches, round * 4);
        await shot(`07-shared-four-consumers-round-${round}`);
    }
    drainAsync();
    check('the rebuild with four consumers also ends at done', await observe(async () => (await rowState()) === 'done', 'done'), await rowText());

    const failingConcert = sql("SELECT DISTINCT concert_id FROM concert_sales_ledger ORDER BY concert_id LIMIT 1 OFFSET 1");
    await rebuildFromPulse();
    await observe(async () => (await waitsBehindQueuedBatches()) && (await latestProgress()).startsWith('running for'), 'the failing rebuild');
    drainAsync({TICKETING_SALES_LEDGER_RESET_FAILS_FOR_CONCERT: failingConcert});
    check('a rebuild whose batch failed shows as failed', await observe(async () => (await rowState()) === 'failed', 'failed'), await rowText());
    const failedLink = row().locator('a[data-rebuild-failed-batch]').first();
    check('the failed batch links its dead letter', (await failedLink.count()) === 1 && (await failedLink.getAttribute('href')).startsWith('/dead-letters/ticketing/'), await failedLink.getAttribute('href'));
    check('the run waits for the replay of the failed batch', (await row().locator('[data-rebuild-state-label]').textContent()).trim() === 'Rebuild waiting' && (await row().locator('[data-rebuild-replay-hint]').count()) === 1, await rowText());
    check('the other batches are done', (await rowBatches()) === `${batches - 1} of ${batches} batches`, await rowBatches());
    await shot('08-rebuild-with-a-failed-batch');
    const deadLetterUrl = new URL(await failedLink.getAttribute('href'), pulse).toString();
    await failedLink.click();
    await page.waitForLoadState('load');
    for (let attempt = 0; attempt < 30 && (await page.locator('button[name="action"][value="replay"]').count()) === 0; attempt++) {
        console.log('     the dead letter is not in the snapshot Pulse holds yet; reloading');
        drainRequests();
        await page.waitForTimeout(2000);
        await visit(deadLetterUrl);
    }
    check('the dead letter of the failed batch opens', page.url().includes('/dead-letters/ticketing/'), page.url());
    await page.screenshot({path: `${screenshots}/09-dead-letter-of-the-failed-batch.png`, fullPage: true});
    console.log(`     screenshot ${screenshots}/09-dead-letter-of-the-failed-batch.png`);
    const replay = page.locator('button[name="action"][value="replay"]').first();
    check('the dead letter offers a replay', (await replay.count()) === 1);
    await replay.click();
    await page.waitForLoadState('load');
    check('replaying the failed batch completes the rebuild', await observe(async () => {
        if ((await rowState()) === 'done') {
            return true;
        }
        drainRequests();
        drainAsync();

        return false;
    }, 'done after replay'), await rowText());
    check('the replayed rebuild has every batch done', (await page.locator('[data-recent-rebuilds] tr[data-rebuild] [data-rebuild-progress]').first().textContent()).trim().startsWith(`${batches} of ${batches} batches`));
    await shot('10-rebuild-done-after-replay');

    docker('exec', '-T', 'ticketing', 'bin/console', 'ecotone:projection:rebuild', projection);
    check('a rebuild started from the console is shown, marked as started from the console', await observe(async () => (await row().locator('[data-rebuild-started-from-console]').count()) === 1 && (await waitsBehindQueuedBatches()), 'the console rebuild'), await rowText());
    await shot('11-rebuild-started-from-the-console');
    await visit(`${pulse}/services/ticketing/gathering`);
    await page.waitForLoadState('load');
    const gathering = await page.locator('body').innerText();
    check('the run is listed on the Gathering tab under its endpoint id', gathering.includes(`${projection}.rebuild`));
    await page.screenshot({path: `${screenshots}/12-gathering-tab-with-the-run.png`, fullPage: true});
    console.log(`     screenshot ${screenshots}/12-gathering-tab-with-the-run.png`);
    drainAsync();
    check('the console rebuild ends at done', await observe(async () => (await rowState()) === 'done', 'done'), await rowText());
    await shot('13-console-rebuild-done');
} finally {
    writeFileSync(`${screenshots}/progress-report.json`, JSON.stringify(report, null, 2));
    ticketingConsumer.kill('SIGTERM');
    docker('exec', '-T', 'ticketing', 'sh', '-c', 'for p in $(ls /proc | grep -E "^[0-9]+$"); do tr "\\0" " " < /proc/$p/cmdline 2>/dev/null | grep -aq "^php bin/console ecotone:run ticketing --executionTimeLimit=3600000" && kill -TERM $p; done; true');
    await browser.close();
    if (process.env.KEEP_WORKERS_STOPPED !== '1') {
        docker('start', 'ticketing-worker');
    }
}

if (failures.length > 0) {
    console.error(`${failures.length} check(s) failed: ${failures.join('; ')}`);
    process.exit(1);
}
