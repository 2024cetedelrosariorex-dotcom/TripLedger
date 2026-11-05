// Dependency-free Chrome DevTools smoke checks. Receives only isolated test credentials via stdin.
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
let input = '';
for await (const chunk of process.stdin) input += chunk;
const config = JSON.parse(input);
const profile = path.join(config.runtime, 'chrome-' + Date.now());
await mkdir(profile, {recursive: true});
const chrome = spawn(config.chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], {windowsHide: true, stdio: 'ignore'});
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
const results = [];
const check = (name, ok) => results.push({name, ok: Boolean(ok)});
try {
    let port;
    for (let i = 0; i < 100; i++) {
        try { port = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch {}
        await delay(100);
    }
    if (!port) throw new Error('Headless Chrome did not start');
    const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {method: 'PUT'})).json();
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { ws.addEventListener('open', resolve, {once: true}); ws.addEventListener('error', reject, {once: true}); });
    let id = 0;
    const pending = new Map();
    const errors = [];
    ws.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (data.id && pending.has(data.id)) {
            const {resolve, reject, timer} = pending.get(data.id); clearTimeout(timer); pending.delete(data.id);
            data.error ? reject(new Error(data.error.message)) : resolve(data.result);
        }
        if (data.method === 'Runtime.exceptionThrown') errors.push('JavaScript exception');
        if (data.method === 'Log.entryAdded' && data.params.entry.level === 'error') errors.push('Browser error');
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const requestId = ++id;
        const timer = setTimeout(() => { pending.delete(requestId); reject(new Error('Browser command timed out')); }, 10000);
        pending.set(requestId, {resolve, reject, timer}); ws.send(JSON.stringify({id: requestId, method, params}));
    });
    const evaluate = async expression => (await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true})).result.value;
    const waitFor = async expression => {
        for (let i = 0; i < 60; i++) { if (await evaluate(expression)) return true; await delay(100); }
        return false;
    };
    const go = async page => {
        await send('Page.navigate', {url: config.base + '/' + page});
        await waitFor(`document.readyState === 'complete' && location.pathname.endsWith(${JSON.stringify(page.split('?')[0])})`);
        await delay(200);
    };
    await send('Runtime.enable'); await send('Log.enable'); await send('Page.enable');
    await send('Emulation.setDeviceMetricsOverride', {width: 1366, height: 900, deviceScaleFactor: 1, mobile: false});
    await go('index.php');
    await evaluate(`document.querySelector('#username').value='demo_1';document.querySelector('#password').value=${JSON.stringify(config.password)};document.querySelector('#tripLedgerLoginForm').requestSubmit()`);
    check('Browser Staff login', await waitFor("location.pathname.endsWith('dashboard.php') && document.readyState === 'complete'"));
    await delay(800);
    check('Trippy popup opens after login', await evaluate("document.querySelector('#trippyOverlay')?.hidden === false"));
    await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape'});
    check('Trippy closes with Escape', await evaluate("document.querySelector('#trippyOverlay')?.hidden === true"));
    await evaluate("document.querySelector('#trippyLauncher').click(); document.querySelector('#trippyInput').value='Show outstanding tickets for October 2026'; document.querySelector('#trippyForm').requestSubmit()");
    check('Trippy renders local period and count in browser', await waitFor("document.querySelector('#trippyMessages').textContent.includes('2026-10-01 to 2026-10-31')"));
    await go('ticket_details.php?id=' + config.ticket);
    await evaluate("var f=[...document.querySelectorAll('form')].find(f=>f.querySelector('[name=action]')?.value==='reverse'); f.querySelector('[name=remarks]').value='Browser demonstration reversal'; f.requestSubmit()");
    check('Return confirmation opens', await evaluate("document.querySelector('.tl-confirm-overlay').hidden === false"));
    await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape'});
    check('Return confirmation cancels via keyboard', await evaluate("document.querySelector('.tl-confirm-overlay').hidden === true"));
    await evaluate("var f=[...document.querySelectorAll('form')].find(f=>f.querySelector('[name=action]')?.value==='reverse'); f.requestSubmit(); document.querySelector('[data-modal-confirm]').click()");
    check('Confirmed reversal completes in browser', await waitFor("document.body.textContent.includes('Recorded return reversed successfully.')"));
    await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: false});
    await go('records.php');
    check('Records fit narrow viewport', await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'));
    const screenshot = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
    await writeFile(path.join(config.runtime, 'records-mobile.png'), Buffer.from(screenshot.data, 'base64'));
    await go('ai_assistant.php');
    check('Trippy page fits narrow viewport', await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'));
    await evaluate("document.querySelector('form[action=\"logout.php\"]').requestSubmit()");
    await waitFor("location.pathname.endsWith('index.php') && document.readyState==='complete'");
    await evaluate(`document.querySelector('#username').value='demo_0';document.querySelector('#password').value=${JSON.stringify(config.password)};document.querySelector('#tripLedgerLoginForm').requestSubmit()`);
    check('Browser Admin login', await waitFor("location.pathname.endsWith('admin_dashboard.php') && document.readyState === 'complete'"));
    await go('admin_ai.php');
    check('AI settings fit narrow viewport', await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'));
    check('AI settings labels and save control present', await evaluate("Boolean(document.querySelector('label[for=model]')) && document.body.textContent.includes('Save Settings')"));
    const adminShot = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
    await writeFile(path.join(config.runtime, 'ai-settings-mobile.png'), Buffer.from(adminShot.data, 'base64'));
    check('No browser console/resource errors during tested flows', errors.length === 0);
} catch (error) {
    results.push({name: 'Browser automation completed', ok: false, detail: error.message});
} finally {
    ws?.close(); chrome.kill();
}
process.stdout.write(JSON.stringify(results));
process.exitCode = results.every(result => result.ok) ? 0 : 1;
