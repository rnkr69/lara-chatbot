import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import {
  STORAGE_KEY,
  ACTIVE_CONVERSATION_KEY,
  ACTIVE_USER_KEY,
  loadState,
  saveState,
  clearState,
  defaultState,
  makeDebouncedSaver,
  loadActiveConversation,
  saveActiveConversation,
  clearActiveConversation,
  loadActiveUser,
  saveActiveUser,
  clearActiveUser,
} from '../../resources/js/persistence.js';

beforeEach(() => {
  window.sessionStorage.clear();
  window.localStorage.clear();
});

afterEach(() => {
  vi.useRealTimers();
});

describe('saveState / loadState', () => {
  it('returns defaultState when storage is empty', () => {
    expect(loadState()).toEqual(defaultState());
  });

  it('round-trips a full state', () => {
    saveState({ conversationId: 42, isOpen: true, draft: 'hi' });
    expect(loadState()).toEqual({ conversationId: 42, isOpen: true, draft: 'hi' });
  });

  it('accepts string conversationId', () => {
    saveState({ conversationId: 'abc-123', isOpen: false, draft: '' });
    expect(loadState()).toEqual({ conversationId: 'abc-123', isOpen: false, draft: '' });
  });

  it('treats malformed JSON as defaultState (does not throw)', () => {
    window.sessionStorage.setItem(STORAGE_KEY, '{not json');
    expect(loadState()).toEqual(defaultState());
  });

  it('coerces unknown isOpen values to false', () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ conversationId: 1, isOpen: 'yes', draft: 'x' }),
    );
    expect(loadState()).toEqual({ conversationId: 1, isOpen: false, draft: 'x' });
  });

  it('drops conversationId when stored as object', () => {
    window.sessionStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({ conversationId: { nested: true }, isOpen: true, draft: 'x' }),
    );
    expect(loadState()).toEqual({ conversationId: null, isOpen: true, draft: 'x' });
  });

  it('clearState wipes the row', () => {
    saveState({ conversationId: 1, isOpen: true, draft: 'x' });
    clearState();
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull();
  });

  it('writes to the canonical storage key chatbot:state:v1', () => {
    saveState({ conversationId: 1, isOpen: false, draft: '' });
    expect(window.sessionStorage.getItem(STORAGE_KEY)).not.toBeNull();
    expect(STORAGE_KEY).toBe('chatbot:state:v1');
  });
});

describe('makeDebouncedSaver', () => {
  it('writes once when called rapidly within the debounce window', () => {
    vi.useFakeTimers();
    const saver = makeDebouncedSaver(250);
    saver.save({ conversationId: 1, isOpen: false, draft: 'a' });
    saver.save({ conversationId: 1, isOpen: false, draft: 'ab' });
    saver.save({ conversationId: 1, isOpen: false, draft: 'abc' });
    expect(loadState().draft).toBe('');
    vi.advanceTimersByTime(260);
    expect(loadState().draft).toBe('abc');
  });

  it('flush() writes the pending state immediately', () => {
    vi.useFakeTimers();
    const saver = makeDebouncedSaver(250);
    saver.save({ conversationId: 7, isOpen: true, draft: 'pending' });
    saver.flush();
    expect(loadState()).toEqual({ conversationId: 7, isOpen: true, draft: 'pending' });
  });

  it('cancel() drops the pending state without writing', () => {
    vi.useFakeTimers();
    const saver = makeDebouncedSaver(250);
    saver.save({ conversationId: 9, isOpen: true, draft: 'will-not-stick' });
    saver.cancel();
    vi.advanceTimersByTime(500);
    expect(loadState()).toEqual(defaultState());
  });
});

// E17 / D16: cross-tab `conversationId` lives in localStorage under
// `chatbot:active-conversation:v1`. Independent from sessionStorage state.
describe('cross-tab active conversation (E17)', () => {
  it('returns null when localStorage is empty', () => {
    expect(loadActiveConversation()).toBeNull();
  });

  it('round-trips a numeric id', () => {
    saveActiveConversation(42);
    expect(loadActiveConversation()).toBe(42);
    expect(window.localStorage.getItem(ACTIVE_CONVERSATION_KEY)).toBe('42');
  });

  it('round-trips a string id', () => {
    saveActiveConversation('abc-123');
    expect(loadActiveConversation()).toBe('abc-123');
    expect(window.localStorage.getItem(ACTIVE_CONVERSATION_KEY)).toBe('"abc-123"');
  });

  it('clears the row when called with null', () => {
    saveActiveConversation(7);
    saveActiveConversation(null);
    expect(loadActiveConversation()).toBeNull();
    expect(window.localStorage.getItem(ACTIVE_CONVERSATION_KEY)).toBeNull();
  });

  it('clearActiveConversation removes the row', () => {
    saveActiveConversation(7);
    clearActiveConversation();
    expect(loadActiveConversation()).toBeNull();
  });

  it('returns null when the persisted value is malformed JSON', () => {
    window.localStorage.setItem(ACTIVE_CONVERSATION_KEY, '{not json');
    expect(loadActiveConversation()).toBeNull();
  });

  it('returns null when the persisted value is a non-scalar', () => {
    window.localStorage.setItem(
      ACTIVE_CONVERSATION_KEY,
      JSON.stringify({ id: 5 }),
    );
    expect(loadActiveConversation()).toBeNull();
  });

  it('does not write the active conversation into sessionStorage', () => {
    saveActiveConversation('cross-tab-1');
    // sessionStorage should remain pristine — that key is for E13's full state.
    expect(window.sessionStorage.getItem(STORAGE_KEY)).toBeNull();
  });

  it('canonical key is chatbot:active-conversation:v1', () => {
    expect(ACTIVE_CONVERSATION_KEY).toBe('chatbot:active-conversation:v1');
  });
});

// v1.1.3 (#21): the active auth user id lets the widget detect a logout/login
// in the same browser and purge the previous user's cross-tab conversation.
describe('cross-user gating (v1.1.3 #21)', () => {
  it('returns null when the active user has never been set', () => {
    expect(loadActiveUser()).toBeNull();
  });

  it('round-trips a string id', () => {
    saveActiveUser('42');
    expect(loadActiveUser()).toBe('42');
    expect(window.localStorage.getItem(ACTIVE_USER_KEY)).toBe('42');
  });

  it('coerces a numeric id to its string representation', () => {
    saveActiveUser(7);
    expect(loadActiveUser()).toBe('7');
  });

  it('clearActiveUser / saveActiveUser(null) wipe the row', () => {
    saveActiveUser('alice');
    clearActiveUser();
    expect(loadActiveUser()).toBeNull();

    saveActiveUser('bob');
    saveActiveUser(null);
    expect(loadActiveUser()).toBeNull();
  });

  it('canonical key is chatbot:active-user:v1', () => {
    expect(ACTIVE_USER_KEY).toBe('chatbot:active-user:v1');
  });
});
