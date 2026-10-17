import { expect, test } from '@playwright/test';
import { createHmac } from 'node:crypto';

test.use({ trace: 'off', actionTimeout: 15000 });
function otp() {
  let bits = ''; for (const character of 'JBSWY3DPEHPK3PXP') bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(character).toString(2).padStart(5, '0');
  const bytes = []; for (let offset = 0; offset + 8 <= bits.length; offset += 8) bytes.push(parseInt(bits.slice(offset, offset + 8), 2));
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac('sha1', Buffer.from(bytes)).update(counter).digest();
  return String((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

test('square product cards retain catalogue operations and fit the workspace', async ({ page }, info) => {
  const label = info.project.name.startsWith('mobile') ? 'mobile' : 'desktop';
  if(label === 'desktop') await page.setViewportSize({ width: 1536, height: 1100 });
  await page.addInitScript(label => localStorage.setItem('bizismart.device.v1', `e2e-banking-cards-${label}`), label);
  await page.goto(`http://e2e-banking-cards-${label}.localhost:15173/`);
  await page.getByLabel('Email address').fill(('0712222301')+'@example.test'); await page.getByLabel('Password', {exact:true}).fill('browser-email-password-42');
  await page.getByRole('button', { name: 'Sign In', exact: true }).click();
   
  await expect(page.getByRole('heading', { name: 'Banking Browser Supermarket' })).toBeVisible();
  const navigation = page.getByRole('button', { name: 'Open navigation' });
  if(label === 'mobile') { await expect(navigation).toBeVisible(); await navigation.click(); }
  await page.getByRole('navigation').getByRole('button', { name: 'Products', exact: true }).click();
  const card = page.getByRole('article', { name: 'Product Basmati Rice 1 kg', exact: true });
  await expect(card).toBeVisible();
  await expect(page.getByRole('article')).toHaveCount(8);
  await expect(page.getByRole('button', { name: 'Grid view' })).toHaveAttribute('aria-pressed', 'true');
  await expect(card.getByText('TSh 4,500', { exact: true })).toBeVisible();
  await expect(card.getByText('12 piece', { exact: true })).toBeVisible();
  await expect(card.getByText('No photo yet', { exact: true })).toBeVisible();
  await expect(page.getByRole('article', { name: 'Product Brown Bread 400 g' }).getByText('Out of stock', { exact: true })).toBeVisible();
  for(const element of await page.getByRole('article').all()) {
    const bounds = await element.boundingBox(); expect(Math.abs(bounds.width - bounds.height)).toBeLessThanOrEqual(1);
    expect(await element.evaluate(node => node.scrollHeight <= node.clientHeight && node.scrollWidth <= node.clientWidth)).toBe(true);
  }
  if(label === 'desktop') expect(await page.locator('.catalogue-product-grid').evaluate(node => getComputedStyle(node).gridTemplateColumns.split(' ').length)).toBe(4);
  expect((await page.getByLabel('Category', { exact: true }).boundingBox()).width).toBeGreaterThan(100);
  expect((await page.getByRole('combobox', { name: 'Department filter', exact: true }).boundingBox()).width).toBeGreaterThan(100);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.locator('main').evaluate(node => { node.scrollTop = 0; });
  await page.screenshot({ path: `tmp/product-cards-${label}.png`, fullPage: true, animations: 'disabled' });
  const actions = card.getByRole('button', { name: 'Actions for Basmati Rice 1 kg' });
  await actions.focus(); await actions.press('Enter');
  await expect(page.getByRole('menuitem', { name: 'Product details', exact: true })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(page.getByRole('menu')).toHaveCount(0);
  await expect(actions).toBeFocused();
  await card.getByRole('button', { name: 'Actions for Basmati Rice 1 kg' }).click();
  await page.getByRole('menuitem', { name: 'Product details', exact: true }).click();
  const details = page.getByRole('dialog', { name: 'Basmati Rice 1 kg', exact: true });
  await expect(details.getByText('TSh 500', { exact: true })).toBeVisible();
  await details.getByRole('button', { name: 'Close product details' }).click(); await expect(details).toHaveCount(0);
  await card.getByRole('button', { name: 'Actions for Basmati Rice 1 kg' }).click();
  await page.getByRole('menuitem', { name: 'Stock for Basmati Rice 1 kg' }).click();
  const batches = page.getByRole('dialog', { name: 'Stock batches · Basmati Rice 1 kg', exact: true });
  await expect(batches.getByText('CARDS-0', { exact: true })).toBeVisible();
  await batches.getByRole('button', { name: 'Close stock batches' }).click(); await expect(batches).toHaveCount(0);
  await page.getByRole('button', { name: 'List view', exact: true }).click();
  await expect(page.getByRole('row', { name: 'Product Basmati Rice 1 kg', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Filters', exact: true }).click();
  await page.getByLabel('Product name', { exact: true }).fill('Basmati'); await page.getByRole('button', { name: 'Search', exact: true }).click();
  await expect(page.locator('tbody').getByRole('row')).toHaveCount(1);
  await page.getByRole('button', { name: 'Grid view', exact: true }).click(); await expect(page.getByRole('article')).toHaveCount(1);
  await expect(card).toBeVisible();
  await card.getByRole('button', { name: 'Edit Basmati Rice 1 kg' }).click();
  const edit = page.getByRole('dialog', { name: 'Edit Product', exact: true }); await expect(edit.getByLabel('Product name', { exact: true })).toHaveValue('Basmati Rice 1 kg');
  await edit.getByRole('button', { name: 'Cancel', exact: true }).click(); await expect(edit).toHaveCount(0);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
