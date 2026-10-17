import {expect,test} from '@playwright/test';
import {createHmac} from 'node:crypto';
test.use({trace:'off'});
function otp(){let bits='';for(const c of 'JBSWY3DPEHPK3PXP')bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0');const bytes=[];for(let i=0;i+8<=bits.length;i+=8)bytes.push(parseInt(bits.slice(i,i+8),2));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const hash=createHmac('sha1',Buffer.from(bytes)).update(counter).digest();return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');}

test('all shop screens retain the supplied navigation and fit desktop and phone',async({page},info)=>{
 test.setTimeout(180000);
 const variant=info.project.name.startsWith('mobile')?'mobile':'desktop';
 await page.addInitScript(variant=>localStorage.setItem('bizismart.device.v1',`e2e-banking-design-${variant}`),variant);
 await page.goto(`http://e2e-banking-design-${variant}.localhost:15173/`);
 await page.getByLabel('Email address').fill(('0712222301')+'@example.test');await page.getByLabel('Password', {exact:true}).fill('browser-email-password-42');
 await page.getByRole('button',{name:'Sign In',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Banking Browser Supermarket'})).toBeVisible();
 async function capture(name){
  const main=page.locator('main');
  await expect(main.locator('[role="status"]').filter({hasText:/^(Loading|Opening)/})).toHaveCount(0);
  await expect(page.locator('header summary')).toHaveCount(0);
  await expect(main.getByText('Could not connect. Please try again.',{exact:true})).toHaveCount(0);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await main.evaluate(element=>{element.scrollTop=0;});
  await page.screenshot({path:`tmp/design-${name}-${variant}.png`,fullPage:true,animations:'disabled'});
 }
 const screens=[['Dashboard','dashboard'],['Point of Sale','pos'],['Products','products'],['Inventory','inventory'],['Suppliers','suppliers'],['Tills & Cash','tills'],['Customers','customers'],['Staff','staff'],['Reports','reports'],['Banking','banking'],['Messaging','messaging'],['Offline Sync','offline'],['Settings','settings']];
 for(const [label,file] of screens){
  const menu=page.getByRole('button',{name:'Open navigation'});if(await menu.isVisible())await menu.click();
  await page.getByRole('navigation').getByRole('button',{name:label,exact:true}).click();
  await expect(page.locator('main')).toHaveJSProperty('scrollTop',0);
  await capture(file);
  if(file==='pos'){
   if(variant==='desktop'){
    const cart=page.locator('#pos-current-sale');await expect(cart).toBeInViewport();
    const catalogue=await page.locator('.pos-catalogue').boundingBox();const panel=await cart.boundingBox();
    expect(panel.x).toBeGreaterThan(catalogue.x+catalogue.width-2);expect(panel.y).toBeLessThan(100);
    await expect(page.getByRole('button',{name:'Close cart',exact:true})).toBeHidden();
   }
   const photo=page.locator('.pos-product-photo').first();
   if(await photo.isVisible()){const box=await photo.boundingBox();expect(Math.abs(box.width-box.height)).toBeLessThan(2);expect(box.width).toBeGreaterThan(140);}
   await page.locator('.pos-sale-tools summary').click();await expect(page.getByRole('button',{name:'Refund',exact:true})).toBeVisible();await page.locator('.pos-sale-tools summary').click();
   if(variant==='mobile'){
    const entry=page.locator('.pos-cart-entry');await expect(entry).toBeVisible();await entry.click();
    const cart=page.getByRole('dialog',{name:'Current sale',exact:true});await expect(cart).toBeVisible();await expect(cart.getByText('Cart is empty',{exact:true})).toBeVisible();
    await page.keyboard.press('Tab');expect(await cart.evaluate(element=>element.contains(document.activeElement))).toBe(true);
    await capture('pos-cart');await cart.getByRole('button',{name:'Close cart',exact:true}).click();await expect(entry).toBeFocused();await entry.click();await page.keyboard.press('Escape');await expect(cart).toHaveCount(0);await expect(entry).toBeFocused();
   }
  }
  if(file==='reports'&&variant==='mobile')await expect(page.getByRole('combobox',{name:'Choose report',exact:true}).locator('option')).toHaveCount(8);
  if(file==='products'){await page.getByRole('tab',{name:'Promotions & Pricing',exact:true}).click();await expect(page.getByRole('heading',{name:'Pricing & promotions'})).toBeVisible();await capture('pricing');}
  if(file==='staff'){await page.getByRole('tab',{name:'Attendance & notifications',exact:true}).click();await expect(page.getByRole('heading',{name:'Attendance & notifications',exact:true})).toBeVisible();await capture('attendance');}
  if(file==='offline'){
   let release,started;const gate=new Promise(resolve=>{release=resolve;});const requested=new Promise(resolve=>{started=resolve;});
   await page.route('**/api/v1/offline/leases',async route=>{started();await gate;await route.continue();});
   await page.getByRole('button',{name:'Prepare offline catalogue',exact:true}).click();await requested;
   try{
    const menu=page.getByRole('button',{name:'Open navigation'});if(await menu.isVisible())await menu.click();
    await page.getByRole('navigation').getByRole('button',{name:'Banking',exact:true}).click();
    await expect(page.getByText('Finish the pending offline operation before leaving this screen.',{exact:true})).toBeVisible();
    await expect(page.getByRole('heading',{name:'Offline cash checkout',exact:true})).toBeVisible();
   }finally{release();}
   await expect(page.getByRole('button',{name:'Prepare offline catalogue',exact:true})).toBeEnabled();
   await page.unroute('**/api/v1/offline/leases');
  }
  if(file==='settings'){
   await page.getByRole('tab',{name:'Loyalty & commissions',exact:true}).click();await expect(page.getByRole('heading',{name:'Loyalty, commissions & demand',exact:true})).toBeVisible();await capture('rewards');
   await page.getByRole('tab',{name:'Scale labels',exact:true}).click();await expect(page.getByLabel('Prefix',{exact:true})).toBeVisible();await capture('scales');
  }
 }
 await page.locator('header').getByRole('button',{name:'My account',exact:true}).click();await expect(page.getByRole('heading',{name:'Attendance & notifications',exact:true})).toBeVisible();await capture('account');
});
