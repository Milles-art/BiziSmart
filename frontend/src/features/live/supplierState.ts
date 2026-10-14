import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedIn,signedOut} from './api';
import type {SupplierPending} from './supplierApi';
export interface BankingPending {action:string;body:Record<string,unknown>}
export interface SupplierState {pending:SupplierPending|null;bankPending:BankingPending|null}
const initialState:SupplierState={pending:null,bankPending:null};
const slice=createSlice({name:'supplierOperations',initialState,reducers:{submitting(s,a:PayloadAction<SupplierPending>){s.pending=a.payload;},settled(s){s.pending=null;},submittingBank(s,a:PayloadAction<BankingPending>){s.bankPending=a.payload;},settledBank(s){s.bankPending=null;}},extraReducers:b=>{b.addCase(signedIn,()=>initialState);b.addCase(signedOut,()=>initialState);}});
export const supplierActions=slice.actions;
export const supplierReducer=slice.reducer;
