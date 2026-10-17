import { useState } from 'react';
import { liveApi, errorMessage } from './api';
import { translate as t, useLanguage } from '../../i18n';

const accessApi = liveApi.injectEndpoints({ endpoints: b => ({
  staffEmailActivation: b.mutation<{ setup_url:string; expires_at:string }, string>({
    query: user_id => ({ url:'auth/email/activation-link', method:'POST', body:{user_id} }),
    transformResponse:(r:{data:{setup_url:string;expires_at:string}})=>r.data,
  }),
}) });

export function StaffEmailAccess({userId,enabled}:{userId:string;enabled:boolean}) {
  useLanguage();
  const [issue] = accessApi.useStaffEmailActivationMutation();
  const [link,setLink] = useState('');
  const [error,setError] = useState('');
  const [busy,setBusy] = useState(false);
  async function create() {
    if(busy)return;
    setBusy(true);setError('');setLink('');
    const request=issue(userId);
    try { const result=await request.unwrap();setLink(result.setup_url); }
    catch(e){setError(errorMessage(e));}
    finally{request.reset();setBusy(false);}
  }
  return <div className="mt-3 space-y-2">
    {enabled?<p className="text-xs text-slate-500">{t('Email sign-in ready. Use Forgot password on the sign-in page to reset it.')}</p>:<button type="button" disabled={busy} onClick={()=>void create()} className="rounded-lg bg-emerald-50 px-3 py-2.5 text-sm font-medium text-emerald-700">{t(busy?'Creating private link…':'Create sign-in link')}</button>}
    {link&&<div role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900"><p>{t('Private link expires in 30 minutes. Give it only to this staff member. They choose their email and password.')}</p><a href={link} target="_blank" rel="noopener noreferrer" className="mt-2 block break-all underline">{t('Open private sign-in setup')}</a><button type="button" className="mt-2 underline" onClick={()=>setLink('')}>{t('Hide private link')}</button></div>}
    {error&&<p role="alert" className="text-sm text-red-600">{error}</p>}
  </div>;
}
