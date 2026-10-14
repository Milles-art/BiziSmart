import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { signedOut } from './api';
import type { PricingInput } from './pricingApi';
export type PricingPending = { action: 'create'; body: PricingInput & { review_hash: string } } | { action: 'cancel'; id: string; body: { operation_id: string; reason: string; review_hash: string } };
export interface PricingState { pending: PricingPending | null }
const initialState: PricingState = { pending: null };
const slice = createSlice({ name: 'pricing', initialState, reducers: { pricingPending: (s, a: PayloadAction<PricingPending>) => { s.pending = a.payload; }, pricingFinished: () => initialState }, extraReducers: (b) => { b.addCase(signedOut, () => initialState); } });
export const { pricingPending, pricingFinished } = slice.actions;
export const pricingReducer = slice.reducer;
