import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { signedOut } from './api';
import type { IncentiveConfigInput, CommissionPayoutInput } from './incentivesApi';
export type IncentivePending = { action: 'configure'; body: IncentiveConfigInput & { review_hash: string } } | { action: 'payout'; body: CommissionPayoutInput & { review_hash: string } };
export interface IncentiveState { pending: IncentivePending | null }
const initialState: IncentiveState = { pending: null };
const slice = createSlice({ name: 'incentives', initialState, reducers: { incentivePending: (s, a: PayloadAction<IncentivePending>) => { s.pending = a.payload; }, incentiveFinished: () => initialState }, extraReducers: (b) => { b.addCase(signedOut, () => initialState); } });
export const { incentivePending, incentiveFinished } = slice.actions;
export const incentivesReducer = slice.reducer;
