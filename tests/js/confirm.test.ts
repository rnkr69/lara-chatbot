import { describe, expect, it, beforeEach, vi } from 'vitest';
import {
  attachConfirmBanner,
  deriveConfirmUrl,
  postConfirm,
  type ConfirmEnvironment,
  type PendingActionResponse,
} from '../../resources/js/confirm.js';
import type { FrontendActionPayload } from '../../resources/js/types.js';

beforeEach(() => {
  document.body.innerHTML = '';
  vi.unstubAllGlobals();
});

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

type FetchHandler = (input: RequestInfo | URL, init?: RequestInit) => Promise<Response>;

function makeFetchMock(handler: FetchHandler) {
  return vi.fn<FetchHandler>(handler);
}

// vitest's Mock<T>.mock.calls is typed as [input: ..., init?: ...] with named
// tuple labels — use a permissive `unknown[]` here to avoid type-tuple mismatch.
function bodyOf(call: unknown[] | undefined): Record<string, unknown> {
  if (!call) return {};
  const init = call[1] as RequestInit | undefined;
  if (!init || typeof init.body !== 'string') return {};
  return JSON.parse(init.body) as Record<string, unknown>;
}

function initOf(call: unknown[] | undefined): RequestInit {
  return (call?.[1] as RequestInit | undefined) ?? {};
}

function makePending(overrides: Partial<PendingActionResponse> = {}): PendingActionResponse {
  return {
    id: 1,
    action_id: 'aaaaaaaa-aaaa-aaaa-aaaa-000000000001',
    status: 'pending',
    confirmation: 'confirm',
    tool: 'confirm_dialog',
    args: { message: 'Run?' },
    result: null,
    expires_at: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

function basePayload(overrides: Partial<FrontendActionPayload> = {}): FrontendActionPayload {
  return {
    tool: 'confirm_dialog',
    args: { message: 'Run?' },
    action_id: 'aaaaaaaa-aaaa-aaaa-aaaa-000000000001',
    confirmation: 'confirm',
    ...overrides,
  };
}

function makeEnv(overrides: Partial<ConfirmEnvironment> = {}): ConfirmEnvironment {
  const parent = document.createElement('div');
  document.body.appendChild(parent);
  return {
    parent,
    showToast: vi.fn(),
    executePrimitive: vi.fn(async () => {}) as unknown as ConfirmEnvironment['executePrimitive'],
    streamEndpoint: '/chatbot/stream',
    bearer: null,
    ...overrides,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// deriveConfirmUrl
// ─────────────────────────────────────────────────────────────────────────

describe('deriveConfirmUrl', () => {
  it('replaces a trailing /stream with /actions/{id}/confirm', () => {
    expect(deriveConfirmUrl('/chatbot/stream', 'abc'))
      .toBe('/chatbot/actions/abc/confirm');
  });

  it('handles a custom prefix', () => {
    expect(deriveConfirmUrl('/api/v1/bot/stream', 'xyz'))
      .toBe('/api/v1/bot/actions/xyz/confirm');
  });

  it('falls back to a default when streamEndpoint is empty', () => {
    expect(deriveConfirmUrl('', 'abc'))
      .toBe('/chatbot/actions/abc/confirm');
  });

  it('strips a trailing segment when the URL does not end with /stream', () => {
    expect(deriveConfirmUrl('/chatbot/foo', 'abc'))
      .toBe('/chatbot/actions/abc/confirm');
  });

  it('handles trailing slashes in the streamEndpoint', () => {
    expect(deriveConfirmUrl('/chatbot/stream/', 'abc'))
      .toBe('/chatbot/actions/abc/confirm');
  });
});

// ─────────────────────────────────────────────────────────────────────────
// postConfirm
// ─────────────────────────────────────────────────────────────────────────

describe('postConfirm', () => {
  it('POSTs JSON with credentials same-origin and parses {data: ...}', async () => {
    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending({ status: 'rejected' }) }));
    vi.stubGlobal('fetch', fetchMock);

    const r = await postConfirm('/chatbot/actions/x/confirm', { accept: false }, null);

    expect(r.ok).toBe(true);
    expect(r.status).toBe(200);
    expect(r.data?.status).toBe('rejected');

    expect(fetchMock).toHaveBeenCalledWith('/chatbot/actions/x/confirm', expect.objectContaining({
      method: 'POST',
      credentials: 'same-origin',
    }));
    const init = initOf(fetchMock.mock.calls[0]);
    const headers = init.headers as Record<string, string>;
    expect(headers['Content-Type']).toBe('application/json');
    expect(headers['Accept']).toBe('application/json');
    expect(bodyOf(fetchMock.mock.calls[0])).toEqual({ accept: false });
  });

  it('attaches X-CSRF-TOKEN from the meta tag when present', async () => {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'csrf-token-xyz');
    document.head.appendChild(meta);

    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending() }));
    vi.stubGlobal('fetch', fetchMock);

    await postConfirm('/chatbot/actions/x/confirm', { accept: true }, null);

    const headers = initOf(fetchMock.mock.calls[0]).headers as Record<string, string>;
    expect(headers['X-CSRF-TOKEN']).toBe('csrf-token-xyz');

    meta.remove();
  });

  it('attaches Authorization Bearer when bearer is provided', async () => {
    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending() }));
    vi.stubGlobal('fetch', fetchMock);

    await postConfirm('/chatbot/actions/x/confirm', { accept: true }, 'jwt-abc');

    const headers = initOf(fetchMock.mock.calls[0]).headers as Record<string, string>;
    expect(headers['Authorization']).toBe('Bearer jwt-abc');
  });

  it('parses {pending_action: ...} on 409', async () => {
    const fetchMock = makeFetchMock(async () => jsonResponse(
      { message: 'Pending action expired.', pending_action: makePending({ status: 'expired' }) },
      409,
    ));
    vi.stubGlobal('fetch', fetchMock);

    const r = await postConfirm('/chatbot/actions/x/confirm', { accept: true }, null);
    expect(r.ok).toBe(false);
    expect(r.status).toBe(409);
    expect(r.data?.status).toBe('expired');
  });

  it('returns ok=false with status=0 when fetch throws', async () => {
    const errSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    vi.stubGlobal('fetch', makeFetchMock(async () => { throw new Error('boom'); }));

    const r = await postConfirm('/chatbot/actions/x/confirm', { accept: true }, null);

    expect(r.ok).toBe(false);
    expect(r.status).toBe(0);
    expect(r.data).toBeNull();
    errSpy.mockRestore();
  });
});

// ─────────────────────────────────────────────────────────────────────────
// attachConfirmBanner — DOM, accept/reject flows
// ─────────────────────────────────────────────────────────────────────────

describe('attachConfirmBanner — UI render', () => {
  it('renders a confirm banner with Accept / Reject buttons for confirmation=confirm', () => {
    const env = makeEnv();
    attachConfirmBanner(basePayload(), env);

    const banner = env.parent.querySelector<HTMLElement>('.cb-confirm-banner');
    expect(banner).not.toBeNull();
    expect(banner!.dataset['actionId']).toBe('aaaaaaaa-aaaa-aaaa-aaaa-000000000001');
    expect(banner!.dataset['confirmation']).toBe('confirm');

    const accept = banner!.querySelector<HTMLButtonElement>('.cb-confirm-accept');
    const reject = banner!.querySelector<HTMLButtonElement>('.cb-confirm-reject');
    expect(accept?.textContent).toBe('Accept');
    expect(reject?.textContent).toBe('Reject');
  });

  it('renders a manual banner with "Mark as done" / "Mark as not done" labels', () => {
    const env = makeEnv();
    attachConfirmBanner(basePayload({ confirmation: 'manual' }), env);

    const banner = env.parent.querySelector<HTMLElement>('.cb-confirm-banner');
    expect(banner).not.toBeNull();
    expect(banner!.dataset['confirmation']).toBe('manual');

    const accept = banner!.querySelector<HTMLButtonElement>('.cb-confirm-accept');
    const reject = banner!.querySelector<HTMLButtonElement>('.cb-confirm-reject');
    expect(accept?.textContent).toBe('Mark as done');
    expect(reject?.textContent).toBe('Mark as not done');
  });
});

describe('attachConfirmBanner — confirm flow (two-step)', () => {
  it('Accept fires two POSTs (confirm then executed) and runs the primitive in between', async () => {
    const env = makeEnv();
    const exec = vi.fn(async () => {});
    env.executePrimitive = exec as unknown as ConfirmEnvironment['executePrimitive'];

    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending() }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload(), env);

    const accept = env.parent.querySelector<HTMLButtonElement>('.cb-confirm-accept')!;
    accept.click();

    // Wait microtasks.
    await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
    await new Promise<void>((r) => setTimeout(r, 0));
    await new Promise<void>((r) => setTimeout(r, 0));

    expect(fetchMock).toHaveBeenCalledTimes(2);

    const first  = bodyOf(fetchMock.mock.calls[0]);
    const second = bodyOf(fetchMock.mock.calls[1]);

    expect(first).toEqual({ accept: true });
    expect(second).toMatchObject({ accept: true });
    expect(second['result']).toBeDefined();

    expect(exec).toHaveBeenCalledOnce();

    // Banner removed after success.
    expect(env.parent.querySelector('.cb-confirm-banner')).toBeNull();
    expect(env.showToast).toHaveBeenCalled();
  });

  it('reports primitive failure in the second POST result', async () => {
    const env = makeEnv();
    env.executePrimitive = vi.fn(async () => { throw new Error('primitive blew up'); });

    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending() }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload(), env);
    env.parent.querySelector<HTMLButtonElement>('.cb-confirm-accept')!.click();
    await new Promise<void>((r) => setTimeout(r, 0));
    await new Promise<void>((r) => setTimeout(r, 0));

    expect(fetchMock).toHaveBeenCalledTimes(2);
    const second = bodyOf(fetchMock.mock.calls[1]);
    const result = second['result'] as Record<string, unknown>;
    expect(result['ok']).toBe(false);
    expect(typeof result['error']).toBe('string');
  });

  it('Reject fires a single POST {accept:false} and dismisses the banner', async () => {
    const env = makeEnv();
    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending({ status: 'rejected' }) }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload(), env);
    env.parent.querySelector<HTMLButtonElement>('.cb-confirm-reject')!.click();
    await new Promise<void>((r) => setTimeout(r, 0));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(bodyOf(fetchMock.mock.calls[0])).toEqual({ accept: false });
    expect(env.parent.querySelector('.cb-confirm-banner')).toBeNull();
  });

  it('keeps the banner and surfaces a status message when the first POST fails', async () => {
    const env = makeEnv();
    const fetchMock = makeFetchMock(async () => new Response('', { status: 500 }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload(), env);
    env.parent.querySelector<HTMLButtonElement>('.cb-confirm-accept')!.click();
    await new Promise<void>((r) => setTimeout(r, 0));

    // The banner is still in the DOM; the status line is visible.
    const banner = env.parent.querySelector<HTMLElement>('.cb-confirm-banner');
    expect(banner).not.toBeNull();
    const status = banner!.querySelector<HTMLElement>('.cb-confirm-status')!;
    expect(status.hidden).toBe(false);
    expect(status.textContent ?? '').toContain('HTTP 500');
  });
});

describe('attachConfirmBanner — manual flow', () => {
  it('Mark-as-done fires a single POST {accept:true, result:{done:true}}', async () => {
    const env = makeEnv();
    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending({ confirmation: 'manual', status: 'executed' }) }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload({ confirmation: 'manual' }), env);
    env.parent.querySelector<HTMLButtonElement>('.cb-confirm-accept')!.click();
    await new Promise<void>((r) => setTimeout(r, 0));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(bodyOf(fetchMock.mock.calls[0]))
      .toEqual({ accept: true, result: { done: true } });
    expect(env.parent.querySelector('.cb-confirm-banner')).toBeNull();
  });

  it('Mark-as-not-done fires {accept:false}', async () => {
    const env = makeEnv();
    const fetchMock = makeFetchMock(async () => jsonResponse({ data: makePending({ confirmation: 'manual', status: 'rejected' }) }));
    vi.stubGlobal('fetch', fetchMock);

    attachConfirmBanner(basePayload({ confirmation: 'manual' }), env);
    env.parent.querySelector<HTMLButtonElement>('.cb-confirm-reject')!.click();
    await new Promise<void>((r) => setTimeout(r, 0));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(bodyOf(fetchMock.mock.calls[0])).toEqual({ accept: false });
  });
});
