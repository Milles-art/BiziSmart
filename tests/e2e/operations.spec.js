import {closeCart,chooseReport} from './workspace-controls.js';
async function openWorkspaceSection(page,name){await closeCart(page);
 if(name==='My attendance & notifications'){await page.locator('header').getByRole('button',{name:'My account',exact:true}).click();return;}
 const destination=name==='Loyalty, commissions & demand'?'Settings':name==='Offline cash checkout'?'Offline Sync':name==='WhatsApp & SMS delivery'?'Messaging':null;
 if(!destination)throw new Error('Unknown workspace section');
 const menu=page.getByRole('button',{name:'Open navigation'});if((page.viewportSize()?.width??1280)<1024){await expect(menu).toBeVisible();await menu.click();}
 await page.getByRole('navigation').getByRole('button',{name:destination,exact:true}).click();
 if(destination==='Settings')await page.getByRole('tab',{name:'Loyalty & commissions',exact:true}).click();
}
import {expect,test} from '@playwright/test';

import {createHmac} from 'node:crypto';



// Synthetic owner OTP/PIN values must never appear in browser traces.

test.use({trace:'off'});

function otp(){const secret='JBSWY3DPEHPK3PXP';let bits='';for(const c of secret)bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0');const bytes=[];for(let i=0;i+8<=bits.length;i+=8)bytes.push(parseInt(bits.slice(i,i+8),2));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const hash=createHmac('sha1',Buffer.from(bytes)).update(counter).digest();return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');}

async function navigate(page,name){await closeCart(page);await expect(page.getByRole('banner')).toBeVisible();const menu=page.getByRole('button',{name:'Open navigation'});if((page.viewportSize()?.width??1280)<1024){await expect(menu).toBeVisible();await menu.click();}await page.getByRole('button',{name,exact:true}).click();}

async function login(page,label,role){await page.addInitScript(({label,role})=>localStorage.setItem('bizismart.device.v1',`e2e-operations-${label}-${role}`),{label,role});await page.goto(`http://e2e-operations-${label}.localhost:15173/`);await page.getByLabel('Email address').fill((role==='owner'?'0712222201':'0712222202')+'@example.test');await page.getByLabel('Password', {exact:true}).fill('browser-email-password-42');await page.getByRole('button',{name:'Sign In',exact:true}).click();if(role==='owner'){}await expect(page.getByRole('heading',{name:'Operations Browser Supermarket'})).toBeVisible();}



test.describe.serial('connected operations and signed offline cash',()=>{

 let context,page,label;

 test.beforeAll(async({browser},info)=>{label=info.project.name.startsWith('mobile')?'mobile':'desktop';context=await browser.newContext(Object.fromEntries(Object.entries(info.project.use).filter(([key])=>['viewport','deviceScaleFactor','isMobile','hasTouch','userAgent'].includes(key))));page=await context.newPage();});

 test.afterAll(async()=>context?.close());

 test('owner authenticates and empty reports show real zeros',async()=>{

  await login(page,label,'owner');await expect(page.getByRole('img',{name:'Weekly net sales from saved sales and returns'})).toBeVisible();await expect(page.locator('svg.recharts-surface').first()).toBeVisible();await page.screenshot({path:`tmp/operations-dashboard-${label}.png`,fullPage:true});const report=page.waitForResponse(r=>r.url().includes('/api/v1/reports/summary')&&r.ok());await navigate(page,'Reports');const data=(await(await report).json()).data;expect(data.summary.sale_count).toBe(0);expect(data.summary.net_revenue).toBe(0);expect(data.summary.net_profit).toBe(0);expect(data.summary.net_cashflow).toBe(0);expect(data.daily).toHaveLength(1);await expect(page.getByText('TSh 0',{exact:true}).first()).toBeVisible();await expect(page.getByText('Local demonstration only.',{exact:false})).toHaveCount(0);

 });

 test('owner reviews an expense, records bank settlement and preserves reversal refund due',async()=>{

  await chooseReport(page,'Expenses');await page.getByRole('button',{name:'Record expense',exact:true}).click();let dialog=page.getByRole('dialog',{name:'Record expense',exact:true});await dialog.getByLabel('Category',{exact:true}).fill('Browser rent');await dialog.getByLabel('Description',{exact:true}).fill('Operations journal receipt');await dialog.getByLabel('Amount (TSh)',{exact:true}).fill('1000');await dialog.getByRole('button',{name:'Review expense',exact:true}).click();await expect(dialog.getByText(/Reviewed amount: TSh 1,000/)).toBeVisible();await dialog.getByRole('button',{name:'Confirm expense record',exact:true}).click();await expect(dialog).toHaveCount(0);await page.getByRole('button',{name:'Record payment',exact:true}).click();dialog=page.getByRole('dialog',{name:'Record payment',exact:true});await dialog.getByLabel('Completed payment reference').fill(`OPS-BANK-${label}`);await dialog.getByRole('button',{name:'Review payment',exact:true}).click();await dialog.getByRole('button',{name:'Confirm journal entry',exact:true}).click();await expect(dialog).toHaveCount(0);await expect(page.getByText(/Unpaid: TSh 0/)).toBeVisible();await page.getByRole('button',{name:'Reverse expense',exact:true}).click();dialog=page.getByRole('dialog',{name:'Reverse expense',exact:true});await dialog.getByLabel('Amount (TSh)',{exact:true}).fill('400');await dialog.getByLabel('Reversal reason').fill('Part of rent cancelled');await dialog.getByRole('button',{name:'Review reversal',exact:true}).click();await dialog.getByRole('button',{name:'Confirm journal entry',exact:true}).click();await expect(dialog).toHaveCount(0);await expect(page.getByText(/Refund due: TSh 400/)).toBeVisible();const report=page.waitForResponse(r=>r.url().includes('/api/v1/reports/summary')&&r.ok());await chooseReport(page,'Profit & cash flow');const data=(await(await report).json()).data;expect(data.summary.net_expenses).toBe(600);expect(data.summary.net_profit).toBe(-600);expect(data.summary.net_cashflow).toBe(-1000);await page.screenshot({path:`tmp/operations-reports-${label}.png`,fullPage:true});

 });

 test('owner uses attendance preferences, Kiswahili and connected pricing screens',async()=>{

  await openWorkspaceSection(page,'My attendance & notifications');await expect(page.getByRole('heading',{name:'Attendance & notifications',exact:true})).toBeVisible();await page.getByRole('button',{name:'Clock in',exact:true}).click();await expect(page.getByRole('button',{name:'Clock out',exact:true})).toBeVisible();await page.getByRole('button',{name:'Clock out',exact:true}).click();await expect(page.getByRole('button',{name:'Clock in',exact:true})).toBeVisible();await page.getByLabel('WhatsApp notices',{exact:false}).check();await page.getByRole('button',{name:'Save notification preferences',exact:true}).click();await expect(page.getByText('Provider setup required; no external messages will be sent.',{exact:true}).first()).toBeVisible();await page.getByRole('button',{name:'Change language',exact:true}).click();await expect(page.getByRole('heading',{name:'Mahudhurio na arifa',exact:true})).toBeVisible();await page.getByRole('button',{name:'Badilisha lugha',exact:true}).click();await openWorkspaceSection(page,'My attendance & notifications');await navigate(page,'Products');await page.getByRole('tab',{name:'Promotions & Pricing',exact:true}).click();await expect(page.getByRole('heading',{name:'Pricing & promotions',exact:true})).toBeVisible();await page.getByRole('button',{name:'New price / offer',exact:true}).click();await expect(page.getByLabel('Pricing product')).toBeVisible();await page.getByRole('button',{name:'Cancel',exact:true}).click();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);

 });

 test('owner reviews and saves supplier directory details',async()=>{

  await navigate(page,'Suppliers');await page.getByRole('button',{name:'Add supplier',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByLabel('Supplier name',{exact:true}).fill('Operations rice supplier');await dialog.getByLabel('Supplier phone',{exact:true}).fill('0713333301');await dialog.getByRole('button',{name:'Review supplier operation',exact:true}).click();await expect(dialog.getByText('Authoritative review',{exact:true})).toBeVisible();await dialog.getByRole('button',{name:'Confirm supplier operation',exact:true}).click();await expect(dialog).toHaveCount(0);const supplier=page.getByRole('region',{name:'Supplier directory'}).getByRole('article').filter({has:page.getByRole('heading',{name:'Operations rice supplier',exact:true})});await expect(supplier.getByRole('heading',{name:'Operations rice supplier',exact:true})).toBeVisible();await expect(supplier.getByText('Active',{exact:true})).toBeVisible();

 });

 test('issued supplier orders create no payable until reviewed goods are received',async()=>{

  await page.getByRole('tab',{name:'Purchase orders',exact:true}).click();await page.getByRole('button',{name:'New purchase order',exact:true}).click();let dialog=page.getByRole('dialog');await dialog.getByLabel('Supplier',{exact:true}).selectOption({label:'Operations rice supplier'});await dialog.getByLabel('Find product',{exact:true}).fill('Operations water');await dialog.getByLabel('Add supplier product',{exact:true}).selectOption({label:'Operations water'});await dialog.getByLabel('Order status',{exact:true}).selectOption('issued');await dialog.getByRole('button',{name:'Review supplier operation',exact:true}).click();await expect(dialog.getByText('Total: TSh 100',{exact:true})).toBeVisible();await dialog.getByRole('button',{name:'Confirm supplier operation',exact:true}).click();await expect(dialog).toHaveCount(0);await page.getByRole('tab',{name:'Directory',exact:true}).click();await expect(page.getByText('Payable',{exact:true}).locator('..').getByRole('definition')).toHaveText('TSh 0');await page.getByRole('tab',{name:'Receiving',exact:true}).click();await page.getByRole('button',{name:'Receive supplier delivery',exact:true}).click();dialog=page.getByRole('dialog');await dialog.getByLabel('Issued purchase order',{exact:true}).selectOption({label:'Operations rice supplier · Operations water'});await dialog.getByLabel('Batch number · supplier line 1',{exact:true}).fill('OPS-SUPPLIER');await dialog.getByLabel('Supplier invoice reference',{exact:true}).fill(`OPS-INVOICE-${label}`);await dialog.getByRole('button',{name:'Review supplier operation',exact:true}).click();await dialog.getByRole('button',{name:'Confirm supplier operation',exact:true}).click();await expect(dialog).toHaveCount(0);await page.getByRole('tab',{name:'Payables',exact:true}).click();const invoice=page.getByRole('row').filter({has:page.getByText(`OPS-INVOICE-${label}`,{exact:true})});await expect(invoice.getByRole('cell').nth(2)).toHaveText('TSh 100');await expect(invoice.getByRole('cell').nth(3)).toHaveText('TSh 100');await page.getByRole('button',{name:'Sign out',exact:true}).click();await expect(page.getByRole('heading',{name:'Sign In',exact:true})).toBeVisible();

 });

 test('cashier prepares encrypted offline cash, reconnects and receives one real sale',async({page:cashier})=>{

  await login(cashier,label,'cashier');await navigate(cashier,'Tills & Cash');await cashier.getByRole('button',{name:'Open till',exact:true}).click();const opening=cashier.getByRole('dialog',{name:'Open till',exact:true});await opening.getByLabel('Opening float (TSh)').fill('20000');await opening.getByRole('button',{name:'Confirm opening',exact:true}).click();await expect(cashier.getByText('Till opened.',{exact:true})).toBeVisible();await openWorkspaceSection(cashier,'Offline cash checkout');await cashier.getByRole('button',{name:'Prepare offline catalogue',exact:true}).click();await expect(cashier.getByText('Offline catalogue prepared for 24 hours.',{exact:true})).toBeVisible();await cashier.context().setOffline(true);await cashier.getByRole('button',{name:/Operations water.*TSh 1,000/}).click();await cashier.getByLabel('Cash tendered (TSh)',{exact:true}).fill('1000');await cashier.getByRole('button',{name:'Save cash sale locally',exact:true}).click();await expect(cashier.getByText(/Awaiting server confirmation · TSh 1,000/)).toBeVisible();expect(await cashier.evaluate(()=>new Promise((resolve,reject)=>{const request=indexedDB.open('bizismart-offline-v1');request.onerror=()=>reject(request.error);request.onsuccess=()=>{const db=request.result;const rows=db.transaction('records').objectStore('records').getAll();rows.onsuccess=()=>{const queue=rows.result.filter(row=>row.kind==='queue');resolve(queue.length===1&&queue.every(row=>row.ciphertext instanceof ArrayBuffer&&row.iv instanceof ArrayBuffer&&!JSON.stringify(row).includes('Operations water')));db.close();};rows.onerror=()=>reject(rows.error);};}))).toBe(true);await cashier.context().setOffline(false);const sync=cashier.waitForResponse(r=>r.url().endsWith('/api/v1/offline/sync')&&r.request().method()==='POST');await cashier.getByRole('button',{name:'Synchronise saved sales',exact:true}).click();const result=await sync;expect(result.ok()).toBe(true);expect(result.request().headers()['x-offline-signature']).toMatch(/^[a-f0-9]{64}$/);expect(result.request().headers()['authorization']).toBeUndefined();const data=(await result.json()).data;expect(data.status).toBe('committed');expect(data.sale.total).toBe(1000);await expect(cashier.getByText(/Server confirmed · TSh 1,000/)).toBeVisible();await cashier.screenshot({path:`tmp/operations-offline-${label}.png`,fullPage:true});await cashier.getByRole('button',{name:'Return to connected app',exact:true}).click();await navigate(cashier,'Tills & Cash');await expect(cashier.getByText('TSh 21,000',{exact:true}).first()).toBeVisible();await navigate(cashier,'Point of Sale');await expect(cashier.getByRole('button',{name:/Operations water.*TSh 1,000.*10 bottle available/})).toBeVisible();await cashier.getByRole('button',{name:'Sign out',exact:true}).click();await cashier.reload();await expect(cashier.getByRole('heading',{name:'Sign In',exact:true})).toBeVisible();await expect(cashier.getByRole('heading',{name:'Operations Browser Supermarket',exact:true})).toHaveCount(0);expect(await cashier.evaluate(()=>sessionStorage.getItem('bizismart.session.v1'))).toBeNull();

 });

});

