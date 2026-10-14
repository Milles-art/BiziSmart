import {test,expect} from '@playwright/test';

test('encrypted offline queue survives reload and logout in native IndexedDB',async({page},testInfo)=>{
  await page.goto('http://e2e-pos-tools.localhost:15173/');
  const first=await page.evaluate(async()=>{
    localStorage.setItem('bizismart.device.v1','offline_storage_test');
    const vault=await import('/src/features/live/offlineVault.ts');
    const now=Date.now();const lease={lease_id:'ol_TEST',device_uid:'offline_storage_test',cashier_id:'usr_TEST',issued_at:new Date(now-1000).toISOString(),expires_at:new Date(now+86399000).toISOString(),secret:'0'.repeat(64),shop_name:'Synthetic test shop',cashier_name:'Synthetic cashier',branch_name:'Synthetic branch',vat_rate:18,truncated:false,automatic_offers_enabled:false,catalogue:[{snapshot_id:'22222222-2222-4222-8222-222222222222',product_id:'prod_TEST',name:'Synthetic rice',barcode:'123',quantity_type:'weight',unit:'kg',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1000',unit_price:10000,stock:'5',proof:'a'.repeat(64)}]};
    const sale={body:{lease_id:lease.lease_id,device_uid:lease.device_uid,operation_id:'11111111-1111-4111-8111-111111111111',captured_at:new Date().toISOString(),cash_tendered:10000,items:[{snapshot_id:lease.catalogue[0].snapshot_id,proof:lease.catalogue[0].proof,qty:'0.750'}]},secret:lease.secret,cashier_id:lease.cashier_id,shop_name:lease.shop_name,display_lines:[{name:'Synthetic rice',qty:'0.750',unit:'kg'}],estimated_total:7500};
    await vault.saveOfflineLease(lease,lease.cashier_id);await vault.saveOfflineSale(lease,sale);
    const raw=await new Promise((resolve,reject)=>{const request=indexedDB.open('bizismart-offline-v1');request.onerror=()=>reject(request.error);request.onsuccess=()=>{const database=request.result;const tx=database.transaction(['records','keys'],'readonly');const records=tx.objectStore('records').getAll(),keys=tx.objectStore('keys').getAll();tx.oncomplete=()=>{resolve({records:records.result,keys:keys.result});database.close();};tx.onerror=()=>reject(tx.error);};});
    return {queueCount:(await vault.readOfflineQueue(lease.cashier_id)).length,privateCipher:raw.records.every(row=>row.ciphertext instanceof ArrayBuffer&&!JSON.stringify(row).includes('Synthetic')),nonexportable:raw.keys.every(row=>row.key.extractable===false)};
  });
  expect(first).toEqual({queueCount:1,privateCipher:true,nonexportable:true});
  await page.reload();
  await page.getByRole('button',{name:'Recover offline cash sales'}).click();
  await expect(page.getByRole('heading',{name:'Offline cash checkout'})).toBeVisible();
  await expect(page.getByRole('button',{name:/Synthetic rice/})).toBeVisible();
  await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
  await page.screenshot({path:testInfo.outputPath('offline-cash-pane.png'),fullPage:true});
  const recovered=await page.evaluate(async()=>{
    const vault=await import('/src/features/live/offlineVault.ts');
    const before=await vault.loadOfflineLease('usr_TEST'),queue=await vault.readOfflineQueue('usr_TEST');
    await vault.clearOfflineCatalogue();
    return {leaseRecovered:before?.cashier_id==='usr_TEST',operationPreserved:queue[0]?.sale.body.operation_id==='11111111-1111-4111-8111-111111111111',captureRevoked:await vault.loadOfflineLease('usr_TEST')===null,queueRetained:(await vault.readOfflineQueue('usr_TEST')).length===1};
  });
  expect(recovered).toEqual({leaseRecovered:true,operationPreserved:true,captureRevoked:true,queueRetained:true});
});
