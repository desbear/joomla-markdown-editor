// Rebuilds the vendored front-end files. Run from tools/assets:
//   npm ci && npm run build
// Output is committed to the repo so the installable package never depends on a CDN.
import { build } from 'esbuild';
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const systemJs = resolve(root, 'src/plugins/system/gssmarkdown/media/js/vendor');
const systemCss = resolve(root, 'src/plugins/system/gssmarkdown/media/css/vendor');
const nm = resolve(here, 'node_modules');

for (const dir of [systemJs, systemCss]) {
  mkdirSync(dir, { recursive: true });
}

const version = (pkg) => JSON.parse(readFileSync(resolve(nm, pkg, 'package.json'), 'utf8')).version;
// MIT requires the copyright notice to travel with every copy, minified ones included.
const notice = `/*! PrismJS ${version('prismjs')} | (c) 2012 Lea Verou | MIT License | https://prismjs.com | see LICENSE-prism.txt */\n`;

await build({
  entryPoints: [resolve(here, 'prism-entry.js')],
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2019'],
  legalComments: 'eof',
  banner: { js: `${notice}window.Prism=window.Prism||{};window.Prism.manual=true;` },
  outfile: resolve(systemJs, 'prism.bundle.min.js'),
});

writeFileSync(
  resolve(systemCss, 'prism.min.css'),
  notice.replace('LICENSE-prism.txt', 'js/vendor/LICENSE-prism.txt') + readFileSync(resolve(nm, 'prismjs/themes/prism.min.css'), 'utf8'),
);
copyFileSync(resolve(nm, 'prismjs/LICENSE'), resolve(systemJs, 'LICENSE-prism.txt'));

writeFileSync(
  resolve(root, 'VENDOR-VERSIONS.txt'),
  [
    `prismjs ${version('prismjs')} (MIT)`,
    '',
  ].join('\n'),
);

console.log('vendor assets rebuilt');
