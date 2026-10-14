import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedIn,signedOut} from './api';
import type {OfflineReconcileCommit} from './offlineTypes';
export interface OfflineReconciliationState {pending:{id:string;body:OfflineReconcileCommit}|null}
const initial:OfflineReconciliationState={pending:null};
const slice=createSlice({name:'offlineReconciliation',initialState:initial,reducers:{submitting(state,action:PayloadAction<NonNullable<OfflineReconciliationState['pending']>>){if(!state.pending)state.pending=action.payload;},settled(state){state.pending=null;}},extraReducers:builder=>{builder.addCase(signedOut,()=>initial);builder.addCase(signedIn,()=>initial);}});
export const offlineReconciliationActions=slice.actions;
export const offlineReconciliationReducer=slice.reducer;
