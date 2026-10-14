import { createSlice,type PayloadAction } from '@reduxjs/toolkit';
import { signedIn,signedOut } from './api';
import type { HoldOperation } from './heldApi';
export interface HeldState {pending:HoldOperation|null}
const initialState:HeldState={pending:null};
const slice=createSlice({name:'held',initialState,reducers:{
  submitting(state,action:PayloadAction<HoldOperation>){state.pending=action.payload;},
  settled(state){state.pending=null;},
},extraReducers:(b)=>{b.addCase(signedOut,()=>initialState);b.addCase(signedIn,()=>initialState);}});
export const heldActions=slice.actions;
export const heldReducer=slice.reducer;
