import { describe, expect, it, beforeEach } from 'vitest';
import {
  readMetaContext,
  emitContextChanged,
  META_NAME,
  CONTEXT_CHANGED_EVENT,
} from '../../resources/js/page-context.js';

beforeEach(() => {
  document.head.innerHTML = '';
});

function setMeta(content: string): void {
  document.head.innerHTML = `<meta name="${META_NAME}" content='${content}'>`;
}

describe('readMetaContext', () => {
  it('returns {} when the meta tag is missing', () => {
    expect(readMetaContext(document)).toEqual({});
  });

  it('parses a JSON object content', () => {
    setMeta('{"route":"orders.index","page":2}');
    expect(readMetaContext(document)).toEqual({ route: 'orders.index', page: 2 });
  });

  it('returns {} when content is not JSON-shaped (no leading "{" or "[")', () => {
    setMeta('plain string');
    expect(readMetaContext(document)).toEqual({});
  });

  it('returns {} when content is invalid JSON', () => {
    setMeta('{not valid');
    expect(readMetaContext(document)).toEqual({});
  });

  it('returns {} when content is a JSON array (root must be an object)', () => {
    setMeta('[1,2,3]');
    expect(readMetaContext(document)).toEqual({});
  });

  it('returns {} when content is empty', () => {
    setMeta('');
    expect(readMetaContext(document)).toEqual({});
  });

  it('parses content with leading/trailing whitespace', () => {
    setMeta('  {"route":"x"}  ');
    expect(readMetaContext(document)).toEqual({ route: 'x' });
  });
});

describe('emitContextChanged', () => {
  it(`dispatches a window CustomEvent named ${CONTEXT_CHANGED_EVENT} with detail`, () => {
    const events: unknown[] = [];
    const listener = (e: Event): void => { events.push((e as CustomEvent).detail); };
    window.addEventListener(CONTEXT_CHANGED_EVENT, listener);

    emitContextChanged({ route: '/a' });
    emitContextChanged({});

    expect(events).toEqual([{ route: '/a' }, {}]);
    window.removeEventListener(CONTEXT_CHANGED_EVENT, listener);
  });
});
