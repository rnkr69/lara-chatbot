import { test, expect } from '@playwright/test';

/**
 * v2.0 / E5 — E2E for the dashboard bundle.
 *
 * Covers the points in §8 of the plan:
 *   - Mount + sidebar list + click selects a dashboard.
 *   - On-open refresh fires the SSE bulk and updates the displayed snapshot.
 *   - Header drag → PATCH to `/widgets/{id}` with the new position
 *     (simulated: the test edits the position via JS and fires gridstack's
 *     `change` event — gridstack runs layout queries that the headless
 *     environment does not provide, so here we verify the wiring,
 *     not the visual drag mechanics).
 *   - Rename from the sidebar updates the name and the slug.
 */

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.localStorage.clear();
    window.sessionStorage.clear();
  });
});

test('mounts the dashboard, lists widgets, and fires SSE bulk refresh on open', async ({ page }) => {
  await page.goto('/dashboard.html');

  // Sidebar shows both dashboards.
  await expect(page.locator('.cb-dashboard-sidebar-item').nth(0)).toContainText('Mi Panel');
  await expect(page.locator('.cb-dashboard-sidebar-item').nth(1)).toContainText('Operaciones');

  // Active dashboard's name renders in the header.
  await expect(page.locator('.cb-dashboard-title')).toHaveText('Mi Panel');

  // Three widgets mounted as cards (table + card + kpi).
  await expect(page.locator('.cb-dashboard-card')).toHaveCount(3);
  await expect(page.locator('.cb-dashboard-card-title').first()).toHaveText('Users');

  // Bulk refresh fired (on_open policy on all widgets).
  await page.waitForFunction(() => (window as unknown as { __dashboardFixture: { bulkRefreshCalls: number } }).__dashboardFixture.bulkRefreshCalls >= 1);
  const refreshes = await page.evaluate(() => (window as unknown as { __dashboardFixture: { bulkRefreshCalls: number } }).__dashboardFixture.bulkRefreshCalls);
  expect(refreshes).toBeGreaterThanOrEqual(1);

  // The refresh appended a "Refreshed-…" row to the users table.
  const tableText = await page.locator('.cb-dashboard-card').first().locator('.cb-table').textContent();
  expect(tableText).toMatch(/Refreshed-/);

  // v2.0 / E8 — KPI widget renders via the built-in renderer (resources/js/kpi.ts).
  // Label + formatted value + signed delta + trend arrow are all present.
  const kpi = page.locator('.cb-dashboard-card', { has: page.locator('.block-kpi') });
  await expect(kpi).toHaveCount(1);
  await expect(kpi.locator('.cb-kpi-label')).toHaveText('p99 latency');
  await expect(kpi.locator('.cb-kpi-value')).toHaveText('420');
  await expect(kpi.locator('.cb-kpi-unit')).toHaveText('ms');
  await expect(kpi.locator('.cb-kpi-delta-value')).toHaveText('-12');
  await expect(kpi.locator('.cb-kpi-delta')).toHaveClass(/cb-kpi-trend-down/);
});

test('renders a grid layout with a legible main pane and styled block primitives (#15/#16)', async ({ page }) => {
  // Regression guard for the two findings the *functional* E2E missed because
  // they only show up rendered: #15 (the dashboard collapsed to ~152px wide
  // because a host `display: flex` ID-selector beat the bundle's grid) and
  // #16 (pinned tables/cards rendered as naked user-agent HTML — no block CSS).
  await page.goto('/dashboard.html');
  await page.waitForSelector('.cb-dashboard-card');

  // #15 — the bundle's CSS owns the layout: the root must compute to `grid`,
  // and the <main> pane must be wide enough to be legible.
  const layout = await page.evaluate(() => {
    const root = document.getElementById('chatbot-dashboard-root')!;
    const main = document.querySelector('.cb-dashboard-main')!;
    return {
      display: getComputedStyle(root).display,
      mainWidth: main.getBoundingClientRect().width,
    };
  });
  expect(layout.display).toBe('grid');
  expect(layout.mainWidth).toBeGreaterThan(400);

  // #16 — a pinned `table` must carry the shared block-styles CSS, not the
  // bare user-agent table (1px cell padding, `border-collapse: separate`).
  const tableCss = await page.evaluate(() => {
    const table = document.querySelector('.cb-dashboard-card .cb-table') as HTMLElement;
    const td = document.querySelector('.cb-dashboard-card .cb-table td') as HTMLElement;
    return {
      borderCollapse: getComputedStyle(table).borderCollapse,
      tdPadding: getComputedStyle(td).padding,
    };
  });
  expect(tableCss.borderCollapse).toBe('collapse');
  expect(tableCss.tdPadding).not.toBe('1px');
});

test('clicking another dashboard in the sidebar swaps the active title + widgets', async ({ page }) => {
  await page.goto('/dashboard.html');
  await page.waitForSelector('.cb-dashboard-card');
  await page.locator('.cb-dashboard-sidebar-item').nth(1).click();
  // Operaciones has zero widgets; the grid empties out.
  await expect(page.locator('.cb-dashboard-title')).toHaveText('Operaciones');
  await expect(page.locator('.cb-dashboard-card')).toHaveCount(0);
});

test('layout change PATCHes the widget position with debounce', async ({ page }) => {
  await page.goto('/dashboard.html');
  await page.waitForSelector('.cb-dashboard-card');

  // gridstack's drag handler binds against real mouse events that headless
  // chromium doesn't fire reliably for non-visible elements. To validate the
  // wiring `gridstack:change → debounce → PATCH`, we drive the change via
  // gridstack's public API (`grid.update(el, opts)`), which marks the node
  // dirty and fires the internal `_triggerChangeEvent` → our listener.
  await page.evaluate(() => {
    const gridRoot = document.querySelector('.grid-stack') as HTMLElement & {
      gridstack?: { update: (el: HTMLElement, opt: Record<string, number>) => unknown };
    };
    const item = document.querySelector<HTMLElement>('.grid-stack-item[data-widget-id="11"]');
    if (!gridRoot?.gridstack || !item) throw new Error('grid instance not initialised');
    gridRoot.gridstack.update(item, { x: 8, y: 4, w: 3, h: 2 });
  });

  // Debounce window 500ms; allow 800ms to be safe.
  await page.waitForTimeout(800);
  const patches = await page.evaluate(() => (window as unknown as { __dashboardFixture: { widgetPatches: Array<{ id: number; payload: { position?: { x: number; y: number; w: number; h: number } } }> } }).__dashboardFixture.widgetPatches);
  expect(patches.length).toBeGreaterThanOrEqual(1);
  const last = patches[patches.length - 1];
  expect(last?.id).toBe(11);
  // gridstack with `float:false` may compact y upward — the wiring assertion
  // is "PATCH was issued with the dimensions we asked for"; the y the engine
  // settles on depends on layout compaction, so we only nail down x/w/h.
  expect(last?.payload.position?.x).toBe(8);
  expect(last?.payload.position?.w).toBe(3);
  expect(last?.payload.position?.h).toBe(2);
});

test('rename via sidebar updates the displayed name + slug', async ({ page }) => {
  await page.goto('/dashboard.html');
  await page.waitForSelector('.cb-dashboard-sidebar-item');
  // Click the rename button on the first row (Mi Panel).
  await page.locator('.cb-dashboard-sidebar-item').first().locator('.cb-dashboard-sidebar-item-rename').click();
  const input = page.locator('.cb-dashboard-sidebar-rename-input');
  await expect(input).toBeVisible();
  await input.fill('Renombrado');
  await input.press('Enter');

  // After the rename succeeds the new name appears.
  await expect(page.locator('.cb-dashboard-sidebar-item').first()).toContainText('Renombrado');
  const rows = await page.evaluate(() => (window as unknown as { __dashboardFixture: { rows: Array<{ slug: string; name: string }> } }).__dashboardFixture.rows);
  expect(rows[0]?.slug).toBe('renombrado');
  expect(rows[0]?.name).toBe('Renombrado');
});
