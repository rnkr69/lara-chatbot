import { describe, expect, it } from 'vitest';
import { WidgetStateMachine } from '../../resources/js/state.js';

describe('WidgetStateMachine', () => {
  it('starts in closed by default', () => {
    const m = new WidgetStateMachine();
    expect(m.state).toBe('closed');
  });

  it('allows closed -> open and emits change', () => {
    const m = new WidgetStateMachine();
    let received: [string, string] | null = null;
    m.onChange((next, prev) => { received = [next, prev]; });
    m.transition('open');
    expect(m.state).toBe('open');
    expect(received).toEqual(['open', 'closed']);
  });

  it('allows the four documented transitions from each state', () => {
    const states = ['closed', 'minimized', 'open', 'fullscreen'] as const;
    for (const from of states) {
      for (const to of states) {
        const m = new WidgetStateMachine(from);
        if (from === to) {
          expect(m.canTransition(to)).toBe(false);
        } else {
          expect(m.canTransition(to)).toBe(true);
        }
      }
    }
  });

  it('throws on illegal self-transition is no-op (does not throw)', () => {
    const m = new WidgetStateMachine('open');
    expect(() => m.transition('open')).not.toThrow();
    expect(m.state).toBe('open');
  });

  it('listeners can be removed via the returned disposer', () => {
    const m = new WidgetStateMachine();
    let count = 0;
    const off = m.onChange(() => { count++; });
    m.transition('open');
    off();
    m.transition('closed');
    expect(count).toBe(1);
  });
});
