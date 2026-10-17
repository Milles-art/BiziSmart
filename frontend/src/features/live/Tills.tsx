import { translate, useLanguage } from '../../i18n';
import { useState } from 'react';
import { TillCards } from './TillCards';
import { Dialog } from '@mui/material';
import { Banknote, Calculator, Shield, Wallet, DollarSign, Eye, FileText, CircleCheck } from 'lucide-react';
import { Badge, SectionCard, StatCard } from '../../components/ui/Primitives';
import { errorMessage, useCurrentTillQuery, useTillsQuery, useOpenTillMutation, usePaidOutMutation, usePreviewTillCloseMutation, useCloseTillMutation, useSupervisorCheckMutation, type ServerUser, type TillReview, type TillCloseCommit } from './api';
const money = (value: number) => 'TSh ' + value.toLocaleString('en-TZ');
const input = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
const primary = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white disabled:opacity-40';
type OpenBody = { operation_id: string; branch_id: string; opening_float: number };
type PaidBody = { operation_id: string; till_id: string; amount: number; reason: string };
type Pending = { kind: 'open'; body: OpenBody } | { kind: 'paid'; body: PaidBody } | { kind: 'close'; body: TillCloseCommit };
function message(error: unknown) { return (error as { data?: { error?: { code?: string } } }).data?.error?.code === 'NOT_FOUND' ? 'This till or branch is no longer available. Refresh the till list.' : errorMessage(error); }
export function Tills({ actor, onReports }: { actor: ServerUser; onReports?:()=>void }) {
  useLanguage();
  const current = useCurrentTillQuery(); const [status, setStatus] = useState<'open' | 'closed'>('open'); const [page, setPage] = useState(1); const list = useTillsQuery({ page, status });
  const [mode, setMode] = useState<'open' | 'paid' | 'close' | null>(null); const [amount, setAmount] = useState(''); const [reason, setReason] = useState('');
  const [review, setReview] = useState<TillReview | null>(null); const [grant, setGrant] = useState<string | null>(null); const [phone, setPhone] = useState(''); const [pin, setPin] = useState('');
  const [pending, setPending] = useState<Pending | null>(null); const [busy, setBusy] = useState(false); const [failure, setFailure] = useState(''); const [notice, setNotice] = useState('');
  const [open] = useOpenTillMutation(); const [paid] = usePaidOutMutation(); const [preview] = usePreviewTillCloseMutation(); const [close] = useCloseTillMutation(); const [approve] = useSupervisorCheckMutation();
  function reset() { if (busy || pending) return; setMode(null); setReview(null); setGrant(null); setPhone(''); setPin('');  setFailure(''); }
  function start(value: 'open' | 'paid' | 'close') { setMode(value); setAmount(''); setReason(''); setReview(null); setGrant(null); setFailure(''); setNotice(''); }
  async function inspect() {
    if (!current.data?.till) return; setBusy(true); setFailure('');
    const request = preview({ operation_id: crypto.randomUUID(), till_id: current.data.till.id, counted_cash: Number(amount) });
    try { setReview(await request.unwrap()); } catch (e) { setFailure(message(e)); } finally { request.reset(); setBusy(false); }
  }
  async function approval() {
    if (!review) return; setBusy(true); setFailure('');
    const request = approve({ email: phone, password: pin, permission: 'approve_variance', operation_id: review.snapshot.operation_id, payload_hash: review.payload_hash });
    try { setGrant((await request.unwrap()).approval_token); } catch (e) { setFailure(message(e)); } finally { request.reset(); setPhone(''); setPin('');  setBusy(false); }
  }
  async function commit() {
    let operation = pending;
    if (!operation) {
      if (mode === 'open' && current.data?.branch) operation = { kind: 'open', body: { operation_id: crypto.randomUUID(), branch_id: current.data.branch.id, opening_float: Number(amount) } };
      if (mode === 'paid' && current.data?.till) operation = { kind: 'paid', body: { operation_id: crypto.randomUUID(), till_id: current.data.till.id, amount: Number(amount), reason } };
      if (mode === 'close' && review) { const { branch_id: _branch, ...snapshot } = review.snapshot; void _branch; operation = { kind: 'close', body: { ...snapshot, ...(grant ? { approval_token: grant } : {}) } }; }
    }
    if (!operation) return; setPending(operation); setBusy(true); setFailure('');
    const request = operation.kind === 'open' ? open(operation.body) : operation.kind === 'paid' ? paid(operation.body) : close(operation.body);
    try { const result = await request.unwrap(); setNotice(operation.kind === 'close' ? 'Till closed. Variance: ' + money(result.till.variance ?? 0) : operation.kind === 'paid' ? 'Cash paid-out recorded.' : 'Till opened.'); setPending(null); setMode(null); setReview(null); setGrant(null); }
    catch (e) { setFailure(message(e)); const s = (e as { status?: number | string }).status; if (typeof s === 'number' && s < 500 && s !== 408) { setPending(null); setGrant(null); if (s === 409) setReview(null); } }
    finally { request.reset(); setPhone(''); setPin('');  setBusy(false); }
  }
  const till = current.data?.till; const title = mode === 'open' ? 'Open till' : mode === 'paid' ? 'Cash paid-out' : 'Close till';
  return <div className="space-y-5 p-4 lg:p-6">
    {notice && <p role="status" className="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    <SectionCard title={translate("Your till")} action={current.data && !current.error ? <Badge status={till ? 'Open' : 'Closed'} variant={till ? 'success' : 'neutral'} /> : undefined}><div className="space-y-3 text-sm text-slate-600">
      {current.isLoading ? <p>{translate("Loading your till…")}</p> : current.error ? <><p role="alert">{message(current.error)}</p><button onClick={() => void current.refetch()}>{translate("Retry till")}</button></> : <>
        <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"><div className="flex min-w-0 items-start gap-3"><div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><Calculator className="h-5 w-5" /></div><div className="min-w-0"><p className="break-words text-base font-semibold text-slate-800">{current.data?.branch?.name ?? translate('No branch assigned')}</p><p className="mt-1 text-sm text-slate-500">{till ? translate('Your session is ready for cash sales.') : current.data?.branch ? translate('Count your opening cash before you start selling.') : translate('Ask the owner to assign your branch before opening a till.')}</p>{till && <p className="mt-2 text-xs text-slate-400">{translate('Opened')} {new Date(till.opened_at).toLocaleString('en-TZ', { timeZone: 'Africa/Dar_es_Salaam' })}</p>}</div></div>
          {till ? <div className="flex shrink-0 flex-col gap-2 sm:flex-row"><button className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50" onClick={() => start('paid')}><DollarSign className="h-4 w-4" />{translate('Record paid-out')}</button><button className={primary + ' py-2.5'} onClick={() => start('close')}><Eye className="h-4 w-4" />{translate('Close till')}</button></div> : <button className={primary + ' shrink-0 py-2.5'} disabled={!current.data?.can_open} onClick={() => start('open')}><CircleCheck className="h-4 w-4" />{translate('Open till')}</button>}
        </div>
      </>}
    </div></SectionCard>
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4"><StatCard title={translate("Your expected cash")} value={till ? money(till.expected_cash) : '—'} icon={<Banknote className="h-5 w-5 text-white" />} color="bg-emerald-500" /><StatCard title={translate("Opening float")} value={till ? money(till.opening_float) : '—'} icon={<Wallet className="h-5 w-5 text-white" />} color="bg-blue-500" /><StatCard title={translate("Your till")} value={till ? translate("Open") : current.data ? translate("Closed") : '—'} icon={<Calculator className="h-5 w-5 text-white" />} color="bg-amber-500" /><StatCard title={translate("Variance limit")} value={current.data ? money(current.data.variance_limit) : '—'} icon={<Shield className="h-5 w-5 text-white" />} color="bg-violet-500" /></div>
    {actor.role === 'owner' && !!list.data?.meta.owner_review_count && <p role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{list.data.meta.owner_review_count} {translate("closed till(s) have an approved variance above the limit. Review closing history.")}</p>}
    <SectionCard title={actor.role === 'cashier' ? translate("Your sessions") : translate("Till overview")} action={onReports&&<button onClick={onReports} className="flex items-center gap-1.5 text-sm font-medium text-emerald-700"><FileText className="h-4 w-4"/>{translate("Open till reports")}</button>}><div><div role="group" aria-label={translate('Session history')} className="mb-4 flex w-fit max-w-full gap-1 rounded-lg bg-slate-100 p-1"><button aria-pressed={status === 'open'} onClick={() => { setStatus('open'); setPage(1); }} className={'rounded-md px-3 py-2 text-sm font-medium ' + (status === 'open' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500')}>{translate("Open sessions")}</button><button aria-pressed={status === 'closed'} onClick={() => { setStatus('closed'); setPage(1); }} className={'rounded-md px-3 py-2 text-sm font-medium ' + (status === 'closed' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500')}>{translate("Closing history")}</button></div>
      {list.error ? <><p role="alert" className="text-sm text-red-600">{message(list.error)}</p><button onClick={() => void list.refetch()}>{translate("Retry sessions")}</button></> : list.isFetching ? <p className="text-sm text-slate-500">{translate("Loading sessions…")}</p> : status === 'open' ? <TillCards tills={list.data?.tills ?? []} /> : <div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b text-xs text-slate-400"><tr><th className="p-3">{translate("Cashier / branch")}</th><th className="p-3">{translate("Opened")}</th><th className="p-3">{translate("Expected cash")}</th><th className="p-3">{translate("Counted cash")}</th><th className="p-3">{translate("Variance")}</th></tr></thead><tbody>{list.data?.tills.map((row) => <tr key={row.id} className="border-b border-slate-100"><td className="p-3 font-medium text-slate-700">{row.cashier_name}<p className="text-xs font-normal text-slate-400">{row.branch_name}</p></td><td className="p-3 text-slate-500">{new Date(row.opened_at).toLocaleString('en-TZ', { timeZone: 'Africa/Dar_es_Salaam' })}</td><td className="p-3">{money(row.expected_cash)}</td><td className="p-3">{row.closing_cash === null ? '—' : money(row.closing_cash)}</td><td className="p-3">{row.variance === null ? '—' : money(row.variance)}{row.approved_by && <p className="text-xs text-emerald-600">{translate("Supervisor approved")}</p>}</td></tr>)}</tbody></table>{!list.data?.tills.length && <p className="py-5 text-sm text-slate-400">{translate("No")} {translate(status)} {translate("sessions.")}</p>}</div>}
      <div className="mt-4 flex items-center gap-4 text-sm"><button disabled={page === 1 || list.isFetching} onClick={() => setPage(page - 1)}>{translate("Previous")}</button><span>{translate("Page")} {page}</span><button disabled={list.isFetching || page * 10 >= (list.data?.meta.total ?? 0)} onClick={() => setPage(page + 1)}>{translate("Next")}</button></div>
    </div></SectionCard>
    <Dialog open={!!mode} onClose={reset} fullWidth maxWidth="sm" slotProps={{ paper: { sx: { borderRadius: "16px" } } }} aria-labelledby="till-dialog-title"><div className="p-5 sm:p-6"><h2 id="till-dialog-title" className="text-lg font-bold text-slate-800">{translate(title)}</h2>
      {failure && <p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(failure)}</p>}
      {pending && !busy && <p className="mt-3 text-sm text-amber-700">{translate("The result is not confirmed. Retry the same operation; it will not be recorded twice.")}</p>}
      {!review ? <form onSubmit={(e) => { e.preventDefault(); if (!busy) void (mode === 'close' ? inspect() : commit()); }}><fieldset disabled={busy || !!pending} className="mt-4 space-y-3"><label className="block text-xs text-slate-500">{mode === 'open' ? translate("Opening float (TSh)") : mode === 'paid' ? translate("Paid-out amount (TSh)") : translate("Counted cash (TSh)")}<input required autoFocus pattern="[0-9]+" value={amount} onChange={(e) => setAmount(e.target.value)} className={input} /></label>{mode === 'paid' && <label className="block text-xs text-slate-500">{translate("Paid-out reason")}<textarea required maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} className={input} /></label>}</fieldset><button disabled={busy} className={primary + ' mt-5'}>{busy ? translate("Saving…") : pending ? translate("Retry operation") : mode === 'close' ? translate("Review closing cash") : mode === 'open' ? translate("Confirm opening") : translate("Confirm paid-out")}</button></form> : <>
        <div className="mt-4 space-y-2 rounded-xl border border-slate-200 p-4 text-sm text-slate-600"><p>{translate("Expected:")} {money(review.expected_cash)}</p><p>{translate("Counted:")} {money(review.counted_cash)}</p><p className="font-semibold">{translate("Variance:")} {money(review.variance)}</p></div>
        {review.approval_required && !grant && <form className="mt-4" onSubmit={(e) => { e.preventDefault(); if (!busy) void approval(); }}><p className="text-sm text-amber-700">{translate("An owner or your branch manager must approve this variance.")}</p><fieldset disabled={busy || !!pending} className="mt-3 space-y-3"><label className="block text-xs text-slate-500">{translate("Supervisor email")}<input required autoComplete="off" type="email" value={phone} onChange={(e) => setPhone(e.target.value)} className={input} /></label><label className="block text-xs text-slate-500">{translate("Supervisor password")}<input required type="password" autoComplete="off" value={pin} onChange={(e) => setPin(e.target.value)} className={input} /></label></fieldset><button disabled={busy || !!pending} className="mt-3 text-sm font-medium text-emerald-600">{translate("Request variance approval")}</button></form>}
        {grant && <p className="mt-3 text-sm text-emerald-600">{translate("Supervisor approval received.")}</p>}
        <div className="mt-5 flex flex-wrap gap-3"><button disabled={busy || !!pending} onClick={() => { setReview(null); setGrant(null); }} className="text-sm text-slate-500">{translate("Edit count")}</button><button disabled={busy || (review.approval_required && !grant && !pending)} onClick={() => void commit()} className={primary}>{busy ? translate("Closing…") : pending ? translate("Retry close") : translate("Confirm close")}</button></div>
      </>}
      <button disabled={busy || !!pending} onClick={reset} className="mt-5 text-sm text-slate-500 disabled:opacity-40">{translate("Cancel")}</button>
    </div></Dialog>
  </div>;
}




