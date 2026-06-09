import { describe, expect, it } from 'vitest';
import { escapeHtml, renderMarkdown } from '../../resources/js/markdown.js';

describe('escapeHtml', () => {
  it('escapes the five HTML metacharacters', () => {
    expect(escapeHtml(`<a href="x">&'</a>`)).toBe('&lt;a href=&quot;x&quot;&gt;&amp;&#39;&lt;/a&gt;');
  });
});

describe('renderMarkdown', () => {
  it('returns empty string for empty input', () => {
    expect(renderMarkdown('')).toBe('');
  });

  it('wraps a simple line in a paragraph', () => {
    expect(renderMarkdown('hello')).toBe('<p>hello</p>');
  });

  it('escapes script tags before any markup processing', () => {
    expect(renderMarkdown('<script>alert(1)</script>')).toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
  });

  it('renders bold and italic', () => {
    expect(renderMarkdown('**bold** and *italic*')).toBe('<p><strong>bold</strong> and <em>italic</em></p>');
  });

  it('renders inline code without re-processing its contents', () => {
    expect(renderMarkdown('use `**not bold**` in code')).toBe('<p>use <code>**not bold**</code> in code</p>');
  });

  it('renders safe links and rejects javascript: URLs', () => {
    expect(renderMarkdown('[ok](https://example.com)')).toBe('<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">ok</a></p>');
    expect(renderMarkdown('[bad](javascript:alert(1))')).toBe('<p>[bad](javascript:alert(1))</p>');
    expect(renderMarkdown('[rel](/foo/bar)')).toContain('href="/foo/bar"');
    expect(renderMarkdown('[mail](mailto:a@b.com)')).toContain('href="mailto:a@b.com"');
  });

  it('escapes attributes inside link text and URL', () => {
    const out = renderMarkdown('[a"b](https://x.test)');
    expect(out).toContain('a&quot;b');
  });

  it('separates paragraphs on blank lines', () => {
    const out = renderMarkdown('first\n\nsecond');
    expect(out).toBe('<p>first</p><p>second</p>');
  });

  it('renders single newlines as <br>', () => {
    const out = renderMarkdown('one\ntwo');
    expect(out).toBe('<p>one<br>two</p>');
  });
});
