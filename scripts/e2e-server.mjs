// Minimal static file server for the E2E fixtures. Serves files under
// `tests/e2e/fixtures/` and the built widget bundle from `public-build/`.
// No frameworks — keeps the harness reproducible across CI machines.

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const FIXTURES_DIR = join(ROOT, 'tests', 'e2e', 'fixtures');
const BUILD_DIR = join(ROOT, 'public-build');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

function mimeFor(path) {
  return MIME[extname(path).toLowerCase()] ?? 'application/octet-stream';
}

const PORT = Number(process.env.E2E_PORT ?? 4173);

const server = createServer(async (req, res) => {
  const url = new URL(req.url ?? '/', `http://localhost:${PORT}`);
  if (url.pathname === '/health') {
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end('ok');
    return;
  }

  // /public-build/* → bundle output
  if (url.pathname.startsWith('/public-build/')) {
    const file = join(BUILD_DIR, url.pathname.replace('/public-build/', ''));
    if (existsSync(file)) {
      try {
        const buf = await readFile(file);
        res.writeHead(200, { 'Content-Type': mimeFor(file) });
        res.end(buf);
        return;
      } catch {
        // fall through to 404
      }
    }
  }

  // Default: serve from tests/e2e/fixtures/, with index.html for directories.
  let pathname = url.pathname === '/' ? '/index.html' : url.pathname;
  if (pathname.endsWith('/')) pathname += 'index.html';
  const file = join(FIXTURES_DIR, pathname);
  if (existsSync(file)) {
    try {
      const buf = await readFile(file);
      res.writeHead(200, { 'Content-Type': mimeFor(file) });
      res.end(buf);
      return;
    } catch {
      // fall through to 404
    }
  }
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
});

server.listen(PORT, '127.0.0.1', () => {
  // eslint-disable-next-line no-console
  console.log(`[e2e] listening on http://127.0.0.1:${PORT}`);
});
