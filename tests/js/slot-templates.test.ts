import { describe, expect, it, beforeEach } from 'vitest';
import {
  cloneAndBind,
  findTemplate,
  getPath,
} from '../../resources/js/slot-templates.js';

beforeEach(() => {
  document.querySelectorAll('template[data-chatbot-block-template]').forEach((n) => n.remove());
});

describe('getPath', () => {
  it('reads top-level keys', () => {
    expect(getPath({ a: 1 }, 'a')).toBe(1);
  });

  it('reads nested keys via dot notation', () => {
    expect(getPath({ a: { b: { c: 'leaf' } } }, 'a.b.c')).toBe('leaf');
  });

  it('reads array indices via numeric segments', () => {
    expect(getPath({ items: ['one', 'two'] }, 'items.1')).toBe('two');
  });

  it('returns undefined for missing intermediate keys', () => {
    expect(getPath({ a: 1 }, 'a.b.c')).toBeUndefined();
  });

  it('returns undefined for non-object intermediates', () => {
    expect(getPath({ a: 'leaf' }, 'a.b')).toBeUndefined();
  });

  it('returns the source unchanged for an empty path', () => {
    const obj = { a: 1 };
    expect(getPath(obj, '')).toBe(obj);
  });
});

describe('findTemplate', () => {
  it('returns null when no template matches', () => {
    expect(findTemplate('card')).toBeNull();
  });

  it('returns the matching template element', () => {
    const tpl = document.createElement('template');
    tpl.setAttribute('data-chatbot-block-template', 'card');
    document.body.appendChild(tpl);
    expect(findTemplate('card')).toBe(tpl);
    tpl.remove();
  });

  it('does not match templates of other types', () => {
    const tpl = document.createElement('template');
    tpl.setAttribute('data-chatbot-block-template', 'table');
    document.body.appendChild(tpl);
    expect(findTemplate('card')).toBeNull();
    tpl.remove();
  });
});

describe('cloneAndBind', () => {
  it('binds top-level fields by data-bind path', () => {
    const tpl = document.createElement('template');
    tpl.innerHTML = `
      <div class="card">
        <h2 data-bind="title"></h2>
        <span data-bind="status"></span>
      </div>
    `.trim();
    const node = cloneAndBind(tpl, { title: 'Hello', status: 'OK' });
    expect(node.classList.contains('card')).toBe(true);
    expect(node.querySelector('h2')!.textContent).toBe('Hello');
    expect(node.querySelector('span')!.textContent).toBe('OK');
  });

  it('binds nested paths and array indices', () => {
    const tpl = document.createElement('template');
    tpl.innerHTML = `
      <div>
        <p data-bind="user.name"></p>
        <p data-bind="user.email"></p>
        <p data-bind="tags.0"></p>
      </div>
    `.trim();
    const node = cloneAndBind(tpl, { user: { name: 'Ada', email: 'a@b.c' }, tags: ['admin'] });
    const ps = node.querySelectorAll('p');
    expect(ps[0]!.textContent).toBe('Ada');
    expect(ps[1]!.textContent).toBe('a@b.c');
    expect(ps[2]!.textContent).toBe('admin');
  });

  it('renders missing values as empty string', () => {
    const tpl = document.createElement('template');
    tpl.innerHTML = `<div><span data-bind="missing"></span></div>`;
    const node = cloneAndBind(tpl, {});
    expect(node.querySelector('span')!.textContent).toBe('');
  });

  it('stringifies non-primitive values as JSON', () => {
    const tpl = document.createElement('template');
    tpl.innerHTML = `<div><span data-bind="payload"></span></div>`;
    const node = cloneAndBind(tpl, { payload: { a: 1, b: 'x' } });
    expect(node.querySelector('span')!.textContent).toBe('{"a":1,"b":"x"}');
  });

  it('wraps multi-root templates in a single div', () => {
    const tpl = document.createElement('template');
    tpl.innerHTML = `<h2 data-bind="title"></h2><p data-bind="body"></p>`;
    const node = cloneAndBind(tpl, { title: 'Hi', body: 'There' });
    expect(node.tagName).toBe('DIV');
    expect(node.children.length).toBe(2);
    expect(node.querySelector('h2')!.textContent).toBe('Hi');
    expect(node.querySelector('p')!.textContent).toBe('There');
  });
});
