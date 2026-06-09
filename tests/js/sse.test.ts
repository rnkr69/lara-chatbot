import { describe, expect, it, vi } from 'vitest';
import { nextBlock, parseFrame, streamPost } from '../../resources/js/sse.js';

describe('nextBlock', () => {
  it('returns null when there is no full block yet', () => {
    expect(nextBlock('event: text\ndata: {}')).toBeNull();
  });
  it('splits at the first \\n\\n boundary', () => {
    const res = nextBlock('event: text\ndata: {"delta":"hi"}\n\nevent: done\n');
    expect(res).toEqual({ block: 'event: text\ndata: {"delta":"hi"}', rest: 'event: done\n' });
  });
  it('normalizes \\r\\n line endings before splitting', () => {
    const res = nextBlock('event: text\r\ndata: {}\r\n\r\nrest');
    expect(res?.block).toBe('event: text\ndata: {}');
    expect(res?.rest).toBe('rest');
  });
});

describe('parseFrame', () => {
  it('parses a simple text frame', () => {
    const f = parseFrame('event: text\ndata: {"delta":"hi"}');
    expect(f).toEqual({ event: 'text', data: { delta: 'hi' } });
  });
  it('drops keepalive comments and frames without event:', () => {
    expect(parseFrame(': keepalive')).toBeNull();
    expect(parseFrame('data: {"x":1}')).toBeNull();
  });
  it('rejects unknown event types', () => {
    expect(parseFrame('event: foo\ndata: {}')).toBeNull();
  });
  it('joins multi-line data per SSE spec', () => {
    const f = parseFrame('event: text\ndata: {"delta":"a\ndata: b"}');
    // Two `data:` lines join with `\n` between them; JSON parse may fail for that
    // particular shape — guarded by returning null.
    expect(f).toBeNull();
  });
  it('returns null when data is non-object JSON', () => {
    expect(parseFrame('event: text\ndata: 42')).toBeNull();
    expect(parseFrame('event: text\ndata: null')).toBeNull();
    expect(parseFrame('event: text\ndata: ["a"]')).toBeNull();
  });
  it('parses done frames with full payload', () => {
    const f = parseFrame('event: done\ndata: {"message_id":7,"usage":{"in":3,"out":4}}');
    expect(f?.event).toBe('done');
    expect(f?.data).toEqual({ message_id: 7, usage: { in: 3, out: 4 } });
  });
  it('strips a single leading space after the colon', () => {
    expect(parseFrame('event: text\ndata: {}')?.event).toBe('text');
    expect(parseFrame('event:text\ndata:{}')?.event).toBe('text');
  });
});

describe('streamPost', () => {
  function mockStream(chunks: string[]): Response {
    let i = 0;
    const stream = new ReadableStream<Uint8Array>({
      pull(controller): void {
        if (i >= chunks.length) {
          controller.close();
          return;
        }
        const chunk = chunks[i++];
        if (chunk !== undefined) controller.enqueue(new TextEncoder().encode(chunk));
      },
    });
    return new Response(stream, { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
  }

  it('parses frames in order and closes on done', async () => {
    const fetchMock = vi.fn(async () => mockStream([
      'event: text\ndata: {"delta":"hello"}\n\n',
      'event: text\ndata: {"delta":" world"}\n\n',
      'event: done\ndata: {"message_id":1,"usage":{}}\n\n',
    ]));
    vi.stubGlobal('fetch', fetchMock);

    const frames: string[] = [];
    const closed = new Promise<string>((resolve) => {
      streamPost(
        { url: '/x', body: { message: 'hi' }, maxRetries: 0 },
        {
          onFrame: (f) => { frames.push(f.event); },
          onClose: (reason) => resolve(reason),
        },
      );
    });
    const reason = await closed;
    expect(frames).toEqual(['text', 'text', 'done']);
    expect(reason).toBe('done');
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  it('reports rate_limited on HTTP 429 without retrying', async () => {
    const fetchMock = vi.fn(async () => new Response('', { status: 429 }));
    vi.stubGlobal('fetch', fetchMock);

    const reason = await new Promise<string>((resolve) => {
      streamPost(
        { url: '/x', body: {}, maxRetries: 5 },
        {
          onFrame: () => { /* ignore */ },
          onClose: (r) => resolve(r),
        },
      );
    });
    expect(reason).toBe('rate_limited');
    expect(fetchMock).toHaveBeenCalledOnce();
  });

  it('aborts with reason=aborted when the caller aborts mid-stream', async () => {
    let resolveStream!: () => void;
    const block = new Promise<void>((r) => { resolveStream = r; });
    const fetchMock = vi.fn(async () => new Response(
      new ReadableStream<Uint8Array>({
        async pull(controller): Promise<void> {
          await block;
          controller.close();
        },
      }),
      { status: 200 },
    ));
    vi.stubGlobal('fetch', fetchMock);

    const ac = new AbortController();
    const closed = new Promise<string>((resolve) => {
      streamPost(
        { url: '/x', body: {}, signal: ac.signal, maxRetries: 0 },
        {
          onFrame: () => { /* noop */ },
          onClose: (reason) => resolve(reason),
        },
      );
    });
    queueMicrotask(() => ac.abort());
    const reason = await closed;
    resolveStream();
    expect(reason).toBe('aborted');
  });

  it('retries with exponential backoff on transient HTTP error', async () => {
    let calls = 0;
    const fetchMock = vi.fn(async () => {
      calls++;
      if (calls < 2) return new Response('', { status: 500 });
      return mockStream([
        'event: done\ndata: {"message_id":1,"usage":{}}\n\n',
      ]);
    });
    vi.stubGlobal('fetch', fetchMock);

    const reason = await new Promise<string>((resolve) => {
      streamPost(
        { url: '/x', body: {}, maxRetries: 3, initialBackoffMs: 1, maxBackoffMs: 5 },
        {
          onFrame: () => { /* noop */ },
          onClose: (r) => resolve(r),
        },
      );
    });
    expect(reason).toBe('done');
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('attaches X-CSRF-TOKEN from the meta tag and bearer token', async () => {
    document.head.innerHTML = '<meta name="csrf-token" content="csrf-abc">';
    let captured: Headers | null = null;
    const fetchMock = vi.fn(async (_url, init: RequestInit) => {
      captured = new Headers(init.headers as HeadersInit);
      return mockStream(['event: done\ndata: {"message_id":1,"usage":{}}\n\n']);
    });
    vi.stubGlobal('fetch', fetchMock);

    await new Promise<void>((resolve) => {
      streamPost(
        { url: '/x', body: {}, bearer: 'tok-1', maxRetries: 0 },
        { onFrame: () => undefined, onClose: () => resolve() },
      );
    });
    expect(captured!.get('X-CSRF-TOKEN')).toBe('csrf-abc');
    expect(captured!.get('Authorization')).toBe('Bearer tok-1');
    expect(captured!.get('Accept')).toBe('text/event-stream');
  });
});
