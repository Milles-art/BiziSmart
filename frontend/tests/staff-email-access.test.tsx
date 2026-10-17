// @vitest-environment jsdom
import { configureStore } from '@reduxjs/toolkit';
import { Provider } from 'react-redux';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { transferableAbortController } from 'node:util';
import { liveApi, sessionReducer, signedIn } from '../src/features/live/api';
import { StaffEmailAccess } from '../src/features/live/StaffEmailAccess';

let store:ReturnType<typeof createStore>;
function createStore(){return configureStore({reducer:{session:sessionReducer,[liveApi.reducerPath]:liveApi.reducer},middleware:get=>get().concat(liveApi.middleware)});}
beforeEach(()=>{
  const controller=transferableAbortController();vi.stubGlobal('AbortController',controller.constructor);vi.stubGlobal('AbortSignal',controller.signal.constructor);
  store=createStore();store.dispatch(signedIn({token:'staff-access-test-token',expires_at:new Date(Date.now()+600000).toISOString(),user:{id:'usr_owner',name:'Owner',role:'owner',branch_id:null,permissions:['manage_users']}}));
});
afterEach(()=>{cleanup();store.dispatch(liveApi.util.resetApiState());vi.unstubAllGlobals();sessionStorage.clear();});
it('issues an authenticated one-user link only on request and removes it on hide',async()=>{
  const token='a'.repeat(64);
  const fetch=vi.fn(async(request:Request)=>{
    expect(request.headers.get('Authorization')).toBe('Bearer staff-access-test-token');
    expect(new URL(request.url).pathname).toBe('/api/v1/auth/email/activation-link');
    expect(await request.json()).toEqual({user_id:'usr_cashier'});
    return new Response(JSON.stringify({success:true,data:{setup_url:`https://alpha.bizismart.test/#activate=${token}`,expires_at:new Date().toISOString()}}),{headers:{'Content-Type':'application/json'}});
  });vi.stubGlobal('fetch',fetch);
  render(<Provider store={store}><StaffEmailAccess userId="usr_cashier" enabled={false}/></Provider>);
  expect(fetch).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button',{name:'Create sign-in link'}));
  const link=await screen.findByRole('link',{name:'Open private sign-in setup'});
  expect(link.getAttribute('href')).toContain(`#activate=${token}`);
  expect(link.getAttribute('target')).toBe('_blank');
  expect(link.getAttribute('rel')).toContain('noopener');
  await waitFor(()=>expect(Object.keys(store.getState().liveApi.mutations)).toHaveLength(0));
  expect(sessionStorage.length).toBe(0);
  fireEvent.click(screen.getByRole('button',{name:'Hide private link'}));
  expect(screen.queryByRole('link')).toBeNull();
});
it('uses self-service reset guidance after activation without offering credential takeover',()=>{
  vi.stubGlobal('fetch',vi.fn());
  render(<Provider store={store}><StaffEmailAccess userId="usr_cashier" enabled/></Provider>);
  expect(screen.getByText(/Use Forgot password/)).toBeTruthy();
  expect(screen.queryByRole('button',{name:'Create sign-in link'})).toBeNull();
  expect(fetch).not.toHaveBeenCalled();
});
