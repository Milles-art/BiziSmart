import {createSlice,type PayloadAction} from '@reduxjs/toolkit';
import {signedOut} from './api';
import type {MessagingWrite} from './messagingApi';
export type MessagingState={pending:MessagingWrite|null};
const slice=createSlice({name:'messaging',initialState:{pending:null} as MessagingState,reducers:{submitting(s,a:PayloadAction<MessagingWrite>){s.pending=a.payload;},settled(s){s.pending=null;}},extraReducers:b=>{b.addCase(signedOut,()=>({pending:null}));}});
export const messagingActions=slice.actions;export const messagingReducer=slice.reducer;
