import type { SseFrame, SseEventName } from './types.js';

export interface SseHandlers {
  onFrame(frame: SseFrame): void;
  onError?(message: string, code?: string): void;
  onConnected?(): void;
  onClose?(reason: 'done' | 'aborted' | 'fatal' | 'rate_limited'): void;
}

export interface SseOptions {
  url: string;
  body: Record<string, unknown>;
  headers?: Record<string, string>;
  bearer?: string | null;
  csrfToken?: string | null;
  signal?: AbortSignal;
  /** Max retries on transient errors. 0 disables retry. */
  maxRetries?: number;
  /** Initial backoff delay in ms (default 1000). */
  initialBackoffMs?: number;
  /** Cap on backoff in ms (default 30000). */
  maxBackoffMs?: number;
}

const KNOWN_EVENTS: ReadonlySet<SseEventName> = new Set<SseEventName>([
  'text', 'block', 'tool_call', 'tool_result', 'frontend_action', 'error', 'done',
]);

function readCsrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  const el = document.querySelector('meta[name="csrf-token"]');
  return el?.getAttribute('content') ?? null;
}

/**
 * Splits the trailing buffer into the next ready event block (terminated by `\n\n`)
 * and the leftover. Returns { block, rest } or null when the buffer has no full block yet.
 */
export function nextBlock(buf: string): { block: string; rest: string } | null {
  // The SSE spec accepts `\n\n`, `\r\n\r\n`, or `\r\r` as event delimiters; we
  // normalize all to `\n` first, which is cheap and avoids three regexes.
  const normalized = buf.replace(/\r\n?/g, '\n');
  const idx = normalized.indexOf('\n\n');
  if (idx === -1) return null;
  return { block: normalized.slice(0, idx), rest: normalized.slice(idx + 2) };
}

/**
 * Parses a single SSE event block into a frame. Returns null when the block has
 * no `event:` line (e.g. comment-only keepalives) or the data is not valid JSON.
 */
export function parseFrame(block: string): SseFrame | null {
  let event = '';
  const dataLines: string[] = [];
  for (const rawLine of block.split('\n')) {
    if (rawLine === '' || rawLine.startsWith(':')) continue;
    const colon = rawLine.indexOf(':');
    const field = colon === -1 ? rawLine : rawLine.slice(0, colon);
    const value = colon === -1 ? '' : rawLine.slice(colon + 1).replace(/^ /, '');
    if (field === 'event') event = value;
    else if (field === 'data') dataLines.push(value);
  }
  if (!event || !KNOWN_EVENTS.has(event as SseEventName)) return null;
  const dataRaw = dataLines.join('\n');
  if (dataRaw === '') return { event: event as SseEventName, data: {} };
  try {
    const parsed = JSON.parse(dataRaw);
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) return null;
    return { event: event as SseEventName, data: parsed as Record<string, unknown> };
  } catch {
    return null;
  }
}

function jitter(ms: number): number {
  return ms + Math.floor(Math.random() * Math.min(1000, ms * 0.25));
}

export function streamPost(opts: SseOptions, handlers: SseHandlers): { abort(): void } {
  const controller = new AbortController();
  const externalAbort = () => controller.abort();
  if (opts.signal) {
    if (opts.signal.aborted) controller.abort();
    else opts.signal.addEventListener('abort', externalAbort, { once: true });
  }

  const maxRetries = opts.maxRetries ?? 4;
  const initial = opts.initialBackoffMs ?? 1000;
  const cap = opts.maxBackoffMs ?? 30000;
  let attempt = 0;

  const run = async (): Promise<void> => {
    while (true) {
      try {
        const headers: Record<string, string> = {
          'Accept': 'text/event-stream',
          'Content-Type': 'application/json',
          ...(opts.headers ?? {}),
        };
        const csrf = opts.csrfToken ?? readCsrfToken();
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        if (opts.bearer) headers['Authorization'] = `Bearer ${opts.bearer}`;

        const response = await fetch(opts.url, {
          method: 'POST',
          headers,
          body: JSON.stringify(opts.body),
          credentials: 'same-origin',
          signal: controller.signal,
        });

        if (response.status === 429) {
          handlers.onError?.('Rate limit exceeded', 'rate_limited');
          handlers.onClose?.('rate_limited');
          return;
        }
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        if (!response.body) {
          throw new Error('Response has no body');
        }

        handlers.onConnected?.();
        attempt = 0; // reset backoff once connected

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        // Propagate aborts down to the body reader explicitly. A spec-compliant
        // fetch() does this for us, but tests with hand-rolled ReadableStream
        // mocks may not — so we wire it once per attempt.
        const cancelReader = (): void => { reader.cancel().catch(() => undefined); };
        if (controller.signal.aborted) cancelReader();
        else controller.signal.addEventListener('abort', cancelReader, { once: true });

        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });
          while (true) {
            const split = nextBlock(buffer);
            if (!split) break;
            buffer = split.rest;
            const frame = parseFrame(split.block);
            if (!frame) continue;
            handlers.onFrame(frame);
            if (frame.event === 'done') {
              handlers.onClose?.('done');
              return;
            }
          }
        }
        // Stream ended without a `done` frame — treat as transient end-of-stream.
        throw new Error('Stream ended before done');
      } catch (err) {
        if (controller.signal.aborted) {
          handlers.onClose?.('aborted');
          return;
        }
        if (attempt >= maxRetries) {
          const msg = err instanceof Error ? err.message : 'Unknown stream error';
          handlers.onError?.(msg, 'fatal');
          handlers.onClose?.('fatal');
          return;
        }
        const delay = Math.min(cap, initial * 2 ** attempt);
        attempt++;
        await new Promise<void>((resolve) => {
          const t = setTimeout(resolve, jitter(delay));
          if (controller.signal.aborted) clearTimeout(t);
        });
      }
    }
  };

  void run();
  return {
    abort(): void {
      controller.abort();
    },
  };
}
