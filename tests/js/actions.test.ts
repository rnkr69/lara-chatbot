import { describe, expect, it, beforeEach, vi } from 'vitest';
import { installApi } from '../../resources/js/api.js';
import { handleFrontendAction } from '../../resources/js/actions.js';

beforeEach(() => {
  delete (window as Window).Chatbot;
  delete (window as Window).__chatbot_widget_initialized__;
  installApi();
  document.body.innerHTML = '';
});

function env() {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const toasts: { message: string; duration: number }[] = [];
  return {
    host,
    toasts,
    showToast: (message: string, duration: number) => { toasts.push({ message, duration }); },
  };
}

describe('handleFrontendAction primitives (confirmation=auto)', () => {
  it('navigate calls window.location.assign with the same-origin URL', () => {
    const e = env();
    const assign = vi.fn();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign, origin: 'http://localhost', href: 'http://localhost/' },
    });
    handleFrontendAction(
      { tool: 'navigate', args: { url: '/dashboard' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(assign).toHaveBeenCalledWith('/dashboard');
  });

  it('navigate ignores cross-origin URLs', () => {
    const e = env();
    const assign = vi.fn();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign, origin: 'http://localhost', href: 'http://localhost/' },
    });
    handleFrontendAction(
      { tool: 'navigate', args: { url: 'https://evil.test/x' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(assign).not.toHaveBeenCalled();
  });

  it('toggle_visibility flips display none', () => {
    const target = document.createElement('div');
    target.id = 'foo';
    document.body.appendChild(target);
    const e = env();
    handleFrontendAction(
      { tool: 'toggle_visibility', args: { selector: '#foo' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(target.style.display).toBe('none');
    handleFrontendAction(
      { tool: 'toggle_visibility', args: { selector: '#foo', visible: true }, action_id: 'a2', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(target.style.display).toBe('');
  });

  it('show_toast pushes to the env queue', () => {
    const e = env();
    handleFrontendAction(
      { tool: 'show_toast', args: { message: 'hi', duration: 2000 }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(e.toasts).toEqual([{ message: 'hi', duration: 2000 }]);
  });

  it('download_file creates an anchor and clicks it for an http(s) URL', () => {
    const e = env();
    const created: HTMLAnchorElement[] = [];
    const orig = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      const el = orig(tag);
      if (tag === 'a') created.push(el as HTMLAnchorElement);
      return el;
    });
    handleFrontendAction(
      {
        tool: 'download_file',
        args: { download_url: 'https://signed.example/x.pdf', filename: 'invoice.pdf' },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(created.length).toBe(1);
    const anchor = created[0]!;
    expect(anchor.href).toBe('https://signed.example/x.pdf');
    expect(anchor.download).toBe('invoice.pdf');
  });

  it('download_file rejects non-http URLs', () => {
    const e = env();
    const orig = document.createElement.bind(document);
    let anchorCount = 0;
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      if (tag === 'a') anchorCount++;
      return orig(tag);
    });
    handleFrontendAction(
      { tool: 'download_file', args: { download_url: 'javascript:alert(1)' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(anchorCount).toBe(0);
  });

  it('navigate consults a registered navigator before falling back to location.assign', () => {
    const e = env();
    const assign = vi.fn();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign, origin: 'http://localhost', href: 'http://localhost/' },
    });
    const navCalls: string[] = [];
    window.Chatbot!.registerNavigator((url) => { navCalls.push(url); });
    handleFrontendAction(
      { tool: 'navigate', args: { url: '/dashboard' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(navCalls).toEqual(['/dashboard']);
    expect(assign).not.toHaveBeenCalled();
  });

  it('a registered tool wins over a registered navigator (cascade order)', () => {
    const e = env();
    const calls: string[] = [];
    const navCalls: string[] = [];
    window.Chatbot!.registerTool('navigate', (args) => {
      calls.push(String((args as { url: string }).url));
    });
    window.Chatbot!.registerNavigator((url) => { navCalls.push(url); });
    handleFrontendAction(
      { tool: 'navigate', args: { url: '/x' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(calls).toEqual(['/x']);
    expect(navCalls).toEqual([]);
  });

  it('delegates to a host-registered tool when present (overrides primitive)', () => {
    const calls: unknown[] = [];
    window.Chatbot!.registerTool('navigate', (args) => { calls.push(args); });
    const assign = vi.fn();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...window.location, assign, origin: 'http://localhost', href: 'http://localhost/' } });
    const e = env();
    handleFrontendAction(
      { tool: 'navigate', args: { url: '/x' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(calls).toEqual([{ url: '/x' }]);
    expect(assign).not.toHaveBeenCalled();
  });
});

describe('fill_form primitive (v1.1 findings #4)', () => {
  it('fills a form looked up by id and dispatches input+change events on each control', () => {
    const e = env();
    const form = document.createElement('form');
    form.id = 'mission-form';
    form.innerHTML = '<input name="origin_planet_id"><input name="priority">';
    document.body.appendChild(form);

    const events: string[] = [];
    form.querySelectorAll('input').forEach((inp) => {
      inp.addEventListener('input', () => events.push(`input:${inp.name}`));
      inp.addEventListener('change', () => events.push(`change:${inp.name}`));
    });

    handleFrontendAction(
      {
        tool: 'fill_form',
        args: {
          form_id: 'mission-form',
          fields: [
            { name: 'origin_planet_id', value: 2 },
            { name: 'priority', value: 'express' },
          ],
        },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );

    const inputs = form.querySelectorAll('input');
    expect(inputs[0]!.value).toBe('2');
    expect(inputs[1]!.value).toBe('express');
    expect(events).toEqual([
      'input:origin_planet_id', 'change:origin_planet_id',
      'input:priority', 'change:priority',
    ]);
  });

  it('auto-discovers a form on the page when form_id is empty (Backpack pattern)', () => {
    const e = env();
    // Simulate a Backpack create page: the only form lives at form#crudTable.
    const form = document.createElement('form');
    form.id = 'crudTable';
    form.innerHTML = '<input name="name">';
    document.body.appendChild(form);

    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    handleFrontendAction(
      {
        tool: 'fill_form',
        args: { fields: [{ name: 'name', value: 'Aurora' }] },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(form.querySelector('input')!.value).toBe('Aurora');
    // Auto-discovery announces itself so operators can tighten the prompt.
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });

  it('warns with a list of available form ids when no form matches', () => {
    const e = env();
    const a = document.createElement('form'); a.id = 'a';
    const b = document.createElement('form'); b.id = 'b';
    document.body.append(a, b);

    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    handleFrontendAction(
      {
        tool: 'fill_form',
        args: { form_id: 'does-not-exist', fields: [{ name: 'x', value: 1 }] },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(warn).toHaveBeenCalled();
    const lastCall = warn.mock.calls[warn.mock.calls.length - 1];
    expect(JSON.stringify(lastCall)).toContain('a');
    expect(JSON.stringify(lastCall)).toContain('b');
    warn.mockRestore();
  });

  it('warns with the available [name] attributes when a field is missing on the form', () => {
    const e = env();
    const form = document.createElement('form');
    form.id = 'mission-form';
    form.innerHTML = '<input name="origin_planet_id"><input name="destination_planet_id">';
    document.body.appendChild(form);

    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    handleFrontendAction(
      {
        tool: 'fill_form',
        args: {
          form_id: 'mission-form',
          fields: [
            // The LLM guessed the friendly name instead of the FK column.
            { name: 'origin', value: 'Earth' },
          ],
        },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(warn).toHaveBeenCalled();
    const flat = JSON.stringify(warn.mock.calls);
    expect(flat).toContain('origin');
    expect(flat).toContain('destination_planet_id');
    warn.mockRestore();
  });
});

describe('handleFrontendAction confirm/manual (E16 — routed by widget)', () => {
  it('does not run primitives when confirmation is not auto and warns the host', () => {
    const e = env();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const toolCalls: unknown[] = [];
    window.Chatbot!.registerTool('fill_form', (args) => { toolCalls.push(args); });
    handleFrontendAction(
      { tool: 'fill_form', args: { fields: [] }, action_id: 'pending-1', confirmation: 'confirm' },
      { hostElement: e.host, showToast: e.showToast },
    );
    // The widget routes confirm/manual through confirm.ts before reaching this
    // path; if we reach here directly, the function should warn and not run.
    expect(toolCalls).toEqual([]);
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });
});

// v1.1.3 (#16): primitives return structured PrimitiveResult so the widget
// can POST-back failures to the backend and surface a toast to the user.
describe('PrimitiveResult shape (v1.1.3 #16)', () => {
  it('returns {ok:true} on happy paths', () => {
    const e = env();
    const target = document.createElement('div');
    target.id = 'foo';
    document.body.appendChild(target);

    const result = handleFrontendAction(
      { tool: 'toggle_visibility', args: { selector: '#foo' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(result).toEqual({ ok: true });
  });

  it('returns {ok:false, error:no_form_matched, ...} when fill_form misses', () => {
    const e = env();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = handleFrontendAction(
      {
        tool: 'fill_form',
        args: { form_id: 'filtersForm', fields: [{ name: 'status', value: 'approved' }] },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );

    expect(result).toMatchObject({
      ok: false,
      error: 'no_form_matched',
    });
    expect((result as { message: string }).message).toContain('could not locate a form');
    expect((result as unknown as { available_forms: string[] }).available_forms).toEqual([]);
    warn.mockRestore();
  });

  it('returns {ok:false, error:cross_origin} when navigate sees a cross-origin URL', () => {
    const e = env();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, assign: vi.fn(), origin: 'http://localhost', href: 'http://localhost/' },
    });

    const result = handleFrontendAction(
      { tool: 'navigate', args: { url: 'https://evil.test/x' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );

    expect(result).toMatchObject({ ok: false, error: 'cross_origin' });
  });

  it('returns {ok:false, error:non_http_url} when download_file gets a javascript: URL', () => {
    const e = env();
    const result = handleFrontendAction(
      { tool: 'download_file', args: { download_url: 'javascript:alert(1)' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(result).toMatchObject({ ok: false, error: 'non_http_url' });
  });

  it('returns {ok:false, error:no_handler} when invoke_host_action targets an unregistered name', () => {
    const e = env();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = handleFrontendAction(
      { tool: 'invoke_host_action', args: { action_name: 'doesNotExist' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );

    expect(result).toMatchObject({ ok: false, error: 'no_handler' });
    expect((result as unknown as { action_name: string }).action_name).toBe('doesNotExist');
    warn.mockRestore();
  });

  it('returns {ok:false, error:no_match} when toggle_visibility finds nothing', () => {
    const e = env();
    const result = handleFrontendAction(
      { tool: 'toggle_visibility', args: { selector: '#missing-element' }, action_id: 'a1', confirmation: 'auto' },
      { hostElement: e.host, showToast: e.showToast },
    );
    expect(result).toMatchObject({ ok: false, error: 'no_match' });
  });

  it('returns {ok:false, error:fields_not_found} when fill_form locates the form but a field is missing', () => {
    const e = env();
    const form = document.createElement('form');
    form.id = 'mission-form';
    form.innerHTML = '<input name="name">';
    document.body.appendChild(form);
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

    const result = handleFrontendAction(
      {
        tool: 'fill_form',
        args: {
          form_id: 'mission-form',
          fields: [
            { name: 'name', value: 'Aurora' },
            { name: 'departure_at', value: '2026-05-19T10:00' },
          ],
        },
        action_id: 'a1',
        confirmation: 'auto',
      },
      { hostElement: e.host, showToast: e.showToast },
    );

    expect(result).toMatchObject({ ok: false, error: 'fields_not_found' });
    expect((result as unknown as { missing_fields: string[] }).missing_fields).toEqual(['departure_at']);
    // The fields that DID match are still applied.
    expect((form.querySelector('input[name="name"]') as HTMLInputElement).value).toBe('Aurora');
    warn.mockRestore();
  });
});
