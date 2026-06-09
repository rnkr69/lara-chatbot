import { describe, expect, it, vi, beforeEach } from 'vitest';
import { wrapWithPinButton } from '../../../resources/js/dashboard/pin-button.js';
import type { BlockPayload } from '../../../resources/js/types.js';

function makeBlock(overrides: Partial<BlockPayload> = {}): BlockPayload {
  return {
    type: 'table',
    data: { caption: 'Users' },
    id: 'block-uuid-1',
    pinnable: true,
    source: { tool: 'list_users', args: { limit: 10 } },
    ...overrides,
  };
}

function makeRendered(): HTMLElement {
  const el = document.createElement('div');
  el.className = 'block block-table';
  el.textContent = 'rendered table';
  return el;
}

beforeEach(() => {
  document.body.innerHTML = '';
});

describe('wrapWithPinButton', () => {
  it('returns the rendered element AS-IS when block is not pinnable', () => {
    const rendered = makeRendered();
    const block = makeBlock({ pinnable: false });
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out).toBe(rendered);
    expect(out.classList.contains('cb-pin-wrapper')).toBe(false);
  });

  it('returns rendered AS-IS when source is missing', () => {
    const rendered = makeRendered();
    // Build the block fresh (omitting `source` entirely): the project's
    // tsconfig sets exactOptionalPropertyTypes=true so we can't pass
    // `{ source: undefined }` through the spread.
    const block: BlockPayload = {
      type: 'table', data: { caption: 'Users' }, id: 'block-uuid-1', pinnable: true,
    };
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out).toBe(rendered);
  });

  it('returns rendered AS-IS when source.tool is empty', () => {
    const rendered = makeRendered();
    const block = makeBlock({ source: { tool: '', args: {} } });
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out).toBe(rendered);
  });

  it('wraps the rendered element with a pin button when pinnable + source set', () => {
    const rendered = makeRendered();
    const block = makeBlock();
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out).not.toBe(rendered);
    expect(out.classList.contains('cb-pin-wrapper')).toBe(true);
    expect(out.contains(rendered)).toBe(true);
    const btn = out.querySelector<HTMLButtonElement>('.cb-pin-button');
    expect(btn).not.toBeNull();
    expect(btn!.getAttribute('aria-label')).toBe('Pin to dashboard');
  });

  it('forwards block.id onto the wrapper data attribute', () => {
    const rendered = makeRendered();
    const block = makeBlock({ id: 'abc-123' });
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out.dataset['blockId']).toBe('abc-123');
  });

  it('omits the data attribute when block.id is missing', () => {
    const rendered = makeRendered();
    const block: BlockPayload = {
      type: 'table', data: { caption: 'Users' }, pinnable: true,
      source: { tool: 'list_users', args: { limit: 10 } },
    };
    const out = wrapWithPinButton({ block, rendered, onPin: vi.fn() });
    expect(out.dataset['blockId']).toBeUndefined();
  });

  it('invokes onPin with the block and rendered element on click', () => {
    const rendered = makeRendered();
    const block = makeBlock();
    const onPin = vi.fn();
    const out = wrapWithPinButton({ block, rendered, onPin });
    document.body.appendChild(out);
    const btn = out.querySelector<HTMLButtonElement>('.cb-pin-button')!;
    btn.click();
    expect(onPin).toHaveBeenCalledTimes(1);
    expect(onPin).toHaveBeenCalledWith(block, rendered);
  });

  it('honors label overrides', () => {
    const rendered = makeRendered();
    const block = makeBlock();
    const out = wrapWithPinButton({
      block,
      rendered,
      onPin: vi.fn(),
      labels: { cta: 'Fijar al panel', tooltip: 'Fijar este bloque a un panel' },
    });
    const btn = out.querySelector<HTMLButtonElement>('.cb-pin-button')!;
    expect(btn.getAttribute('aria-label')).toBe('Fijar al panel');
    expect(btn.title).toBe('Fijar este bloque a un panel');
  });

  it('stops click propagation so the surrounding block receives nothing', () => {
    const rendered = makeRendered();
    const block = makeBlock();
    const onPin = vi.fn();
    const out = wrapWithPinButton({ block, rendered, onPin });
    document.body.appendChild(out);
    const onWrapperClick = vi.fn();
    out.addEventListener('click', onWrapperClick);
    out.querySelector<HTMLButtonElement>('.cb-pin-button')!.click();
    expect(onPin).toHaveBeenCalledTimes(1);
    // Click handler attached to the wrapper does not fire because the
    // button calls stopPropagation() — the rendered block (e.g. an
    // actions row) cannot misinterpret the pin click as its own.
    expect(onWrapperClick).not.toHaveBeenCalled();
  });
});
