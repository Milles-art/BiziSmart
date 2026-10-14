import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedIn,signedOut,type ManualRefundCommit} from './api';
export interface MobileRefundState {pending:{id:string;body:ManualRefundCommit}|null}
const initialState:MobileRefundState={pending:null};
const slice=createSlice({name:'mobileRefund',initialState,reducers:{submitting:(state,action:PayloadAction<NonNullable<MobileRefundState['pending']>>)=>{state.pending=action.payload;},cleared:()=>initialState},extraReducers:b=>{b.addCase(signedOut,()=>initialState);b.addCase(signedIn,()=>initialState);}});
export const mobileRefundActions=slice.actions;export const mobileRefundReducer=slice.reducer;
