import {chromium} from 'playwright-core';
import {execFileSync, spawn} from 'node:child_process';
import {mkdirSync, rmSync, writeFileSync} from 'node:fs';

const pulse = (process.env.PULSE_URL ?? 'http://localhost:8280').replace(/\/$/, '');
const ticketing = (process.env.TICKETING_URL ?? 'http://localhost:8201').replace(/\/$/, '');
const screenshots = process.env.SCREENSHOTS ?? 'var/pulse-verification';
const compose = ['compose', '-f', process.env.DEMO_COMPOSE_FILE ?? 'demo/docker-compose.yml'];
const ledger = 'concert_sales_ledger';
const seatMap = 'concert_seat_map';
const wantedPartitions = Number(process.env.PARTITIONS ?? 8);
const parts = `${screenshots}/.parts`;
mkdirSync(parts, {recursive: true});

const failures = [];
const check = (name, condition, detail = '') => {
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${name}${detail ? ` (${detail})` : ''}`);
    if (!condition) {
        failures.push(name);
    }
};
const docker = (...args) => execFileSync('docker', [...compose, ...args], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore']});
const sql = (query) => docker('exec', '-T', 'postgres', 'sh', '-c', `psql -U "$POSTGRES_USER" -d ticketing -Atc "${query}"`).trim();
const consume = (channel, limit, environment = {}) => docker('exec', '-T', ...Object.entries(environment).flatMap(([name, value]) => ['-e', `${name}=${value}`]), 'ticketing', 'bin/console', 'ecotone:run', channel, `--handledMessageLimit=${limit}`, '--executionTimeLimit=60000', '--finishWhenNoMessages=true');
const runBatches = (limit, environment = {}) => consume('ticketing_async', limit, environment);
const drainBatches = (environment = {}) => runBatches(100000, environment);
const drainBatchCompletions = () => consume('ticketing_batch_completions', 100000);
const drainRequests = () => docker('exec', '-T', 'ticketing', 'bin/console', 'ecotone:run', 'ticketing', '--handledMessageLimit=100', '--executionTimeLimit=10000', '--finishWhenNoMessages=true');
const pending = (queue) => Number(sql(`SELECT COUNT(*) FROM messenger_messages WHERE queue_name = '${queue}' AND delivered_at IS NULL AND available_at <= NOW()`));
const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const browser = await chromium.launch({executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome', args: ['--no-sandbox']});
const context = await browser.newContext({viewport: {width: 1280, height: 900}, deviceScaleFactor: 1});
await context.addInitScript(() => localStorage.setItem('ecotone-pulse.intro-seen-on', `${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`));
const page = await context.newPage();
page.setDefaultTimeout(90000);
page.setDefaultNavigationTimeout(90000);
const visit = async (url) => {
    for (let attempt = 1; ; attempt++) {
        try {
            return await page.goto(url);
        } catch (error) {
            if (attempt === 3 || !/ERR_ABORTED|interrupted by another navigation/.test(String(error))) {
                throw error;
            }
            await page.waitForTimeout(1000);
        }
    }
};
const projectionsPage = `${pulse}/services/ticketing/projections`;
const gatheringPage = `${pulse}/services/ticketing/gathering`;
const row = (projection) => page.locator(`tr[data-projection="${projection}"]`).first();
const latestOperation = () => page.locator('[data-recent-rebuilds] tr[data-rebuild]').first();
const text = async (locator) => (await locator.count()) === 0 ? '' : (await locator.first().textContent()).replace(/\s+/g, ' ').trim();
const rowState = async (projection) => (await row(projection).locator('[data-rebuild-state]').count()) === 0 ? null : row(projection).locator('[data-rebuild-state]').getAttribute('data-rebuild-state');
const rowText = (projection) => text(row(projection).locator('[data-rebuild-state]'));
const rowBatches = (projection) => text(row(projection).locator('[data-rebuild-batches]'));
const latestProgress = () => text(latestOperation().locator('[data-rebuild-progress]'));
const waitsBehindQueuedBatches = async (projection) => (await row(projection).locator('[data-rebuild-queued-records]').count()) === 1;

const observe = async (until, description, between = () => drainRequests()) => {
    for (let attempt = 0; attempt < 40; attempt++) {
        await visit(projectionsPage);
        if (await until()) {
            return true;
        }
        between();
        await page.waitForTimeout(1500);
    }
    console.log(`gave up waiting for ${description}: ledger "${await rowText(ledger)}", seat map "${await rowText(seatMap)}", latest operation "${await latestProgress()}"`);

    return false;
};
const openQuietSection = async (projection) => {
    if ((await row(projection).isVisible()) === false && (await page.locator('details[data-projections-quiet] > summary').count()) > 0) {
        await page.locator('details[data-projections-quiet] > summary').click();
    }
};
const stitch = (name, files) => {
    execFileSync('python3', ['-I', '-c', `
import sys
from PIL import Image
out, *paths = sys.argv[1:]
images = [Image.open(path).convert('RGB') for path in paths]
gap = 24
width = max(image.width for image in images)
canvas = Image.new('RGB', (width, sum(image.height for image in images) + gap * (len(images) - 1)), images[0].getpixel((2, 2)))
top = 0
for image in images:
    canvas.paste(image, (0, top))
    top += image.height + gap
canvas.save(out, optimize=True)
`, `${screenshots}/${name}.png`, ...files]);
    console.log(`     screenshot ${screenshots}/${name}.png`);
};
const shotProjection = async (name, projection) => {
    await openQuietSection(projection);
    if ((await page.locator('details[data-projection-operations]').getAttribute('open')) === null) {
        await page.locator('details[data-projection-operations] > summary').click();
    }
    const regions = [row(projection).locator('xpath=ancestor::*[self::section or self::details][1]'), page.locator('[data-projection-operations]')];
    const files = [];
    for (const [index, region] of regions.entries()) {
        const file = `${parts}/${name}-${index}.png`;
        await region.first().screenshot({path: file});
        files.push(file);
    }
    stitch(name, files);
};
const boxOf = (locator) => locator.first().evaluate((element) => {
    const rectangle = element.getBoundingClientRect();

    return {left: rectangle.left + window.scrollX, top: rectangle.top + window.scrollY, right: rectangle.left + window.scrollX + Math.max(rectangle.width, element.scrollWidth), bottom: rectangle.bottom + window.scrollY};
});
const shotSpan = async (name, from, to, width = 1280) => {
    await page.setViewportSize({width, height: 900});
    const [first, last] = [await boxOf(from), await boxOf(to)];
    const left = Math.max(0, Math.min(first.left, last.left) - 8);
    await page.screenshot({path: `${screenshots}/${name}.png`, fullPage: true, clip: {x: left, y: Math.max(0, first.top - 8), width: Math.max(first.right, last.right) + 8 - left, height: last.bottom - first.top + 16}});
    await page.setViewportSize({width: 1280, height: 900});
    console.log(`     screenshot ${screenshots}/${name}.png`);
};
const runFromPulse = async (projection) => {
    await visit(projectionsPage);
    await openQuietSection(projection);
    await page.locator(`tr[data-projection="${projection}"] a[data-projection-actions]:visible`).first().click();
    const panel = page.locator(`[data-rebuild-confirm="${projection}"]`);
    if ((await panel.getAttribute('open')) === null) {
        await panel.locator('summary').click();
    }
    await panel.locator('button[data-rebuild-submit]').click();
    await page.waitForLoadState('load');
    drainRequests();
};

const partitions = () => Number(sql('SELECT COUNT(DISTINCT concert_id) FROM concert_sales_ledger'));
for (let concert = 1; partitions() < wantedPartitions && concert <= wantedPartitions; concert++) {
    await visit(`${ticketing}/admin/concerts`);
    await page.locator('input[name="artist"]').fill(`Rebuild Verification ${concert}`);
    await page.locator('input[name="onSaleRightAway"]').check();
    await page.locator('form.admin-form button[type="submit"], form.admin-form input[type="submit"]').first().click();
    await page.waitForLoadState('load');
    const concertId = sql(`SELECT concert_id FROM concerts WHERE artist = 'Rebuild Verification ${concert}' LIMIT 1`);
    for (let attempt = 1; ; attempt++) {
        try {
            docker('exec', '-T', 'storefront', 'php', 'artisan', 'demo:rush', concertId, '--buyers=2', '--declinedShare=0', '--outageShare=0', '--bounceShare=0');
            break;
        } catch (error) {
            if (attempt === 30 || !String(error.stdout).includes('in the storefront')) {
                throw error;
            }
            await pause(2000);
        }
    }
}
for (let attempt = 0; attempt < 90 && partitions() < wantedPartitions; attempt++) {
    await pause(2000);
}
check(`the sales ledger has at least ${wantedPartitions} concerts with sales, so it rebuilds in that many batches of one concert`, partitions() >= wantedPartitions, `${partitions()} concerts with sales`);
const activity = () => `${pending('ticketing_async')}:${pending('ticketing_batch_completions')}:${sql('SELECT COALESCE(SUM(seats_sold), 0) FROM concert_sales_ledger')}:${sql('SELECT COUNT(*) FROM messenger_messages')}`;
for (let quiet = 0, last = activity(), attempt = 0; quiet < 5 && attempt < 150; attempt++) {
    await pause(2000);
    const now = activity();
    quiet = now === last && now.startsWith('0:0:') ? quiet + 1 : 0;
    last = now;
}
check('the rushes have ended and ticketing_async is empty before ticketing-worker stops', pending('ticketing_async') === 0, activity());

docker('stop', 'ticketing-worker');
const ticketingConsumer = spawn('docker', [...compose, 'exec', '-T', 'ticketing', 'bin/console', 'ecotone:run', 'ticketing', '--executionTimeLimit=3600000'], {stdio: 'ignore'});
try {
    drainBatches();
    drainBatchCompletions();
    check('nothing waits on ticketing_async or ticketing_batch_completions before the first rebuild', pending('ticketing_async') === 0 && pending('ticketing_batch_completions') === 0);

    await runFromPulse(ledger);
    check('a rebuild started from Pulse is running with none of its batches done', await observe(async () => (await rowState(ledger)) === 'running' && /^0 of \d+ batches$/.test(await rowBatches(ledger)), 'the running rebuild'), await rowText(ledger));
    const batches = Number((await rowBatches(ledger)).split(' ')[2]);
    check('the rebuild has one batch per concert with seat events, at least one per concert with sales', batches >= partitions(), `${batches} batches, ${partitions()} concerts with sales`);
    check('with a separate batch completion channel the row shows its batches, not the queued-batches sentence', (await waitsBehindQueuedBatches(ledger)) === false);
    await shotProjection('01-rebuild-started', ledger);

    let executed = 0;
    for (const [percent, name] of [[25, '02-rebuild-progress-25'], [50, '03-rebuild-progress-50'], [75, '04-rebuild-progress-75']]) {
        const target = Math.round(batches * percent / 100);
        runBatches(target - executed);
        executed = target;
        drainBatchCompletions();
        check(`after ${executed} of ${batches} batches ran, Pulse counts ${executed} batches done`, await observe(async () => (await rowBatches(ledger)) === `${executed} of ${batches} batches`, `${executed} batches done`), await rowText(ledger));
        check(`the recent operations show the same ${executed} of ${batches} batches`, (await latestProgress()).startsWith(`${executed} of ${batches} batches`), await latestProgress());
        await shotProjection(name, ledger);
    }
    drainBatches();
    drainBatchCompletions();
    check('the rebuild ends at done once every batch ran', await observe(async () => (await rowState(ledger)) === 'done', 'done'), await rowText(ledger));
    check('the recent operations list the finished rebuild with every batch', (await latestProgress()).startsWith(`${batches} of ${batches} batches`), await latestProgress());
    await shotProjection('05-rebuild-done', ledger);

    const failingConcert = sql('SELECT DISTINCT concert_id FROM concert_sales_ledger ORDER BY concert_id LIMIT 1 OFFSET 1');
    await runFromPulse(ledger);
    await observe(async () => (await rowState(ledger)) === 'running' && (await rowBatches(ledger)) === `0 of ${batches} batches`, 'the failing rebuild');
    drainBatches({TICKETING_SALES_LEDGER_RESET_FAILS_FOR_CONCERT: failingConcert});
    drainBatchCompletions();
    check('a rebuild whose batch failed shows as failed', await observe(async () => (await rowState(ledger)) === 'failed', 'failed'), await rowText(ledger));
    const failedLink = row(ledger).locator('a[data-rebuild-failed-batch]').first();
    check('the failed batch links its dead letter', (await failedLink.count()) === 1 && (await failedLink.getAttribute('href')).startsWith('/dead-letters/ticketing/'), await failedLink.getAttribute('href'));
    check('the run waits for the replay of the failed batch', (await text(row(ledger).locator('[data-rebuild-state-label]'))) === 'Rebuild waiting' && (await row(ledger).locator('[data-rebuild-replay-hint]').count()) === 1, await rowText(ledger));
    check('the other batches are done', (await rowBatches(ledger)) === `${batches - 1} of ${batches} batches`, await rowBatches(ledger));
    check('the up-to-date row says its positions are caught up and the rebuild waits for the replay', (await text(row(ledger).locator('[data-projection-reason]'))).startsWith('Event positions are caught up; the latest rebuild waits for the replay of batch '), await text(row(ledger).locator('[data-projection-reason]')));
    check('the folded summaries say waiting for replay', (await text(page.locator('[data-projection-operations] [data-summary-state]'))).includes('1 waiting for replay'), await text(page.locator('[data-projection-operations] [data-summary-state]')));
    await shotProjection('06-rebuild-with-a-failed-batch', ledger);
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
    await shotSpan('07-dead-letter-of-the-failed-batch', page.locator('main h1'), page.locator('[data-action-consequences]'));
    const replay = page.locator('button[name="action"][value="replay"]').first();
    check('the dead letter offers a replay', (await replay.count()) === 1);
    await replay.click();
    await page.waitForLoadState('load');
    check('replaying the failed batch completes the rebuild', await observe(async () => (await rowState(ledger)) === 'done', 'done after replay', () => {
        drainRequests();
        drainBatches();
        drainBatchCompletions();
    }), await rowText(ledger));
    check('the replayed rebuild has every batch done', (await latestProgress()).startsWith(`${batches} of ${batches} batches`), await latestProgress());
    await shotProjection('08-rebuild-done-after-replay', ledger);

    docker('exec', '-T', 'ticketing', 'bin/console', 'ecotone:projection:rebuild', ledger);
    check('a rebuild started from the console is listed as started from the console', await observe(async () => (await latestOperation().locator('[data-rebuild-started-from-console]').count()) === 1, 'the console rebuild'), await rowText(ledger));
    drainBatches();
    drainBatchCompletions();
    check('the console rebuild ends at done with every batch and no trace', await observe(async () => (await rowState(ledger)) === 'done' && (await latestOperation().locator('[data-rebuild-started-from-console]').count()) === 1, 'the console rebuild done') && (await latestProgress()).startsWith(`${batches} of ${batches} batches`) && (await latestOperation().locator('[data-rebuild-no-trace]').count()) === 1, await latestProgress());
    await shotProjection('09-rebuild-started-from-the-console', ledger);

    await runFromPulse(ledger);
    await observe(async () => (await rowState(ledger)) === 'running' && (await rowBatches(ledger)) === `0 of ${batches} batches`, 'the rebuild to discard');
    const doneBeforeDiscard = batches - 2;
    runBatches(doneBeforeDiscard);
    drainBatchCompletions();
    await observe(async () => (await rowBatches(ledger)) === `${doneBeforeDiscard} of ${batches} batches`, 'the batches of the rebuild to discard');
    const openRun = () => page.locator('tr[data-gathering-group]').filter({has: page.locator('[data-group-endpoint]', {hasText: `${ledger}.rebuild`})}).filter({has: page.locator('[data-group-state]', {hasText: 'open'})});
    for (let attempt = 0; attempt < 30; attempt++) {
        await visit(gatheringPage);
        if ((await openRun().count()) === 1 && (await text(openRun().locator('[data-group-recorded]'))) === String(doneBeforeDiscard)) {
            break;
        }
        drainRequests();
        await page.waitForTimeout(1500);
    }
    check('the Gathering tab lists the running rebuild as an open group under its endpoint id with its finished batches recorded', (await openRun().count()) === 1 && (await text(openRun().locator('[data-group-recorded]'))) === String(doneBeforeDiscard), await text(openRun()));
    await shotSpan('12-gathering-tab-with-the-run', page.locator('main h1'), page.locator('section[aria-label^="Gathered groups"]'), 2200);
    await openRun().locator('button[data-discard-group]').click();
    await page.waitForLoadState('load');
    drainRequests();
    check('a rebuild discarded on the Gathering tab is shown as discarded with the batches it did not project', await observe(async () => (await rowState(ledger)) === 'discarded', 'discarded'), await rowText(ledger));
    check('the discarded rebuild keeps its finished batches and names the two it did not project', (await rowBatches(ledger)) === `${doneBeforeDiscard} of ${batches} batches` && (await latestProgress()).includes('not projected'), await latestProgress());
    check('the recent operations summary names the discarded run instead of saying all done', (await text(page.locator('[data-projection-operations] [data-summary-state]'))).includes('1 discarded'), await text(page.locator('[data-projection-operations] [data-summary-state]')));
    check('the up-to-date row says the latest rebuild was discarded with the batches it did not project', (await text(row(ledger).locator('[data-projection-reason]'))) === `Event positions are caught up; the latest rebuild was discarded with batches ${doneBeforeDiscard + 1}, ${batches} not projected.`, await text(row(ledger).locator('[data-projection-reason]')));
    await shotProjection('10-rebuild-discarded', ledger);
    drainBatches();
    drainBatchCompletions();
    check('the discarded rebuild stays discarded after its remaining batches ran', await observe(async () => (await rowState(ledger)) === 'discarded', 'still discarded'), await rowText(ledger));

    await runFromPulse(seatMap);
    check('a rebuild whose batch reports share its batches channel shows its running time and how to count each batch as it finishes', await observe(async () => (await rowState(seatMap)) === 'running' && (await waitsBehindQueuedBatches(seatMap)) && (await latestProgress()).startsWith('running for'), 'the shared-channel rebuild'), await rowText(seatMap));
    check('the sentence names the shared channel and batchCompletionChannelName', (await text(row(seatMap).locator('[data-rebuild-queued-records]'))) === 'Finished batches are counted once the batches queued on ticketing_async have run. Set batchCompletionChannelName on #[ProjectionRebuild] to count each batch as it finishes.', await text(row(seatMap).locator('[data-rebuild-queued-records]')));
    await shotProjection('11-shared-channel-rebuild-running', seatMap);
    drainBatches();
    check('the shared-channel rebuild ends at done once its batches and their reports ran', await observe(async () => (await rowState(seatMap)) === 'done', 'shared-channel done'), await rowText(seatMap));
} finally {
    rmSync(parts, {recursive: true, force: true});
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
