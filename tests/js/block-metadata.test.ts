import { describe, expect, it } from 'vitest';
import type { BlockPayload } from '../../resources/js/types.js';
import { readV2BlockMetadata } from '../../resources/js/block-metadata.js';

/**
 * E1 (v2.0) — tests del helper que extrae los metadatos opcionales `id`,
 * `source` y `pinnable` que el orquestador SSE estampa sobre cada bloque.
 *
 * Triple back-compat:
 *   - Block v1 sin metadatos: el target queda exactamente igual.
 *   - Inputs malformados (id no-string, source sin tool, etc.): se ignoran.
 *   - Block v2 completo: los tres campos aterrizan correctamente.
 */
describe('readV2BlockMetadata — v1 back-compat', () => {
  it('does NOT mutate the block when no v2 fields are present (v1 frame)', () => {
    const block: BlockPayload = { type: 'card', data: { title: 'Hi' } };
    readV2BlockMetadata({ type: 'card', data: { title: 'Hi' } }, block);

    expect(block).toEqual({ type: 'card', data: { title: 'Hi' } });
    expect(block.id).toBeUndefined();
    expect(block.source).toBeUndefined();
    expect(block.pinnable).toBeUndefined();
    expect(block.blockOrdinal).toBeUndefined();
  });

  it('ignores block_ordinal when negative, non-integer, NaN or non-number (#27)', () => {
    const block: BlockPayload = { type: 'kpi', data: {} };

    readV2BlockMetadata({ block_ordinal: -1 }, block);
    expect(block.blockOrdinal).toBeUndefined();

    readV2BlockMetadata({ block_ordinal: 1.5 }, block);
    expect(block.blockOrdinal).toBeUndefined();

    readV2BlockMetadata({ block_ordinal: Number.NaN }, block);
    expect(block.blockOrdinal).toBeUndefined();

    readV2BlockMetadata({ block_ordinal: '2' as unknown as number }, block);
    expect(block.blockOrdinal).toBeUndefined();
  });

  it('ignores empty/non-string id', () => {
    const block: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ id: '' }, block);
    expect(block.id).toBeUndefined();

    readV2BlockMetadata({ id: 42 as unknown as string }, block);
    expect(block.id).toBeUndefined();

    readV2BlockMetadata({ id: null as unknown as string }, block);
    expect(block.id).toBeUndefined();
  });

  it('ignores pinnable=false / pinnable="true" / other truthy non-true values', () => {
    // The widget treats pinnable as STRICT === true. Anything else means
    // "not pinnable" because we don't want a truthy string to accidentally
    // turn on the pin button — that flag is a security boundary.
    const block: BlockPayload = { type: 'card', data: {} };

    readV2BlockMetadata({ pinnable: false }, block);
    expect(block.pinnable).toBeUndefined();

    readV2BlockMetadata({ pinnable: 'true' as unknown as boolean }, block);
    expect(block.pinnable).toBeUndefined();

    readV2BlockMetadata({ pinnable: 1 as unknown as boolean }, block);
    expect(block.pinnable).toBeUndefined();
  });

  it('ignores source when tool is empty, missing or non-string', () => {
    const block: BlockPayload = { type: 'card', data: {} };

    readV2BlockMetadata({ source: { tool: '', args: {} } }, block);
    expect(block.source).toBeUndefined();

    readV2BlockMetadata({ source: { args: {} } }, block);
    expect(block.source).toBeUndefined();

    readV2BlockMetadata({ source: { tool: 42 as unknown as string, args: {} } }, block);
    expect(block.source).toBeUndefined();
  });

  it('ignores source when args is missing, a non-empty array, or non-object', () => {
    const block: BlockPayload = { type: 'card', data: {} };

    readV2BlockMetadata({ source: { tool: 'x' } }, block);
    expect(block.source).toBeUndefined();

    // A non-empty array is genuinely malformed — named tool args always
    // serialize as a JSON object, never a list.
    readV2BlockMetadata({ source: { tool: 'x', args: [1, 2, 3] as unknown as Record<string, unknown> } }, block);
    expect(block.source).toBeUndefined();

    readV2BlockMetadata({ source: { tool: 'x', args: 'nope' as unknown as Record<string, unknown> } }, block);
    expect(block.source).toBeUndefined();
  });

  it('normalizes args: [] (PHP json_encode of an empty arg array) to an empty map', () => {
    // A pinnable tool invoked with no args — PHP's json_encode([]) emits the
    // JSON list `[]`, not `{}`. The source must still be accepted so the pin
    // button mounts; the empty array becomes an empty arg map.
    const block: BlockPayload = { type: 'kpi', data: {} };

    readV2BlockMetadata(
      { source: { tool: 'fleet_kpis', args: [] as unknown as Record<string, unknown> } },
      block,
    );

    expect(block.source).toEqual({ tool: 'fleet_kpis', args: {} });
  });

  it('ignores source when it is an array or a primitive', () => {
    const block: BlockPayload = { type: 'card', data: {} };

    readV2BlockMetadata({ source: [{ tool: 'x', args: {} }] as unknown as Record<string, unknown> }, block);
    expect(block.source).toBeUndefined();

    readV2BlockMetadata({ source: 'list_invoices' as unknown as Record<string, unknown> }, block);
    expect(block.source).toBeUndefined();
  });
});

describe('readV2BlockMetadata — v2 happy paths', () => {
  it('copies the id when it is a non-empty string', () => {
    const block: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ id: 'block-uuid-1' }, block);

    expect(block.id).toBe('block-uuid-1');
  });

  it('copies pinnable when strictly === true', () => {
    const block: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ pinnable: true }, block);

    expect(block.pinnable).toBe(true);
  });

  it('copies block_ordinal when it is an integer >= 0, including 0 (#27)', () => {
    // Ordinal 0 (the first block of its type — the common single-block
    // case) MUST be copied; only an absent / malformed value is ignored.
    const first: BlockPayload = { type: 'kpi', data: {} };
    readV2BlockMetadata({ block_ordinal: 0 }, first);
    expect(first.blockOrdinal).toBe(0);

    const third: BlockPayload = { type: 'kpi', data: {} };
    readV2BlockMetadata({ block_ordinal: 2 }, third);
    expect(third.blockOrdinal).toBe(2);
  });

  it('copies a well-shaped source object', () => {
    const block: BlockPayload = { type: 'table', data: {} };
    readV2BlockMetadata(
      {
        source: {
          tool: 'list_invoices',
          args: { status: 'paid' },
          page_context_keys: ['route', 'entity'],
        },
      },
      block,
    );

    expect(block.source).toEqual({
      tool: 'list_invoices',
      args: { status: 'paid' },
      page_context_keys: ['route', 'entity'],
    });
  });

  it('omits page_context_keys when the array is empty or all entries are non-strings', () => {
    const block: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata(
      {
        source: {
          tool: 'x',
          args: {},
          page_context_keys: [],
        },
      },
      block,
    );
    expect(block.source).toEqual({ tool: 'x', args: {} });
    expect(block.source?.page_context_keys).toBeUndefined();

    const block2: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata(
      {
        source: {
          tool: 'x',
          args: {},
          page_context_keys: [1, 2, 3] as unknown as string[],
        },
      },
      block2,
    );
    expect(block2.source?.page_context_keys).toBeUndefined();
  });

  it('filters non-string entries from page_context_keys', () => {
    const block: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata(
      {
        source: {
          tool: 'x',
          args: {},
          page_context_keys: ['route', 42 as unknown as string, 'entity', null as unknown as string],
        },
      },
      block,
    );

    expect(block.source?.page_context_keys).toEqual(['route', 'entity']);
  });

  it('applies all four fields together when present', () => {
    const block: BlockPayload = { type: 'table', data: { rows: [] } };
    readV2BlockMetadata(
      {
        id: 'uuid-xyz',
        source: { tool: 'list_invoices', args: { status: 'paid' } },
        pinnable: true,
        block_ordinal: 1,
      },
      block,
    );

    expect(block).toEqual({
      type: 'table',
      data: { rows: [] },
      id: 'uuid-xyz',
      source: { tool: 'list_invoices', args: { status: 'paid' } },
      pinnable: true,
      blockOrdinal: 1,
    });
  });

  it('copies meta verbatim when it is a plain object (v2.2.1 PR-B side_effects)', () => {
    const block: BlockPayload = { type: 'card', data: { title: 'Added' } };
    readV2BlockMetadata(
      {
        meta: { side_effects: { type: 'widget_added', dashboard_slug: 'ops', widget_id: 7 } },
      },
      block,
    );

    expect(block.meta).toEqual({
      side_effects: { type: 'widget_added', dashboard_slug: 'ops', widget_id: 7 },
    });
  });

  it('ignores meta when it is an array, primitive or null', () => {
    const a: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ meta: ['x'] as unknown as Record<string, unknown> }, a);
    expect(a.meta).toBeUndefined();

    const b: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ meta: 'side_effects' as unknown as Record<string, unknown> }, b);
    expect(b.meta).toBeUndefined();

    const c: BlockPayload = { type: 'card', data: {} };
    readV2BlockMetadata({ meta: null as unknown as Record<string, unknown> }, c);
    expect(c.meta).toBeUndefined();
  });
});

describe('readV2BlockMetadata — accepts both v1 and v2 BlockPayload shapes (type-level)', () => {
  it('compiles when assigning a v1-shape literal to BlockPayload', () => {
    // Pure type assertion: if this file `tsc --noEmit`s, the test passes.
    // The legacy `{type, data}` shape must remain assignable without the
    // optional fields, otherwise every v1 renderer/test fixture would break.
    const v1: BlockPayload = { type: 'card', data: { title: 'Hi' } };
    expect(v1.type).toBe('card');
  });

  it('compiles when assigning a v2-shape literal to BlockPayload', () => {
    const v2: BlockPayload = {
      type: 'table',
      data: { rows: [] },
      id: 'b1',
      source: { tool: 'list_invoices', args: { status: 'paid' }, page_context_keys: ['route'] },
      pinnable: true,
      blockOrdinal: 0,
    };
    expect(v2.pinnable).toBe(true);
  });
});
