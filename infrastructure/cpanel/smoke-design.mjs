import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const manifest = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const release = process.argv[3];
if (!/^design-\d{8}-\d{6}$/.test(release ?? '')) throw new Error('Supply the deployed release name.');
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const results = [];
for (const domain of ['bizismart.com', 'freshchoice.bizismart.com']) {
  for (const entry of manifest.entries) {
    if (!/^(assets\/[^/]+|index\.html|bizismart\.svg|manifest\.webmanifest|registerSW\.js|sw\.js|workbox-[\w-]+\.js)$/.test(entry.path)) throw new Error('Unexpected manifest path.');
    const path = entry.path === 'index.html' ? '/' : `/${entry.path}`;
    let response;
    try { response = await fetch(`https://${domain}${path}?release=${release}`, { signal: AbortSignal.timeout(25000), redirect: 'error', cache: 'no-store' }); }
    catch (cause) { throw new Error(`${domain}${path}: HTTPS request failed`, { cause }); }
    if (!response.ok) throw new Error(`${domain}${path}: HTTP ${response.status}`);
    const bytes = Buffer.from(await response.arrayBuffer());
    if (digest(bytes) !== entry.sha256) throw new Error(`${domain}${path}: public content differs from verified build`);
    console.log(`Verified ${domain}${path}`);
  }
  for (const endpoint of ['health', 'ready']) {
    const response = await fetch(`https://${domain}/api/v1/${endpoint}`, { signal: AbortSignal.timeout(25000), redirect: 'error', cache: 'no-store' });
    const body = await response.json();
    if (!response.ok || body.success !== true) throw new Error(`${domain}: ${endpoint} failed`);
    if (endpoint === 'ready' && (body.data.ready !== true || ['database','migrations','cache'].some(key => body.data.checks[key] !== true))) throw new Error(`${domain}: readiness checks failed`);
    if (endpoint === 'health' && body.data.status !== 'ok') throw new Error(`${domain}: health status failed`);
  }
  results.push({ domain, public_files_verified: manifest.entries.length, health: true, readiness: true });
}
console.log(JSON.stringify({ release, results, note: 'Read-only public checks. No authenticated sale, provider delivery or hardware operation is claimed.' }, null, 2));
