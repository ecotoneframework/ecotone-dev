import {readdirSync, readFileSync} from 'node:fs';
import {join} from 'node:path';
import {JSDOM} from 'jsdom';

const pulse = join(import.meta.dirname, '..', '..');
const pinned = /'mermaid' => \['version' => '([^']+)'\]/.exec(readFileSync(join(pulse, 'importmap.php'), 'utf8'))?.[1];
const installed = JSON.parse(readFileSync(join(import.meta.dirname, 'node_modules', 'mermaid', 'package.json'), 'utf8')).version;
if (pinned !== installed) {
    console.error(`The pages load mermaid ${pinned} (importmap.php) but this check installed ${installed}. Set the mermaid version in tests/Mermaid/package.json to ${pinned} and run npm install.`);
    process.exit(2);
}

const dom = new JSDOM('<!doctype html><html><body></body></html>');
globalThis.window = dom.window;
globalThis.document = dom.window.document;

const {default: mermaid} = await import('mermaid');
mermaid.initialize({startOnLoad: false, securityLevel: 'strict', maxTextSize: 400000, maxEdges: 2000, flowchart: {htmlLabels: false}});

const directory = process.argv[2];
const files = readdirSync(directory).filter((file) => file.endsWith('.mmd')).sort();
if (files.length === 0) {
    console.error(`No .mmd diagrams in ${directory}. Run the Pulse test suite first: it dumps every page's diagram there.`);
    process.exit(2);
}

let failed = 0;
for (const file of files) {
    try {
        await mermaid.parse(readFileSync(join(directory, file), 'utf8'));
        console.log(`ok     ${file}`);
    } catch (error) {
        failed++;
        console.error(`FAILED ${file}: ${error.message ?? error}`);
    }
}
process.exit(failed === 0 ? 0 : 1);
