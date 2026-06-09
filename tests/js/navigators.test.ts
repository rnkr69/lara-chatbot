import { describe, expect, it, beforeEach, vi } from 'vitest';
import {
  mpaNavigator,
  inertiaNavigator,
  livewireNavigator,
  selectDefaultNavigator,
} from '../../resources/js/navigators.js';

declare global {
  interface Window {
    Inertia?: unknown;
    Livewire?: unknown;
  }
}

beforeEach(() => {
  delete (window as Window).Inertia;
  delete (window as Window).Livewire;
});

function stubLocation(): { assign: ReturnType<typeof vi.fn>; replace: ReturnType<typeof vi.fn> } {
  const assign = vi.fn();
  const replace = vi.fn();
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: {
      ...window.location,
      assign,
      replace,
      origin: 'http://localhost',
      href: 'http://localhost/',
    },
  });
  return { assign, replace };
}

describe('mpaNavigator', () => {
  it('calls window.location.assign by default', () => {
    const { assign, replace } = stubLocation();
    mpaNavigator('/foo');
    expect(assign).toHaveBeenCalledWith('/foo');
    expect(replace).not.toHaveBeenCalled();
  });

  it('uses replace when options.replace=true', () => {
    const { assign, replace } = stubLocation();
    mpaNavigator('/foo', { replace: true });
    expect(replace).toHaveBeenCalledWith('/foo');
    expect(assign).not.toHaveBeenCalled();
  });
});

describe('inertiaNavigator', () => {
  it('delegates to window.Inertia.visit', () => {
    const visit = vi.fn();
    (window as Window).Inertia = { visit };
    inertiaNavigator('/foo', { replace: true });
    expect(visit).toHaveBeenCalledWith('/foo', { replace: true });
  });

  it('falls back to mpaNavigator when Inertia is absent', () => {
    const { assign } = stubLocation();
    inertiaNavigator('/foo');
    expect(assign).toHaveBeenCalledWith('/foo');
  });
});

describe('livewireNavigator', () => {
  it('delegates to window.Livewire.navigate', () => {
    const navigate = vi.fn();
    (window as Window).Livewire = { navigate };
    livewireNavigator('/bar');
    expect(navigate).toHaveBeenCalledWith('/bar', {});
  });

  it('falls back to mpaNavigator when Livewire is absent', () => {
    const { assign } = stubLocation();
    livewireNavigator('/bar');
    expect(assign).toHaveBeenCalledWith('/bar');
  });
});

describe('selectDefaultNavigator', () => {
  it('returns inertiaNavigator when window.Inertia is present', () => {
    const visit = vi.fn();
    (window as Window).Inertia = { visit };
    selectDefaultNavigator()('/x');
    expect(visit).toHaveBeenCalled();
  });

  it('returns livewireNavigator when only Livewire is present', () => {
    const navigate = vi.fn();
    (window as Window).Livewire = { navigate };
    selectDefaultNavigator()('/x');
    expect(navigate).toHaveBeenCalled();
  });

  it('prefers Inertia when both are present', () => {
    const visit = vi.fn();
    const navigate = vi.fn();
    (window as Window).Inertia = { visit };
    (window as Window).Livewire = { navigate };
    selectDefaultNavigator()('/x');
    expect(visit).toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('falls back to mpaNavigator when neither is present', () => {
    const { assign } = stubLocation();
    selectDefaultNavigator()('/x');
    expect(assign).toHaveBeenCalledWith('/x');
  });
});
