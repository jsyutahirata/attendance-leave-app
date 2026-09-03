import {writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {dirname, resolve} from 'node:path';

const appUrl = process.env.ATTENDANCE_APP_URL;
if (!appUrl) throw new Error('ATTENDANCE_APP_URL を指定してからパッケージしてください。');
const parsed = new URL(appUrl);
if (parsed.protocol !== 'https:') throw new Error('本番パッケージの ATTENDANCE_APP_URL はHTTPSで指定してください。');
const target = resolve(dirname(fileURLToPath(import.meta.url)), '../resources/app-config.json');
await writeFile(target, JSON.stringify({appUrl: parsed.toString()}, null, 2) + '\n', 'utf8');
console.log(`Configured Electron app URL: ${parsed.origin}`);
