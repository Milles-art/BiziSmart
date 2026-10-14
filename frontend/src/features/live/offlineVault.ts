import Dexie, { type Table } from 'dexie';
import type {OfflineLease,OfflineQueuedSale,OfflineResult} from './offlineTypes';
import { canonicalOfflineJson } from './offlineProof';

interface VaultKey {scope:string; key:CryptoKey}
interface SealedRecord {id:string; scope:string; kind:'catalogue'|'queue'; state:'ready'|'pending'|'quarantined'|'committed'; updated_at:number; iv:ArrayBuffer; ciphertext:ArrayBuffer}
class OfflineDatabase extends Dexie {
  declare keys:Table<VaultKey,string>; declare records:Table<SealedRecord,string>;
  constructor(){super('bizismart-offline-v1');this.version(1).stores({keys:'scope',records:'id,scope,[scope+kind],state,updated_at'});}
}
let database:OfflineDatabase|undefined;
let captureGeneration=0;
function db(){
  if(typeof indexedDB==='undefined'||typeof crypto==='undefined'||!crypto.subtle)throw new Error('Encrypted offline selling is unavailable in this browser. Use a secure supported browser while connected.');
  return database??=new OfflineDatabase();
}
const encoder=new TextEncoder(),decoder=new TextDecoder();
function devicePrefix():string {
  const uid=localStorage.getItem('bizismart.device.v1');
  if(!uid || !/^[A-Za-z0-9_-]{8,128}$/.test(uid))throw new Error('An approved checkout device is required.');
  return `${location.hostname.toLowerCase()}|${uid}|`;
}
function scopeFor(userId:string):string {if(!/^usr_[A-Za-z0-9]+$/.test(userId))throw new Error('The offline cashier identity is invalid.');return devicePrefix()+userId;}
async function keyFor(scope:string,create=false):Promise<CryptoKey>{
  const existing=await db().keys.get(scope);if(existing)return existing.key;
  if(!create)throw new Error('The encrypted offline key is unavailable. Keep this browser data for recovery.');
  const key=await crypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
  try{await db().keys.add({scope,key});return key;}
  catch(error){const raced=await db().keys.get(scope);if(raced)return raced.key;throw error;}
}
async function seal(id:string,scope:string,kind:SealedRecord['kind'],state:SealedRecord['state'],value:unknown):Promise<SealedRecord>{
  const key=await keyFor(scope,true),iv=crypto.getRandomValues(new Uint8Array(12));
  const ciphertext=await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:encoder.encode(`${id}|${scope}|${kind}`)},key,encoder.encode(JSON.stringify(value)));
  return {id,scope,kind,state,updated_at:Date.now(),iv:iv.buffer,ciphertext};
}
async function unseal<T>(record:SealedRecord):Promise<T>{
  if(!record.scope.startsWith(devicePrefix()))throw new Error('This offline record belongs to another shop or device.');
  const key=await keyFor(record.scope);
  const clear=await crypto.subtle.decrypt({name:'AES-GCM',iv:record.iv,additionalData:encoder.encode(`${record.id}|${record.scope}|${record.kind}`)},key,record.ciphertext);
  return JSON.parse(decoder.decode(clear)) as T;
}
export function validateOfflineLease(lease:OfflineLease,userId?:string,allowExpired=false):void {
  if(!lease || !/^ol_[A-Za-z0-9]+$/.test(lease.lease_id)||!/^usr_[A-Za-z0-9]+$/.test(lease.cashier_id)||!Array.isArray(lease.catalogue)||!lease.catalogue.length||!lease.secret||!lease.device_uid)throw new Error('The offline preparation response is incomplete.');
  if(lease.device_uid!==localStorage.getItem('bizismart.device.v1') || (userId && lease.cashier_id!==userId))throw new Error('This offline preparation belongs to another cashier or device.');
  const issued=Date.parse(lease.issued_at),expires=Date.parse(lease.expires_at);
  if(!Number.isFinite(issued)||!Number.isFinite(expires)||expires-issued!==24*60*60*1000||issued>Date.now()+5*60*1000||(!allowExpired&&expires<=Date.now()))throw new Error('The offline authorisation expired. Connect and prepare again.');
  if(lease.automatic_offers_enabled!==false || !/^[a-f0-9]{64}$/.test(lease.secret))throw new Error('The offline cash-only contract is invalid.');
  for(const item of lease.catalogue)if(!Number.isSafeInteger(item.unit_price)||item.unit_price<0||!['counted','weight'].includes(item.quantity_type)||!/^\d+$/.test(item.conversion_atoms)||!item.snapshot_id||!/^[a-f0-9]{64}$/.test(item.proof))throw new Error('The cached catalogue is invalid. Prepare it again online.');
}
export async function saveOfflineLease(lease:OfflineLease,userId:string):Promise<void>{
  const generation=captureGeneration;
  validateOfflineLease(lease,userId);const scope=scopeFor(userId);
  const record=await seal(`catalogue:${scope}`,scope,'catalogue','ready',lease);
  await db().transaction('rw',db().records,async()=>{if(generation!==captureGeneration)throw new Error('Offline preparation was cancelled by sign-out.');await db().records.put(record);});
}
export async function loadOfflineLease(userId?:string):Promise<OfflineLease|null>{
  const generation=captureGeneration;
  const rows=userId?await db().records.where('[scope+kind]').equals([scopeFor(userId),'catalogue']).toArray():await db().records.where('scope').startsWith(devicePrefix()).filter(row=>row.kind==='catalogue').toArray();
  rows.sort((a,b)=>b.updated_at-a.updated_at);if(!rows[0])return null;
  const lease=await unseal<OfflineLease>(rows[0]);if(generation!==captureGeneration)throw new Error('Offline capture was revoked by sign-out.');validateOfflineLease(lease,userId);return lease;
}
export async function saveOfflineSale(lease:OfflineLease,sale:OfflineQueuedSale):Promise<void>{
  validateOfflineLease(lease,sale.cashier_id,true);
  const captured=Date.parse(sale.body.captured_at);
  if(!Number.isFinite(captured)||captured<Date.parse(lease.issued_at)||captured>Date.parse(lease.expires_at))throw new Error('This sale was not captured within the offline authorisation window.');
  if(sale.body.lease_id!==lease.lease_id||sale.body.device_uid!==lease.device_uid||sale.secret!==lease.secret)throw new Error('The offline sale does not match its preparation.');
  const scope=scopeFor(lease.cashier_id),record=await seal(sale.body.operation_id,scope,'queue','pending',sale);
  try{await db().records.add(record);}catch(error){
    const existing=await db().records.get(record.id);if(!existing||existing.scope!==scope)throw error;
    const saved=await unseal<OfflineQueuedSale>(existing);if(canonicalOfflineJson(saved.body)!==canonicalOfflineJson(sale.body))throw new Error('This offline sale reference already has different details.');
  }
}
export async function readOfflineQueue(userId?:string):Promise<{id:string;state:SealedRecord['state'];sale:OfflineQueuedSale}[]>{
  const rows=userId?await db().records.where('[scope+kind]').equals([scopeFor(userId),'queue']).toArray():await db().records.where('scope').startsWith(devicePrefix()).filter(row=>row.kind==='queue').toArray();
  rows.sort((a,b)=>a.updated_at-b.updated_at);
  return Promise.all(rows.map(async row=>({id:row.id,state:row.state,sale:await unseal<OfflineQueuedSale>(row)})));
}
export async function acknowledgeOfflineSale(id:string,result:OfflineResult):Promise<void>{
  const row=await db().records.get(id);if(!row||row.kind!=='queue')throw new Error('The saved offline sale is unavailable.');
  const sale=await unseal<OfflineQueuedSale>(row);
  if(result.operation_id!==sale.body.operation_id)throw new Error('The server acknowledgement does not match this offline sale.');
  if(!['quarantined','committed','committed_with_conflict'].includes(result.status))throw new Error('The server returned an invalid offline status.');
  if(result.status!=='quarantined'&&!result.sale)throw new Error('The server did not return a confirmed receipt.');
  await db().records.put(await seal(id,row.scope,'queue',result.status==='quarantined'?'quarantined':'committed',{...sale,result}));
}
/** Sign-out from any cause revokes local capture; unresolved encrypted sales and their non-exportable keys survive. */
export async function clearOfflineCatalogue():Promise<void>{
  captureGeneration++;
  if(typeof indexedDB==='undefined')return;
  let prefix:string;try{prefix=devicePrefix();}catch{return;}
  await db().transaction('rw',db().records,db().keys,async()=>{
    const rows=await db().records.where('scope').startsWith(prefix).toArray();
    await db().records.bulkDelete(rows.filter(row=>row.kind==='catalogue'||row.state==='committed').map(row=>row.id));
    for(const scope of new Set(rows.map(row=>row.scope)))if(!await db().records.where('scope').equals(scope).count())await db().keys.delete(scope);
  });
}
export async function closeOfflineVaultForTests():Promise<void>{database?.close();database=undefined;}
