// Reduziert package-lock.json auf die in package.json gepinnten Pakete.
// Die Bundles unter CMS/assets/ sind fertige Build-Artefakte; die Build-Abhängigkeiten der Upstream-Pakete
// (vite, svgtofont …) werden nie ausgeliefert und würden nur Fehlalarme erzeugen.
// Aufruf: npm install --package-lock-only --ignore-scripts && node prune-lock.mjs
import { readFileSync, writeFileSync } from 'node:fs';

const dir = new URL('.', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('package.json', dir), 'utf8'));
const lock = JSON.parse(readFileSync(new URL('package-lock.json', dir), 'utf8'));
const packages = { '': lock.packages[''] };

for (const [name, version] of Object.entries(manifest.dependencies)) {
    const entry = lock.packages[`node_modules/${name}`];
    if (!entry || entry.version !== version) {
        throw new Error(`${name}@${version} fehlt in package-lock.json (zuerst npm install --package-lock-only ausführen).`);
    }
    const { version: locked, resolved, integrity, license } = entry;
    packages[`node_modules/${name}`] = { version: locked, resolved, integrity, license };
}

lock.packages = packages;
writeFileSync(new URL('package-lock.json', dir), JSON.stringify(lock, null, 2) + '\n');
console.log(`package-lock.json: ${Object.keys(packages).length - 1} Pakete`);
