import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
export interface FinancialPending { url: string; body: Record<string, unknown>; label: string }
export interface FinancialState { pending: FinancialPending | null }
const initialState: FinancialState = { pending: null };
const slice = createSlice({ name: 'financial', initialState, reducers: { financialPending: (_s, a: PayloadAction<FinancialPending>) => ({ pending: a.payload }), financialFinished: () => initialState }, extraReducers: b => { b.addCase('session/signedOut', () => initialState); } });
export const { financialPending, financialFinished } = slice.actions;
export const financialReducer = slice.reducer;
