import type { WidgetState } from './types.js';

const TRANSITIONS: Record<WidgetState, ReadonlySet<WidgetState>> = {
  closed: new Set<WidgetState>(['open', 'minimized', 'fullscreen']),
  minimized: new Set<WidgetState>(['open', 'closed', 'fullscreen']),
  open: new Set<WidgetState>(['closed', 'minimized', 'fullscreen']),
  fullscreen: new Set<WidgetState>(['open', 'closed', 'minimized']),
};

export class WidgetStateMachine {
  private current: WidgetState;
  private listeners = new Set<(next: WidgetState, prev: WidgetState) => void>();

  constructor(initial: WidgetState = 'closed') {
    this.current = initial;
  }

  get state(): WidgetState {
    return this.current;
  }

  canTransition(next: WidgetState): boolean {
    if (next === this.current) return false;
    return TRANSITIONS[this.current].has(next);
  }

  transition(next: WidgetState): void {
    if (next === this.current) return;
    if (!TRANSITIONS[this.current].has(next)) {
      throw new Error(`Illegal widget transition: ${this.current} -> ${next}`);
    }
    const prev = this.current;
    this.current = next;
    for (const listener of this.listeners) listener(next, prev);
  }

  onChange(listener: (next: WidgetState, prev: WidgetState) => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }
}
