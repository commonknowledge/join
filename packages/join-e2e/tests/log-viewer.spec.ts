import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers';

/**
 * The plugin's log, shown on WP Admin > Join > Logging, holds text that
 * visitors send. It must be shown as text: markup in it must never run in the
 * admin's browser.
 */
test('markup sent by a visitor is shown as text in the log viewer, not run', async ({ page, request }) => {
  const probe = `log-viewer-probe-${Date.now()}`;
  const markup = `<img id="${probe}" src="x" onerror="window.${probe.replace(/-/g, '_')}=true">`;

  // A join on a plan that doesn't exist logs the plan's ID as sent.
  const response = await request.post('/wp-json/join/v1/join', {
    data: { email: 'someone@example.com', membership: markup },
  });
  expect(response.status()).toBe(500);

  await loginAsAdmin(page);
  await page.goto('/wp-admin/admin.php?page=crb_carbon_fields_container_ck_join_flow.php');
  await page.getByRole('tab', { name: 'Logging', exact: true }).click();

  await expect(page.locator('pre', { hasText: `Invalid membership plan: ${markup}` })).toBeVisible();
  await expect(page.locator(`#${probe}`)).toHaveCount(0);
  expect(await page.evaluate((name) => (window as any)[name], probe.replace(/-/g, '_'))).toBeUndefined();
});
