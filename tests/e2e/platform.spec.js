import {expect,test} from '@playwright/test';
import {createHmac} from 'node:crypto';
test.use({trace:'off',actionTimeout:15000});
function otp(secret){let bits='';for(const c of secret)bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0');const bytes=[];for(let i=0;i+8<=bits.length;i+=8)bytes.push(parseInt(bits.slice(i,i+8),2));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const hash=createHmac('sha1',Buffer.from(bytes)).update(counter).digest();return String((hash.readUInt32BE(hash[19]&15)&0x7fffffff)%1000000).padStart(6,'0');}
// The installed Livewire exposes all()/find()/$get(); Alpine.nextTick() flushes
// deferred input bindings. Visible server-rendered HTML alone is not hydration.
async function reactiveReady(page,input){
 await expect.poll(()=>input.evaluate(el=>{const root=el.closest('[wire\\:id]');const id=root?.getAttribute('wire:id');const field=[...el.attributes].some(attribute=>attribute.name.startsWith('wire:model'));return !!id&&typeof window.Livewire?.all==='function'&&window.Livewire.all().some(component=>component.id===id)&&typeof window.Alpine?.nextTick==='function'&&(!field||typeof el._x_model?.get==='function');})).toBe(true);
 await page.evaluate(()=>window.Alpine.nextTick());
}
async function assertBound(input,path,value){
 // Return booleans so failed assertions never print the password or MFA code.
 await expect.poll(()=>input.inputValue().then(actual=>actual===value)).toBe(true);
 await expect.poll(()=>input.evaluate((el,{path,value})=>{const id=el.closest('[wire\\:id]')?.getAttribute('wire:id');const actual=id?window.Livewire.find(id)?.$get(path):undefined;return actual===value||(typeof actual==='number'&&String(actual)===value);},{path,value})).toBe(true);
}
async function fillBound(page,input,value,path){await reactiveReady(page,input);await input.fill(value);await input.blur();await assertBound(input,path,value);}
test('platform email sign-in protects real plan creation and one-use client onboarding',async({page},info)=>{
 const label=info.project.name.startsWith('mobile')?'mobile':'desktop';
 await page.goto('http://admin.localhost:18080/platform-management');await expect(page.getByLabel('Email address')).toBeVisible();
 await fillBound(page,page.getByLabel('Email address'),`platform-${label}@example.test`,'data.email');await fillBound(page,page.getByLabel(/Password/),'browser-platform-password','data.password');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByRole('heading',{name:'Clients, plans & subscriptions',exact:true})).toBeVisible();
 await reactiveReady(page,page.getByRole('button',{name:'Create plan',exact:true}));await page.getByRole('button',{name:'Create plan',exact:true}).click();await expect(page.getByRole('heading',{name:'Plan',exact:true})).toBeVisible();
 await fillBound(page,page.getByLabel('code',{exact:true}),`platform-${label}`,'form.code');await fillBound(page,page.getByLabel('name',{exact:true}),`Platform browser plan ${label}`,'form.name');await fillBound(page,page.getByLabel('price monthly',{exact:true}),'70000','form.price_monthly');await page.getByRole('button',{name:'Save plan',exact:true}).click();await expect(page.getByText('Saved.',{exact:false}).first()).toBeVisible();
 await page.getByRole('button',{name:'Create client',exact:true}).click();await expect(page.getByRole('heading',{name:'Onboard',exact:true})).toBeVisible();
 await fillBound(page,page.getByLabel('name',{exact:true}),`Platform new client ${label}`,'form.name');await fillBound(page,page.getByLabel('subdomain',{exact:true}),`platform-new-${label}`,'form.subdomain');await fillBound(page,page.getByLabel('owner phone',{exact:true}),'0712345678','form.owner_phone');
 const plan=page.getByRole('combobox',{name:'plan code',exact:true});await reactiveReady(page,plan);await plan.selectOption(`platform-${label}`);await plan.blur();await assertBound(plan,'form.plan_code',`platform-${label}`);
 await fillBound(page,page.getByLabel('ends on',{exact:true}),'2026-11-14','form.ends_on');await page.getByRole('button',{name:'Save onboard',exact:true}).click();await expect(page.getByRole('link',{name:'Owner setup link'})).toBeVisible();expect(await page.getByRole('link',{name:'Owner setup link'}).getAttribute('href')).toMatch(/#setup=[a-f0-9]{64}$/);await expect(page.getByText(`Platform new client ${label} · platform-new-${label} · platform-${label}`,{exact:true})).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:`tmp/platform-${label}.png`,fullPage:true});
 const setup=await page.getByRole('link',{name:'Owner setup link'}).getAttribute('href');const owner=await page.context().newPage();const local=new URL(setup);local.protocol='http:';local.port='15173';await owner.goto(local.href);await expect(owner.getByRole('heading',{name:'Set up owner account'})).toBeVisible();expect(new URL(owner.url()).hash).toBe('');await owner.getByLabel('Owner name').fill('Platform onboarded owner');await owner.getByLabel('Email address').fill(`owner-${label}@example.test`);await owner.getByLabel('New password',{exact:true}).fill('browser-email-password-42');await owner.getByLabel('Confirm password',{exact:true}).fill('browser-email-password-42');await owner.getByRole('button',{name:'Create owner account',exact:true}).click();await expect(owner.getByRole('heading',{name:`Platform new client ${label}`})).toBeVisible();await expect(owner.getByRole('button',{name:'WhatsApp & SMS delivery',exact:true})).toHaveCount(0);
 const menu=owner.getByRole('button',{name:'Open navigation'});
 if(owner.viewportSize().width<1024){await expect(menu).toBeVisible();await menu.click();}
 await owner.getByRole('button',{name:'Tills & Cash',exact:true}).click();
 await expect(owner.getByRole('button',{name:'Open till',exact:true})).toBeEnabled();
 await expect(owner.getByText('Main',{exact:true})).toBeVisible();
 await expect(owner.getByText('Ask the owner to assign your branch before opening a till.',{exact:true})).toHaveCount(0);
 await owner.close();
});
