import {expect,it} from 'vitest';
import {canAccessShopScreen} from '../src/features/live/shopNavigation';

it('keeps owner-only banking and messaging separate from cashier offline cash access and module entitlements',()=>{
 const owner={role:'owner',permissions:['sell','manage_settings']};
 const cashier={role:'cashier',permissions:['sell']};
 const stockkeeper={role:'stockkeeper',permissions:['receive_stock']};
 expect(canAccessShopScreen('banking',owner,{banking:true,suppliers:true})).toBe(true);
 expect(canAccessShopScreen('banking',owner,{banking:false,suppliers:true})).toBe(false);
 expect(canAccessShopScreen('banking',owner,{banking:true,suppliers:false})).toBe(false);
 expect(canAccessShopScreen('banking',cashier,{banking:true,suppliers:true})).toBe(false);
 expect(canAccessShopScreen('messaging',cashier,{messaging:true})).toBe(false);
 expect(canAccessShopScreen('messaging',owner,{messaging:false})).toBe(false);
 expect(canAccessShopScreen('messaging',owner,{messaging:true})).toBe(true);
 expect(canAccessShopScreen('offline',cashier,{offline:true})).toBe(true);
 expect(canAccessShopScreen('offline',cashier,{offline:false})).toBe(false);
 expect(canAccessShopScreen('offline',stockkeeper,{offline:true})).toBe(false);
 expect(canAccessShopScreen('account',cashier,{})).toBe(true);
 expect(canAccessShopScreen('staff',cashier,{})).toBe(false);
 expect(canAccessShopScreen('inventory',stockkeeper,{inventory:true})).toBe(true);
 expect(canAccessShopScreen('inventory',stockkeeper,{inventory:false})).toBe(false);
});
