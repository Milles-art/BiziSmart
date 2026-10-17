import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent, type ReactNode } from 'react';
import { Dialog } from '@mui/material';
import { Plus, X, Users, UserCheck, ShieldCheck, UserX, KeyRound, Search } from 'lucide-react';
import { StaffEmailAccess } from './StaffEmailAccess';
import { Badge, SummaryCard, ScreenTabs } from '../../components/ui/Primitives';
import { errorMessage, useUsersQuery, useCreateUserMutation, useUpdateUserMutation, useDeactivateUserMutation, useResetPinMutation, type ManagedUser, type ServerUser, type StaffRole } from './api';

const colors: Record<StaffRole, string> = { owner: 'bg-violet-500', manager: 'bg-blue-500', cashier: 'bg-emerald-500', stockkeeper: 'bg-amber-500' };
const roles: StaffRole[] = ['owner', 'manager', 'cashier', 'stockkeeper'];
const roleName = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);
const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';

export function Staff({ actor, navigation }: { actor: ServerUser; navigation?: ReactNode }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const { data, error, isLoading, refetch } = useUsersQuery(page);
  const [filter, setFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState<'all' | StaffRole>('all');
  const [pinFor, setPinFor] = useState<ManagedUser | null>(null);
  const shown = (data?.users ?? []).filter(user => (roleFilter === 'all' || user.role === roleFilter)
    && (!filter.trim() || `${user.name} ${user.email ?? ''} ${user.phone ?? ''}`.toLowerCase().includes(filter.trim().toLowerCase())));
  const [form, setForm] = useState<ManagedUser | 'new' | null>(null);
  const [action, setAction] = useState<{ user: ManagedUser; action: 'deactivate' | 'reactivate' } | null>(null);
  const [notice, setNotice] = useState('');
  const [failure, setFailure] = useState('');
  const [busy, setBusy] = useState(false);
  const [deactivate] = useDeactivateUserMutation();
  const [update] = useUpdateUserMutation();
  async function confirm(event: FormEvent) {
    event.preventDefault();
    if (!action || busy) return;
    setBusy(true); setFailure('');
    const pending = action.action === 'deactivate' ? deactivate(action.user.id) : update({ id: action.user.id, body: { is_active: true } });
    try { await pending.unwrap(); setNotice('Staff access updated.'); setAction(null); }
    catch (e) { setFailure(errorMessage(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <div className="space-y-5 p-4 lg:p-6">
    <header className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="text-xl font-bold text-slate-800">{translate('Staff')}</h1><p className="mt-1 text-sm text-slate-500">{translate('Manage your team and their access to the shop.')}</p></div><button onClick={() => { setForm('new'); setNotice(''); }} className="flex min-h-11 items-center gap-1.5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-600"><Plus className="h-4 w-4" />{translate("Add Staff")}</button></header>
    {navigation??<ScreenTabs value="staff" onChange={() => {}} tabs={[{id:"staff",label:"Staff Members"}]} />}
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <SummaryCard icon={<Users className="h-5 w-5 text-white"/>} title={translate("Total Staff")} value={data && !error ? String(data.meta.total) : "—"} /><SummaryCard icon={<UserCheck className="h-5 w-5 text-white"/>} color="bg-emerald-500" title={translate("Active on this page")} value={data && !error ? String(data.users.filter(u => u.is_active).length) : "—"} emphasis /><SummaryCard icon={<ShieldCheck className="h-5 w-5 text-white"/>} color="bg-violet-500" title={translate("Email sign-in ready")} value={data && !error ? String(data.users.filter(u => u.email_login_enabled).length) : "—"} /><SummaryCard icon={<UserX className="h-5 w-5 text-white"/>} color="bg-amber-500" title={translate("Inactive on this page")} value={data && !error ? String(data.users.filter(u => !u.is_active).length) : "—"} />
    </div>
    {notice && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{errorMessage(error)} <button onClick={refetch}>{translate("Try again")}</button></p>}
    {isLoading && <p role="status" className="text-sm text-slate-500">{translate("Loading staff…")}</p>}
    <div className="flex flex-wrap items-end gap-3">
      <label className="min-w-[220px] flex-1 text-xs font-medium text-slate-500">{translate("Filter staff on this page")}<span className="mt-1 flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2"><Search className="h-4 w-4 shrink-0 text-slate-400" /><input aria-label={translate("Filter staff on this page")} value={filter} onChange={(event) => setFilter(event.target.value)} placeholder={translate("Name, phone or email…")} className="min-w-0 w-full text-sm outline-none" /></span></label>
      <label className="text-xs font-medium text-slate-500">{translate("Role")}<select aria-label={translate("Filter by role")} value={roleFilter} onChange={(event) => setRoleFilter(event.target.value as 'all' | StaffRole)} className="mt-1 block w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700"><option value="all">{translate("All roles")}</option>{roles.map(role => <option key={role} value={role}>{roleName(role)}</option>)}</select></label>
    </div>
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      {shown.map((user) => <div key={user.id} aria-label={`Staff member ${user.name}`} className="flex flex-col rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
        <div className="flex items-start justify-between gap-2"><div className="flex min-w-0 items-center gap-3"><div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${colors[user.role]} text-sm font-bold text-white`}>{user.name.split(' ').map((s) => s[0]).join('').slice(0, 2)}</div><div className="min-w-0"><h3 className="break-words text-sm font-bold text-slate-800">{user.name}</h3><p className="text-xs text-slate-400">{user.email ?? user.phone}</p></div></div><Badge status={roleName(user.role)} variant={user.role === 'owner' ? "info" : "neutral"} /></div>
        <div className="mt-4 flex flex-wrap gap-2"><Badge status={user.is_active ? translate("Active") : translate("Inactive")} variant={user.is_active ? "success" : "neutral"} />{!user.email_login_enabled && <Badge status="Sign-in setup needed" variant="warning" />}</div>
        <details className="mt-4 rounded-lg border border-slate-100 bg-slate-50"><summary className="cursor-pointer rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-500">{translate('Assigned permissions')} <span className="text-slate-400">({user.permissions.length})</span></summary><ul className="grid gap-2 border-t border-slate-100 px-3 py-3 text-sm text-slate-600 sm:grid-cols-2">{user.permissions.map((p) => <li key={p} className="break-words">{translate(p.replace(/_/g, ' '))}</li>)}{!user.permissions.length&&<li>{translate('No permissions assigned.')}</li>}</ul></details>
        <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3"><button onClick={() => setForm(user)} className="rounded-lg bg-slate-100 px-3 py-2.5 text-sm font-medium text-slate-600">{translate("Edit staff")}</button>{user.is_active && <button onClick={() => { setPinFor(user); setFailure(''); }} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2.5 text-sm font-medium text-slate-600"><KeyRound className="h-4 w-4" />{translate("Reset PIN")}</button>}<button onClick={() => { setAction({ user, action: user.is_active ? 'deactivate' : 'reactivate' }); setFailure(''); }} className="rounded-lg bg-red-50 px-3 py-2.5 text-sm font-medium text-red-600">{user.is_active ? translate("Deactivate") : translate("Reactivate")}</button></div>
        {user.is_active && <StaffEmailAccess userId={user.id} enabled={!!user.email_login_enabled} />}
      </div>)}
    </div>
    {!isLoading&&!error&&data?.users.length===0&&<div className="rounded-xl border border-dashed border-slate-300 bg-white px-5 py-10 text-center"><Users className="mx-auto mb-3 h-8 w-8 text-slate-300"/><p className="font-semibold text-slate-700">{translate('No staff found.')}</p><p className="mt-1 text-sm text-slate-500">{translate('Add a staff member to give them access to the shop.')}</p></div>}
    {data && data.meta.total > 50 && <div className="flex gap-4 text-sm"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>{translate("Previous")}</button><span>{translate("Page")} {page}</span><button disabled={page * 50 >= data.meta.total} onClick={() => setPage(page + 1)}>{translate("Next")}</button></div>}
    {form && <StaffForm actor={actor} user={form === 'new' ? undefined : form} onClose={() => setForm(null)} onSaved={() => { setForm(null); setNotice('Staff saved.'); }} />}
    {pinFor && <ResetPinDialog user={pinFor} onClose={() => setPinFor(null)} onReset={() => { setPinFor(null); setNotice('PIN reset. Share the new PIN privately with the staff member.'); }} />}
    <Dialog open={!!action} onClose={() => { if (!busy) { setAction(null); } }} maxWidth="xs" fullWidth aria-labelledby="staff-access-dialog-label">{action && <form onSubmit={confirm} className="p-5 sm:p-6"><span id="staff-access-dialog-label" className="sr-only">{translate("Change staff access")}</span><h2 className="text-lg font-bold text-slate-800">{action.action === 'deactivate' ? translate("Deactivate") : translate("Reactivate")} {action.user.name}?</h2><p className="mt-3 text-sm text-slate-500">{translate("This changes their access to the shop. Their history is preserved.")}</p>

      {failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={() => { setAction(null); }} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : translate("Confirm change")}</button></div>
    </form>}</Dialog>
  </div>;
}

function ResetPinDialog({ user, onClose, onReset }: { user: ManagedUser; onClose: () => void; onReset: () => void }) {
  useLanguage();
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [reset] = useResetPinMutation();
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    if (!/^[0-9]{4,10}$/.test(pin)) { setError(translate("Enter a PIN of 4 to 10 digits.")); return; }
    if (pin !== confirm) { setError(translate("The two PINs do not match.")); return; }
    setBusy(true); setError('');
    const pending = reset({ user_id: user.id, new_pin: pin });
    try { await pending.unwrap(); onReset(); }
    catch (e) { setError(errorMessage(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} maxWidth="xs" fullWidth aria-labelledby="staff-pin-dialog-label"><form onSubmit={submit} className="p-5 sm:p-6"><span id="staff-pin-dialog-label" className="sr-only">{translate("Reset PIN")}</span>
    <h2 className="text-lg font-bold text-slate-800">{translate("Reset PIN for")} {user.name}</h2><p className="mt-2 text-sm text-slate-500">{translate("Their previous PIN stops working immediately. Share the new PIN privately.")}</p>
    {error && <p role="alert" className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>}
    <div className="mt-4 space-y-3"><label className="block text-xs text-slate-500">{translate("New PIN")}<input aria-label={translate("New PIN")} required inputMode="numeric" pattern="[0-9]*" maxLength={10} disabled={busy} value={pin} onChange={(e) => setPin(e.target.value.replace(/[^0-9]/g, ''))} className={inputClass}/></label><label className="block text-xs text-slate-500">{translate("Repeat new PIN")}<input aria-label={translate("Repeat new PIN")} required inputMode="numeric" pattern="[0-9]*" maxLength={10} disabled={busy} value={confirm} onChange={(e) => setConfirm(e.target.value.replace(/[^0-9]/g, ''))} className={inputClass}/></label></div>
    <div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white disabled:opacity-40">{busy ? translate("Saving…") : translate("Reset PIN")}</button></div>
  </form></Dialog>;
}

function StaffForm({ actor, user, onClose, onSaved }: { actor: ServerUser; user?: ManagedUser; onClose: () => void; onSaved: () => void }) {
  useLanguage();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [role, setRole] = useState<StaffRole>(user?.role ?? 'cashier');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [create] = useCreateUserMutation();
  const [update] = useUpdateUserMutation();
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return; setBusy(true); setError('');
    const pending = user ? update({ id: user.id, body: { name, phone, role } }) : create({ name, phone, role, branch_id: actor.branch_id });
    try { await pending.unwrap(); onSaved(); } catch (e) { setError(errorMessage(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} maxWidth="xs" fullWidth aria-labelledby="staff-form-dialog-label"><form onSubmit={submit} className="p-5 sm:p-6"><span id="staff-form-dialog-label" className="sr-only">{user ? translate("Edit Staff Member") : translate("Add Staff Member")}</span>
    <div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{user ? translate("Edit Staff Member") : translate("Add Staff Member")}</h2><button type="button" aria-label={translate("Close staff form")} onClick={onClose} disabled={busy} className="text-slate-400"><X className="h-5 w-5" /></button></div>
    {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(error)}</p>}
    <div className="space-y-4"><div><label htmlFor="staff-name" className="mb-1 block text-xs text-slate-500">{translate("Full Name")}</label><input id="staff-name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} disabled={busy} className={inputClass} /></div><div><label htmlFor="staff-phone" className="mb-1 block text-xs text-slate-500">{translate("Phone Number")}</label><input id="staff-phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required disabled={busy} className={inputClass} /></div><div><label htmlFor="staff-role" className="mb-1 block text-xs text-slate-500">{translate("Role")}</label><select id="staff-role" value={role} onChange={(e) => setRole(e.target.value as StaffRole)} disabled={busy} className={inputClass}>{roles.map((r) => <option key={r} value={r}>{roleName(r)}</option>)}</select></div></div>
    <div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : user ? translate("Save Changes") : translate("Add Staff")}</button></div>
  </form></Dialog>;
}
