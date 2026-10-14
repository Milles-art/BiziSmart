import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { signedIn, signedOut, type CollectionInput, type CollectionPayment, type BeginMobilePayment } from './api';
export interface CollectionState { mobile:{key:string|null;body:BeginMobilePayment|null;id:string|null}|null; pending: { customer:string; key:string; body:CollectionInput & {review_hash:string} } | null; receipt:CollectionPayment | null }
const initialState:CollectionState = {mobile:null,pending:null,receipt:null};
const slice = createSlice({name:'collection',initialState,reducers:{
  mobileSubmitting(state,action:PayloadAction<NonNullable<CollectionState['mobile']>>) {state.mobile=action.payload;},
  mobileRejected(state) {state.mobile=null;},
  submitting(state,action:PayloadAction<NonNullable<CollectionState['pending']>>) { if (!state.pending) state.pending = action.payload; },
  rejected(state) { state.pending = null; },
  confirmed(state,action:PayloadAction<CollectionPayment>) { state.mobile=null; state.pending = null; state.receipt = action.payload; },
  clear(state) { if (!state.pending&&!state.mobile) return initialState; },
},extraReducers:(b)=>{b.addCase(signedOut,()=>initialState);b.addCase(signedIn,()=>initialState);}});
export const collectionActions = slice.actions;
export const collectionReducer = slice.reducer;
