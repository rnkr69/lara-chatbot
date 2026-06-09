import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { detectMode, getMode, resetModeCache } from '../../resources/js/runtime.js';

declare global {
  interface Window {
    Inertia?: unknown;
    Livewire?: unknown;
  }
}

beforeEach(() => {
  resetModeCache();
  document.head.innerHTML = '';
  delete (window as Window).Inertia;
  delete (window as Window).Livewire;
});

afterEach(() => {
  resetModeCache();
});

describe('detectMode', () => {
  it('defaults to mpa when nothing is present', () => {
    expect(detectMode()).toBe('mpa');
  });

  it('returns spa when window.Inertia is present', () => {
    (window as Window).Inertia = { visit: () => undefined };
    expect(detectMode()).toBe('spa');
  });

  it('returns spa when window.Livewire is present', () => {
    (window as Window).Livewire = { navigate: () => undefined };
    expect(detectMode()).toBe('spa');
  });

  it('honors explicit meta tag content="spa"', () => {
    const m = document.createElement('meta');
    m.setAttribute('name', 'chatbot:mode');
    m.setAttribute('content', 'spa');
    document.head.appendChild(m);
    expect(detectMode()).toBe('spa');
  });

  it('honors explicit meta tag content="mpa" even with Inertia present', () => {
    (window as Window).Inertia = { visit: () => undefined };
    const m = document.createElement('meta');
    m.setAttribute('name', 'chatbot:mode');
    m.setAttribute('content', 'mpa');
    document.head.appendChild(m);
    expect(detectMode()).toBe('mpa');
  });

  it('ignores meta tag with unknown content and falls back to heuristics', () => {
    const m = document.createElement('meta');
    m.setAttribute('name', 'chatbot:mode');
    m.setAttribute('content', 'unknown');
    document.head.appendChild(m);
    expect(detectMode()).toBe('mpa');
    (window as Window).Livewire = { navigate: () => undefined };
    resetModeCache();
    expect(detectMode()).toBe('spa');
  });
});

describe('getMode caching', () => {
  it('caches the first detection and returns the same value afterwards', () => {
    expect(getMode()).toBe('mpa');
    (window as Window).Inertia = { visit: () => undefined };
    // No reset → still mpa from cache
    expect(getMode()).toBe('mpa');
  });

  it('resetModeCache forces re-detection', () => {
    expect(getMode()).toBe('mpa');
    (window as Window).Inertia = { visit: () => undefined };
    resetModeCache();
    expect(getMode()).toBe('spa');
  });
});
