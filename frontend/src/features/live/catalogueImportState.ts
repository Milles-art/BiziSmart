import { createSlice, type PayloadAction } from '@reduxjs/toolkit';
import { signedOut } from './api';
import type { ImportCommit } from './catalogueImportApi';
export interface CatalogueImportState { pending: ImportCommit | null }
const initialState: CatalogueImportState = { pending: null };
const slice = createSlice({ name: 'catalogueImport', initialState, reducers: {
  importPending: (state, action: PayloadAction<ImportCommit>) => { state.pending = action.payload; },
  importFinished: () => initialState,
}, extraReducers: (builder) => { builder.addCase(signedOut, () => initialState); } });
export const { importPending, importFinished } = slice.actions;
export const catalogueImportReducer = slice.reducer;
