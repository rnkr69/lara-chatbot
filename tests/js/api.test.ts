import { describe, expect, it, beforeEach } from 'vitest';
import { installApi, markReady } from '../../resources/js/api.js';

declare global {
  interface Window {
    // v2.1.3 (#35) — bundle-specific flags. The pre-2.1.3 names
    // (`__chatbot_initialized__`/`__chatbot_ready__`) are gone.
    __chatbot_widget_initialized__?: true;
    __chatbot_widget_ready__?: true;
  }
}

beforeEach(() => {
  delete (window as Window).Chatbot;
  delete (window as Window).__chatbot_widget_initialized__;
  delete (window as Window).__chatbot_widget_ready__;
});

describe('installApi', () => {
  it('creates window.Chatbot once and is idempotent on a second call', () => {
    const a = installApi();
    const b = installApi();
    expect(a).toBe(b);
    expect(window.Chatbot).toBe(a);
  });

  it('registers tools and exposes them via __internal', () => {
    const api = installApi();
    let received: unknown = null;
    api.registerTool('navigate', (args) => { received = args; });
    const handler = api.__internal.getTool('navigate');
    expect(typeof handler).toBe('function');
    handler!({ url: '/x' }, { actionId: '1', confirmation: 'auto' });
    expect(received).toEqual({ url: '/x' });
  });

  it('rejects invalid tool registrations', () => {
    const api = installApi();
    expect(() => api.registerTool('', () => undefined)).toThrow();
    expect(() => api.registerTool('x', null as unknown as () => void)).toThrow();
  });

  it('registers and recovers block renderers', () => {
    const api = installApi();
    const renderer = (): HTMLElement => document.createElement('div');
    api.registerBlockRenderer('table', renderer);
    expect(api.__internal.getBlockRenderer('table')).toBe(renderer);
    expect(api.__internal.getBlockRenderer('missing')).toBeUndefined();
  });

  it('stores and clears page context', () => {
    const api = installApi();
    api.setPageContext({ route: '/dashboard', tenant: 7 });
    expect(api.__internal.getPageContext()).toEqual({ route: '/dashboard', tenant: 7 });
    api.clearPageContext();
    expect(api.__internal.getPageContext()).toEqual({});
  });

  it('shallow-merges successive setPageContext calls (E14 D14)', () => {
    const api = installApi();
    api.setPageContext({ route: '/dashboard', tenant: 7 });
    api.setPageContext({ tenant: 9, locale: 'es' });
    // route is preserved, tenant overwritten, locale added.
    expect(api.__internal.getPageContext()).toEqual({
      route: '/dashboard',
      tenant: 9,
      locale: 'es',
    });
  });

  it('deep-merges one level when both values at a key are plain objects (#34)', () => {
    const api = installApi();
    api.setPageContext({
      crud: {
        entity: 'mission',
        form: { selector: 'form#x', fields: [{ name: 'a' }] },
        filters: { available: ['destination'], applied: {} },
      },
    });
    // A subsequent partial sync (the Backpack bulk-selection hook) must NOT
    // wipe form / filters / entity.
    api.setPageContext({ crud: { selected_ids: ['7', '12'] } });
    expect(api.__internal.getPageContext()).toEqual({
      crud: {
        entity: 'mission',
        form: { selector: 'form#x', fields: [{ name: 'a' }] },
        filters: { available: ['destination'], applied: {} },
        selected_ids: ['7', '12'],
      },
    });
  });

  it('replaces arrays wholesale even when previous value is an array (#34)', () => {
    const api = installApi();
    api.setPageContext({ tags: ['a', 'b', 'c'] });
    api.setPageContext({ tags: ['x'] });
    expect(api.__internal.getPageContext()).toEqual({ tags: ['x'] });
  });

  it('replaces a previous object with null when explicitly assigned (#34)', () => {
    const api = installApi();
    api.setPageContext({ crud: { entity: 'mission' } });
    api.setPageContext({ crud: null as unknown as Record<string, unknown> });
    expect(api.__internal.getPageContext()).toEqual({ crud: null });
  });

  it('overwrites a previous primitive with an object (no merge with non-object) (#34)', () => {
    const api = installApi();
    api.setPageContext({ crud: 'oops' as unknown as Record<string, unknown> });
    api.setPageContext({ crud: { entity: 'mission' } });
    expect(api.__internal.getPageContext()).toEqual({ crud: { entity: 'mission' } });
  });

  it('rejects non-object page context payloads', () => {
    const api = installApi();
    api.setPageContext({ route: '/x' });
    api.setPageContext(null as unknown as Record<string, unknown>);
    api.setPageContext([1, 2, 3] as unknown as Record<string, unknown>);
    api.setPageContext('not an object' as unknown as Record<string, unknown>);
    expect(api.__internal.getPageContext()).toEqual({ route: '/x' });
  });

  it('emits chatbot:context-changed on setPageContext and clearPageContext (E14 D14)', () => {
    const api = installApi();
    const events: unknown[] = [];
    const listener = (e: Event): void => { events.push((e as CustomEvent).detail); };
    window.addEventListener('chatbot:context-changed', listener);

    api.setPageContext({ route: '/a' });
    api.setPageContext({ tenant: 3 });
    api.clearPageContext();

    expect(events).toEqual([
      { route: '/a' },
      { route: '/a', tenant: 3 },
      {},
    ]);
    window.removeEventListener('chatbot:context-changed', listener);
  });

  it('stores and clears bearer token', () => {
    const api = installApi();
    api.setUser('tok-1');
    expect(api.__internal.getBearer()).toBe('tok-1');
    api.setUser(null);
    expect(api.__internal.getBearer()).toBeNull();
    api.setUser('');
    expect(api.__internal.getBearer()).toBeNull();
  });

  it('registers a navigator and exposes it via __internal.getNavigator', () => {
    const api = installApi();
    expect(api.__internal.getNavigator()).toBeNull();
    const nav = (): void => undefined;
    api.registerNavigator(nav);
    expect(api.__internal.getNavigator()).toBe(nav);
  });

  it('rejects non-function navigator registration', () => {
    const api = installApi();
    expect(() => api.registerNavigator(null as unknown as () => void)).toThrow();
  });

  it('emit*Request fires registered listeners', () => {
    const api = installApi();
    let opened = 0;
    let closed = 0;
    let toggled = 0;
    api.__internal.onOpenRequest(() => { opened++; });
    api.__internal.onCloseRequest(() => { closed++; });
    api.__internal.onToggleRequest(() => { toggled++; });
    api.open();
    api.close();
    api.toggle();
    expect([opened, closed, toggled]).toEqual([1, 1, 1]);
  });

  describe('whenReady (v1.1 findings #8)', () => {
    it('invokes the callback on the next microtask when the bundle is already ready', async () => {
      const api = installApi();
      markReady();

      let called = false;
      let calledSync = true;
      api.whenReady(() => { called = true; });
      // Should NOT have run synchronously inside whenReady().
      calledSync = called;

      await Promise.resolve();
      expect(calledSync).toBe(false);
      expect(called).toBe(true);
    });

    it('subscribes to the chatbot:ready event when not yet ready, and fires once', () => {
      const api = installApi();
      // Note: NO markReady() yet.
      let calls = 0;
      api.whenReady(() => { calls++; });
      expect(calls).toBe(0);

      markReady();
      expect(calls).toBe(1);

      // Subsequent ready emissions must not re-run the once-listener.
      document.dispatchEvent(new CustomEvent('chatbot:ready'));
      expect(calls).toBe(1);
    });

    it('markReady is idempotent and only emits chatbot:ready once', () => {
      installApi();
      let emissions = 0;
      const listener = (): void => { emissions++; };
      document.addEventListener('chatbot:ready', listener);

      markReady();
      markReady();
      markReady();

      expect(emissions).toBe(1);
      document.removeEventListener('chatbot:ready', listener);
    });

    it('ignores non-function callbacks safely', () => {
      const api = installApi();
      markReady();
      // Must not throw on bogus input — the contract is "best-effort".
      expect(() => api.whenReady(null as unknown as () => void)).not.toThrow();
      expect(() => api.whenReady(undefined as unknown as () => void)).not.toThrow();
    });
  });

  describe('v2.1.3 (#35) — shim upgrade when chatbot-dashboard.js loaded first', () => {
    it('upgrades the dashboard bundle\'s shim to a real API and copies its renderers', () => {
      // Simulate the dashboard bundle's `installChatbotShim()` having run first:
      // window.Chatbot is a shim tagged with `__chatbot_shim__` and the built-in
      // chart renderer has been registered against it.
      const chartRenderer = (): HTMLElement => document.createElement('div');
      const renderers = new Map<string, () => HTMLElement>();
      renderers.set('chart', chartRenderer);
      const shim = {
        __chatbot_shim__: true as const,
        open: () => undefined,
        close: () => undefined,
        toggle: () => undefined,
        setPageContext: () => undefined,
        clearPageContext: () => undefined,
        registerTool: () => undefined,
        registerBlockRenderer: () => undefined,
        registerNavigator: () => undefined,
        setUser: () => undefined,
        newChat: () => undefined,
        whenReady: () => undefined,
        __internal: {
          getTool: () => undefined,
          getBlockRenderer: (type: string) => renderers.get(type),
          getNavigator: () => null,
          getPageContext: () => ({}),
          getBearer: () => null,
          emitOpen: () => undefined,
          emitClose: () => undefined,
          emitToggle: () => undefined,
          emitNewChat: () => undefined,
          onOpenRequest: () => undefined,
          onCloseRequest: () => undefined,
          onToggleRequest: () => undefined,
          onNewChatRequest: () => undefined,
        },
      };
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      window.Chatbot = shim as any;

      const api = installApi();

      // The shim must have been REPLACED with the real API — `whenReady` is no
      // longer a no-op (the real one defers via microtask).
      expect(window.Chatbot).toBe(api);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((window.Chatbot as any).__chatbot_shim__).toBeUndefined();

      // The chart renderer the shim accumulated survives the upgrade.
      expect(api.__internal.getBlockRenderer('chart')).toBe(chartRenderer);

      // registerTool is functional now (was a no-op on the shim).
      let invoked = false;
      api.registerTool('navigate', () => { invoked = true; });
      api.__internal.getTool('navigate')!({}, { actionId: 'a', confirmation: 'auto' });
      expect(invoked).toBe(true);
    });

    it('does not flag a real API as a shim on the second installApi() call', () => {
      const first = installApi();
      const second = installApi();
      expect(second).toBe(first);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((window.Chatbot as any).__chatbot_shim__).toBeUndefined();
    });
  });
});
