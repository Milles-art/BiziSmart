import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { signedIn, signedOut, type CatalogueProduct, type CashSale, type BeginMobilePayment, type CashSaleInput } from './api';

export interface CartEntry { key: string; product: CatalogueProduct; qty: string; discount?:number; packaging_id: string | null }
export interface CheckoutState { discount?:number;discount_reason?:string; entries: CartEntry[]; mobile: {key:string|null;body:BeginMobilePayment|null;id:string|null}|null; pending: { key: string; body: CashSaleInput } | null; receipt: CashSale | null }
const initialState: CheckoutState = {discount:0,discount_reason:'', entries: [], mobile:null, pending: null, receipt: null };
const slice = createSlice({ name: 'checkout', initialState, reducers: {
  add(state, action: PayloadAction<CartEntry>) { if (!state.pending && !state.mobile) { state.entries.push(action.payload); state.receipt = null; } },
  edit(state, action: PayloadAction<{ key: string; qty?: string;discount?:number; packaging_id?: string | null }>) { if (state.pending || state.mobile) return; const row = state.entries.find((r) => r.key === action.payload.key); if (row) { if(action.payload.discount!==undefined)row.discount=action.payload.discount; if (action.payload.qty !== undefined) row.qty = action.payload.qty; if (action.payload.packaging_id !== undefined) row.packaging_id = action.payload.packaging_id; } },
  remove(state, action: PayloadAction<string>) { if (!state.pending && !state.mobile) state.entries = state.entries.filter((r) => r.key !== action.payload); },
  setDiscount(state,action:PayloadAction<{discount:number;reason:string}>){if(!state.pending&&!state.mobile){state.discount=action.payload.discount;state.discount_reason=action.payload.reason;}},
  restore(state,action:PayloadAction<{entries:CartEntry[];discount:number;discount_reason:string}>){if(!state.pending&&!state.mobile){state.entries=action.payload.entries;state.discount=action.payload.discount;state.discount_reason=action.payload.discount_reason;state.receipt=null;}},
  mobileSubmitting(state,action:PayloadAction<NonNullable<CheckoutState['mobile']>>) { state.mobile=action.payload; },
  mobileRejected(state) { state.mobile=null; },
  submitting(state, action: PayloadAction<NonNullable<CheckoutState['pending']>>) { state.pending = action.payload; },
  rejected(state) { state.pending = null; },
  confirmed(state, action: PayloadAction<CashSale>) { state.pending = null; state.mobile=null; state.entries = [];state.discount=0;state.discount_reason=''; state.receipt = action.payload; },
  clear(state) { if (!state.pending && !state.mobile) return initialState; },
}, extraReducers: (b) => { b.addCase(signedOut, () => initialState); b.addCase(signedIn, () => initialState); } });
export const checkoutActions = slice.actions;
export const checkoutReducer = slice.reducer;
