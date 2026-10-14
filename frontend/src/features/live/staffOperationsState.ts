import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedIn,signedOut} from './api';
import type {StaffPending} from './staffOperationsApi';
export interface StaffOperationsState {pending:StaffPending|null}
const initialState:StaffOperationsState={pending:null};
const slice=createSlice({name:'staffOperations',initialState,reducers:{submitting(s,a:PayloadAction<StaffPending>){s.pending=a.payload;},settled(s){s.pending=null;}},extraReducers:b=>{b.addCase(signedIn,()=>initialState);b.addCase(signedOut,()=>initialState);}});
export const staffOperationActions=slice.actions;
export const staffOperationsReducer=slice.reducer;
