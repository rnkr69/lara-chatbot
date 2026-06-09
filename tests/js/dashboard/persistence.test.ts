import { describe, expect, it, beforeEach } from 'vitest';
import {
  ACTIVE_DASHBOARD_KEY,
  loadActiveDashboard,
  saveActiveDashboard,
} from '../../../resources/js/dashboard/persistence.js';

beforeEach(() => {
  window.localStorage.clear();
});

describe('dashboard persistence — active slug', () => {
  it('returns null when nothing is stored', () => {
    expect(loadActiveDashboard()).toBeNull();
  });

  it('round-trips a string slug', () => {
    saveActiveDashboard('mi-panel');
    expect(loadActiveDashboard()).toBe('mi-panel');
    expect(window.localStorage.getItem(ACTIVE_DASHBOARD_KEY)).toBe('"mi-panel"');
  });

  it('clears the key when given null', () => {
    saveActiveDashboard('a');
    saveActiveDashboard(null);
    expect(loadActiveDashboard()).toBeNull();
    expect(window.localStorage.getItem(ACTIVE_DASHBOARD_KEY)).toBeNull();
  });

  it('treats empty strings as null', () => {
    saveActiveDashboard('');
    expect(window.localStorage.getItem(ACTIVE_DASHBOARD_KEY)).toBeNull();
    expect(loadActiveDashboard()).toBeNull();
  });

  it('drops non-string payloads silently', () => {
    window.localStorage.setItem(ACTIVE_DASHBOARD_KEY, JSON.stringify({ slug: 'x' }));
    expect(loadActiveDashboard()).toBeNull();
  });

  it('drops malformed JSON silently', () => {
    window.localStorage.setItem(ACTIVE_DASHBOARD_KEY, 'not-json');
    expect(loadActiveDashboard()).toBeNull();
  });
});
