import {afterEach,expect,it,vi} from 'vitest';
import {webcrypto} from 'node:crypto';
import {canonicalOfflineJson,signOfflineBody} from '../src/features/live/offlineProof';
import {offlineDisplayTotal,parseOfflineCash} from '../src/features/live/offlineDisplay';
import type {OfflineSnapshot} from '../src/features/live/offlineTypes';
afterEach(()=>vi.unstubAllGlobals());
const snapshot:OfflineSnapshot={snapshot_id:'snapshot',product_id:'prod_rice',name:'Rice',barcode:null,quantity_type:'weight',unit:'kg',packaging_id:null,packaging_name:null,packaging_version:null,conversion_atoms:'1000',unit_price:8500,stock:'10',proof:'a'.repeat(64)};
it('matches the PHP OfflineProof known vector with exact nested canonical data',async()=>{
  vi.stubGlobal('crypto',webcrypto);
  const body={lease_id:'ol_test',device_uid:'device_test',operation_id:'11111111-1111-4111-8111-111111111111',captured_at:'2026-10-13T07:00:00.000Z',cash_tendered:10000,items:[{snapshot_id:'22222222-2222-4222-8222-222222222222',proof:'a'.repeat(64),qty:'0.750'}]};
  expect(await signOfflineBody(body,'0'.repeat(64))).toBe('f1e13ca15bd7987c592e023fcee443c0d69f8cd16e1639bb6d257ace345232c8');
  expect(await signOfflineBody({...body,cash_tendered:10001},'0'.repeat(64))).not.toBe(await signOfflineBody(body,'0'.repeat(64)));
});
it('normalises object order while preserving array order and rejects floating or unsafe values',()=>{
  expect(canonicalOfflineJson({b:1,a:{z:3,c:2}})).toBe('{"a":{"c":2,"z":3},"b":1}');
  expect(canonicalOfflineJson({items:[2,1]})).toBe('{"items":[2,1]}');
  expect(()=>canonicalOfflineJson({cash:0.5})).toThrow();expect(()=>canonicalOfflineJson({cash:9007199254740992})).toThrow();expect(()=>canonicalOfflineJson({cash:undefined})).toThrow();
});
it('uses exact half-up provisional gram charges and whole-pack prices without stock authority',()=>{
  expect(offlineDisplayTotal([{snapshot,qty:'0.750'}])).toBe(6375);
  expect(offlineDisplayTotal([{snapshot:{...snapshot,unit_price:500},qty:'0.001'}])).toBe(1);
  expect(offlineDisplayTotal([{snapshot:{...snapshot,packaging_id:'pack_one',unit_price:10000,conversion_atoms:'750'},qty:'3'}])).toBe(30000);
  expect(()=>offlineDisplayTotal([{snapshot,qty:'0.0001'}])).toThrow();expect(()=>offlineDisplayTotal([{snapshot,qty:'0'}])).toThrow();
  expect(()=>offlineDisplayTotal([{snapshot:{...snapshot,unit_price:9007199254740991},qty:'2'}])).toThrow();
});
it('never converts decimal or unsafe tendered cash to an integer silently',()=>{
  expect(parseOfflineCash('10000')).toBe(10000);expect(()=>parseOfflineCash('1.5')).toThrow();expect(()=>parseOfflineCash('9007199254740992')).toThrow();
});
