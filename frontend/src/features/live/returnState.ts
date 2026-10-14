import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedIn,signedOut,type ReturnCommit,type ReturnResult} from './api';
export interface ReturnState {pending:{key:string;body:ReturnCommit}|null;receipt:ReturnResult|null}
const initialState:ReturnState={pending:null,receipt:null};
const slice=createSlice({name:'returns',initialState,reducers:{
 submitting(state,action:PayloadAction<NonNullable<ReturnState['pending']>>){if(!state.pending)state.pending=action.payload;},
 rejected(state){state.pending=null;},
 confirmed(state,action:PayloadAction<ReturnResult>){state.pending=null;state.receipt=action.payload;},
 clear(state){if(!state.pending)return initialState;},
},extraReducers:(b)=>{b.addCase(signedOut,()=>initialState);b.addCase(signedIn,()=>initialState);}});
export const returnActions=slice.actions;
export const returnReducer=slice.reducer;
