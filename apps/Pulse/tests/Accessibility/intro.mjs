import {chromium} from 'playwright-core';

const base = (process.argv[2] ?? process.env.PULSE_URL ?? '').replace(/\/$/, '');
const version = process.env.PULSE_EXPECTED_VERSION ?? '';
if (base === '' || version === '') {
    console.error('Usage: PULSE_EXPECTED_VERSION=<PULSE_VERSION of the running Pulse> node intro.mjs <pulse url>');
    process.exit(2);
}

const browser = await chromium.launch({executablePath: process.env.CHROME_PATH ?? '/usr/bin/google-chrome', args: ['--no-sandbox']});
const failures = [];
const check = (name, condition) => {
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${name}`);
    if (!condition) {
        failures.push(name);
    }
};
const open = async (options = {}, init = null) => {
    const context = await browser.newContext({viewport: {width: 1440, height: 900}, ...options});
    if (init !== null) {
        await context.addInitScript(init);
    }

    return {context, page: await context.newPage()};
};
const intro = (page) => page.$('html.intro-pending [data-intro]');
const gone = (page, milliseconds) => page.waitForSelector('[data-intro]', {state: 'detached', timeout: milliseconds}).then(() => true, () => false);
const seen = (page) => page.evaluate(() => localStorage.getItem('ecotone-pulse.intro-seen'));

{
    const {context, page} = await open();
    await page.goto(base + '/');
    check('first visit shows the splash', (await intro(page)) !== null);
    await page.waitForTimeout(900);
    check('the heart is beating', await page.$eval('[data-intro-heart]', (heart) => getComputedStyle(heart).animationName === 'intro-beat' && getComputedStyle(heart).opacity === '1'));
    check('the splash gives way to the dashboard', await gone(page, 5000));
    check('the version is stored once the splash is done', (await seen(page)) === version);
    check('the header logo stays on the page', (await page.$('header.topbar a.brand svg[aria-label="Ecotone"]')) !== null);
    await page.reload();
    check('a reload for the same version goes straight to the dashboard', (await intro(page)) === null);
    await page.evaluate(() => localStorage.setItem('ecotone-pulse.intro-seen', 'an-older-version'));
    await page.reload();
    check('a different version shows the splash again', (await intro(page)) !== null);
    await context.close();
}

for (const [name, act] of [['a click', (page) => page.mouse.click(300, 300)], ['a key', (page) => page.keyboard.press('Escape')]]) {
    const {context, page} = await open();
    await page.goto(base + '/');
    await page.waitForTimeout(300);
    await act(page);
    check(`${name} skips the splash`, await gone(page, 1500));
    check(`${name} stores the version`, (await seen(page)) === version);
    await context.close();
}

{
    const {context, page} = await open({reducedMotion: 'reduce'});
    await page.goto(base + '/');
    check('reduced motion shows no beat animation', await page.$eval('[data-intro-heart]', (heart) => getComputedStyle(heart).animationName === 'none'));
    check('reduced motion keeps the splash brief', await gone(page, 1200));
    await context.close();
}

{
    const {context, page} = await open({}, () => {
        Storage.prototype.setItem = () => {
            throw new Error('storage is unavailable');
        };
    });
    await page.goto(base + '/');
    await page.waitForTimeout(800);
    check('unavailable storage never shows the splash', (await intro(page)) === null);
    await context.close();
}

{
    const {context, page} = await open({javaScriptEnabled: false});
    await page.goto(base + '/');
    check('without JavaScript the dashboard is not blocked', await page.$eval('[data-intro]', (element) => getComputedStyle(element).display === 'none'));
    await context.close();
}

await browser.close();
console.log(failures.length === 0 ? '\nintro: all checks passed' : `\nintro: ${failures.length} failed`);
process.exit(failures.length === 0 ? 0 : 1);
