// @vitest-environment jsdom
import {afterEach,beforeEach,expect,it,vi} from 'vitest';
import {webcrypto} from 'node:crypto';
import {acknowledgeOfflineSale,clearOfflineCatalogue,closeOfflineVaultForTests,loadOfflineLease,readOfflineQueue,saveOfflineLease,saveOfflineSale} from '../src/features/live/offlineVault';
import type {OfflineLease,OfflineQueuedSale,OfflineResult} from '../src/features/live/offlineTypes';

const storage=vi.hoisted(()=>({keys:new Map<string,unknown>(),records:new Map<string,unknown>()}));
vi.mock('dexie',()=>{
  const table=(map:Map<string,unknown>)=>({
    async get(id:string){return map.get(id);},
    async add(value:Record<string,unknown>){const key=String(value.id??value.scope);if(map.has(key))throw new Error('duplicate');map.set(key,structuredClone(value));},
    async put(value:Record<string,unknown>){map.set(String(value.id??value.scope),structuredClone(value));},
    async delete(id:string){map.delete(id);},async bulkDelete(ids:string[]){ids.forEach(id=>map.delete(id));},
    where(field:string){let predicate=(value:Record<string,unknown>)=>!!value;const query={
      equals(expected:unknown){predicate=value=>field==='[scope+kind]'?JSON.stringify([value.scope,value.kind])===JSON.stringify(expected):value[field]===expected;return query;},
      startsWith(prefix:string){predicate=value=>String(value[field]).startsWith(prefix);return query;},
      filter(filter:(value:Record<string,unknown>)=>boolean){const previous=predicate;predicate=value=>previous(value)&&filter(value);return query;},
      async toArray(){return [...map.values()].filter(value=>predicate(value as Record<string,unknown>));},
      async count(){return (await query.toArray()).length;},
    };return query;},
  });
  return {default:class{keys=table(storage.keys);records=table(storage.records);version(){return {stores:()=>undefined};}close(){}async transaction(...args:unknown[]){return (args.at(-1) as ()=>Promise<unknown>)();}}};
});
beforeEach(async()=>{await closeOfflineVaultForTests();storage.keys.clear();storage.records.clear();localStorage.clear();localStorage.setItem('bizismart.device.v1','device_test');vi.stubGlobal('crypto',webcrypto);vi.stubGlobal('indexedDB',{});});
afterEach(async()=>{await closeOfflineVaultForTests();vi.unstubAllGlobals();});
function lease():OfflineLease{
  const issued=new Date(Date.now()-1000);
  return {lease_id:'ol_TEST',device_uid:'device_test',cashier_id:'usr_TEST',issued_at:issued.toISOString(),expires_at:new Date(issued.getTime()+86400000).toISOString(),secret:'0'.repeat(64),shop_name:'Private shop',cashier_name:'Private cashier',branch_name:'Private branch',vat_rate:18,truncated:false,automatic_offers_enabled:false,
    catalogue:[{snapshot_id:'22222222-2222-4222-8222-222222222222',product_id:'prod_TEST',name:'Private rice',barcode:'123',quantity_type:'weight',unit:'kg',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1000',unit_price:10000,stock:'5',proof:'a'.repeat(64)}]};
}
function sale(prepared:OfflineLease):OfflineQueuedSale{return {body:{lease_id:prepared.lease_id,device_uid:prepared.device_uid,operation_id:'11111111-1111-4111-8111-111111111111',captured_at:new Date().toISOString(),cash_tendered:10000,items:[{snapshot_id:prepared.catalogue[0].snapshot_id,proof:prepared.catalogue[0].proof,qty:'0.750'}]},secret:prepared.secret,cashier_id:prepared.cashier_id,shop_name:prepared.shop_name,display_lines:[{name:'Private rice',qty:'0.750',unit:'kg'}],estimated_total:7500};}
it('stores only ciphertext and a non-exportable key; a fresh vault instance recovers the same immutable queue',async()=>{
  const prepared=lease(),queued=sale(prepared);await saveOfflineLease(prepared,prepared.cashier_id);await saveOfflineSale(prepared,queued);
  for(const record of storage.records.values()){const text=JSON.stringify(record);expect(text).not.toContain('Private');expect(text).not.toContain(prepared.secret);expect(text).not.toContain('10000');}
  for(const value of storage.keys.values())expect((value as {key:CryptoKey}).key.extractable).toBe(false);
  await closeOfflineVaultForTests();expect(await loadOfflineLease(prepared.cashier_id)).toEqual(prepared);expect((await readOfflineQueue(prepared.cashier_id))[0].sale.body).toEqual(queued.body);
});
it('keeps unsynchronised and quarantined sales through logout but removes capture catalogue and confirmed records',async()=>{
  const prepared=lease(),queued=sale(prepared);await saveOfflineLease(prepared,prepared.cashier_id);await saveOfflineSale(prepared,queued);
  const result:OfflineResult={id:'oo_TEST',operation_id:queued.body.operation_id,status:'quarantined',conflicts:['TILL_CLOSED'],sale:null,replayed:false};await acknowledgeOfflineSale(queued.body.operation_id,result);
  await clearOfflineCatalogue();expect(await loadOfflineLease(prepared.cashier_id)).toBeNull();expect((await readOfflineQueue(prepared.cashier_id))[0].state).toBe('quarantined');expect(storage.keys.size).toBe(1);
});
it('deduplicates the identical UUID/body and rejects changed details without replacing the original encrypted sale',async()=>{
  const prepared=lease(),queued=sale(prepared);await saveOfflineSale(prepared,queued);await saveOfflineSale(prepared,queued);
  await expect(saveOfflineSale(prepared,{...queued,body:{...queued.body,cash_tendered:20000}})).rejects.toThrow('different details');
  expect((await readOfflineQueue(prepared.cashier_id))).toHaveLength(1);expect((await readOfflineQueue(prepared.cashier_id))[0].sale.body.cash_tendered).toBe(10000);
});
it('fails closed on wrong device or altered ciphertext while keeping saved data intact',async()=>{
  const prepared=lease();await saveOfflineLease(prepared,prepared.cashier_id);localStorage.setItem('bizismart.device.v1','other_device');expect(await loadOfflineLease()).toBeNull();
  localStorage.setItem('bizismart.device.v1','device_test');const record=[...storage.records.values()][0] as {ciphertext:ArrayBuffer};new Uint8Array(record.ciphertext)[0]^=1;
  await expect(loadOfflineLease(prepared.cashier_id)).rejects.toThrow();expect(storage.records.size).toBe(1);
});
it('retains a genuine already-captured sale after lease expiry and never marks partial server evidence confirmed',async()=>{
  const prepared=lease(),queued=sale(prepared);queued.body.captured_at=new Date(Date.now()-500).toISOString();
  prepared.issued_at=new Date(Date.now()-86401000).toISOString();prepared.expires_at=new Date(Date.parse(prepared.issued_at)+86400000).toISOString();queued.body.captured_at=new Date(Date.parse(prepared.expires_at)-500).toISOString();
  await saveOfflineSale(prepared,queued);await expect(loadOfflineLease(prepared.cashier_id)).resolves.toBeNull();
  await expect(acknowledgeOfflineSale(queued.body.operation_id,{id:'oo_TEST',operation_id:queued.body.operation_id,status:'committed',conflicts:[],sale:null,replayed:false})).rejects.toThrow('confirmed receipt');
  expect((await readOfflineQueue(prepared.cashier_id))[0].state).toBe('pending');
});
