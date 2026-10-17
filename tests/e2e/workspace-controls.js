import { expect } from '@playwright/test';
// Exercise the responsive UI through its actual controls, preserving financial assertions.
export async function openCart(page) {
  const entry = page.locator('.pos-cart-entry');
  if (await entry.isVisible() && await entry.getAttribute('aria-expanded') === 'false') await entry.click();
}
export async function closeCart(page) {
  const close = page.getByRole('button', { name: 'Close cart', exact: true });
  if (await close.isVisible()) await close.click();
}
export async function openSaleTools(page) {
  await closeCart(page);
  const tools = page.locator('.pos-sale-tools');
  if (await tools.getAttribute('open') === null) await tools.locator('summary').click();
}
export async function chooseReport(page, name) {
  const picker = page.getByRole('combobox', { name: 'Choose report', exact: true });
  if ((page.viewportSize()?.width ?? 1280) < 1024) { await expect(picker).toBeVisible(); await picker.selectOption({ label: name }); }
  else await page.getByRole('navigation', { name: 'Report navigation', exact: true }).getByRole('button', { name, exact: true }).click();
}
