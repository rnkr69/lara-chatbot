import { describe, expect, it, vi, afterEach } from 'vitest';
import { parseI18nFromElement, pickString, pickObject } from '../../resources/js/i18n-bridge.js';

afterEach(() => {
  vi.restoreAllMocks();
});

describe('parseI18nFromElement', () => {
  it('returns {} when element is null or undefined', () => {
    expect(parseI18nFromElement(null)).toEqual({});
    expect(parseI18nFromElement(undefined)).toEqual({});
  });

  it('returns {} when data-i18n attribute is missing', () => {
    const el = document.createElement('div');
    expect(parseI18nFromElement(el)).toEqual({});
  });

  it('returns {} when data-i18n is an empty string', () => {
    const el = document.createElement('div');
    el.setAttribute('data-i18n', '');
    expect(parseI18nFromElement(el)).toEqual({});
  });

  it('parses a valid JSON object payload', () => {
    const el = document.createElement('div');
    el.setAttribute('data-i18n', JSON.stringify({
      title: 'Chatbot',
      dashboard: { sidebar: { new_cta: '+ Nuevo panel' } },
    }));
    const out = parseI18nFromElement(el);
    expect(out.title).toBe('Chatbot');
    expect(out.dashboard?.sidebar?.new_cta).toBe('+ Nuevo panel');
  });

  it('returns {} and warns once on malformed JSON', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const el = document.createElement('div');
    el.setAttribute('data-i18n', '{not: valid json');
    expect(parseI18nFromElement(el)).toEqual({});
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0]?.[0]).toContain('[chatbot:i18n]');
  });

  it('truncates the warned payload to 80 chars + ellipsis', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const el = document.createElement('div');
    const long = 'x'.repeat(200);
    el.setAttribute('data-i18n', long);
    parseI18nFromElement(el);
    const msg = String(warn.mock.calls[0]?.[0] ?? '');
    expect(msg).toContain('…');
    // The 80-char window is from raw input, so the warn message length ≈ prefix + 80 + ellipsis.
    expect(msg.length).toBeLessThan(160);
  });

  it('rejects array payloads (must be an object)', () => {
    const el = document.createElement('div');
    el.setAttribute('data-i18n', JSON.stringify(['a', 'b']));
    expect(parseI18nFromElement(el)).toEqual({});
  });

  it('rejects primitive payloads (string, number, null)', () => {
    const el = document.createElement('div');
    el.setAttribute('data-i18n', '"just a string"');
    expect(parseI18nFromElement(el)).toEqual({});
    el.setAttribute('data-i18n', '42');
    expect(parseI18nFromElement(el)).toEqual({});
    el.setAttribute('data-i18n', 'null');
    expect(parseI18nFromElement(el)).toEqual({});
  });
});

describe('pickString', () => {
  it('returns the value when it is a non-empty string', () => {
    expect(pickString({ a: 'hello' }, 'a', 'fallback')).toBe('hello');
  });

  it('returns the fallback when the key is missing', () => {
    expect(pickString({}, 'missing', 'fallback')).toBe('fallback');
  });

  it('returns the fallback when the value is an empty string', () => {
    expect(pickString({ a: '' }, 'a', 'fallback')).toBe('fallback');
  });

  it('returns the fallback when the value is not a string', () => {
    expect(pickString({ a: 42 }, 'a', 'fb')).toBe('fb');
    expect(pickString({ a: null }, 'a', 'fb')).toBe('fb');
    expect(pickString({ a: ['nope'] }, 'a', 'fb')).toBe('fb');
  });

  it('returns the fallback when obj is undefined', () => {
    expect(pickString(undefined, 'a', 'fb')).toBe('fb');
  });
});

describe('pickObject', () => {
  it('returns the nested object when present', () => {
    expect(pickObject({ nested: { x: 1 } }, 'nested')).toEqual({ x: 1 });
  });

  it('returns {} when the key is missing', () => {
    expect(pickObject({}, 'missing')).toEqual({});
  });

  it('returns {} when the value is not an object', () => {
    expect(pickObject({ a: 'string' }, 'a')).toEqual({});
    expect(pickObject({ a: ['arr'] }, 'a')).toEqual({});
    expect(pickObject({ a: null }, 'a')).toEqual({});
  });

  it('returns {} when obj is undefined', () => {
    expect(pickObject(undefined, 'a')).toEqual({});
  });
});
