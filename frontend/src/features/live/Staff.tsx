import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { Plus, X } from 'lucide-react';
import { Badge, SummaryCard, ScreenTabs } from '../../components/ui/Primitives';
import { errorMessage, useUsersQuery, useCreateUserMutation, useUpdateUserMutation, useDeactivateUserMutation, useResetPinMutation, type ManagedUser, type ServerUser, type StaffRole } from './api';

const colors: Record<StaffRole, string> = { owner: 'bg-violet-500', manager: 'bg-blue-500', cashier: 'bg-emerald-500', stockkeeper: 'bg-amber-500' };
const roles: StaffRole[] = ['owner', 'manager', 'cashier', 'stockkeeper'];
const roleName = (role: string) => role.charAt(0).toUpperCase() + role.slice(1);
const inputClass = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';

export function Staff({ actor }: { actor: ServerUser }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const { data, error, isLoading, refetch } = useUsersQuery(page);
  const [form, setForm] = useState<ManagedUser | 'new' | null>(null);
  const [action, setAction] = useState<{ user: ManagedUser; action: 'deactivate' | 'reactivate' | 'reset' } | null>(null);
  const [pin, setPin] = useState('');
  const [notice, setNotice] = useState('');
  const [failure, setFailure] = useState('');
  const [busy, setBusy] = useState(false);
  const [deactivate] = useDeactivateUserMutation();
  const [update] = useUpdateUserMutation();
  const [reset] = useResetPinMutation();
  async function confirm(event: FormEvent) {
    event.preventDefault();
    if (!action || busy) return;
    setBusy(true); setFailure('');
    const pending = action.action === 'reset' ? reset({ user_id: action.user.id, new_pin: pin })
      : action.action === 'deactivate' ? deactivate(action.user.id) : update({ id: action.user.id, body: { is_active: true } });
    try { await pending.unwrap(); setNotice('Staff access updated.'); setAction(null); }
    catch (e) { setFailure(errorMessage(e)); }
    finally { pending.reset(); setPin(''); setBusy(false); }
  }
  return <div className="space-y-5 p-4 lg:p-6">
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <SummaryCard title={translate("Total Staff")} value={data && !error ? String(data.meta.total) : "—"} /><SummaryCard title={translate("Active on this page")} value={data && !error ? String(data.users.filter(u => u.is_active).length) : "—"} emphasis /><SummaryCard title={translate("2FA on this page")} value={data && !error ? String(data.users.filter(u => u.two_factor_enabled).length) : "—"} /><SummaryCard title={translate("Attendance")} value="—" />
    </div>
    {notice && <p role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    {error && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{errorMessage(error)} <button onClick={refetch}>{translate("Try again")}</button></p>}
    <div className="flex flex-wrap items-center justify-between gap-3"><ScreenTabs value="staff" onChange={() => {}} tabs={[{id:"staff",label:"Staff Members"},{id:"roles",label:"Roles & Permissions",unavailable:"Assigned permissions are shown on each staff card; role administration is not connected."},{id:"audit",label:"Audit Log",unavailable:"Audit log browsing is not connected yet."}]} /><button onClick={() => { setForm('new'); setNotice(''); }} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" />{translate("Add Staff")}</button></div>
    {isLoading && <p role="status" className="text-sm text-slate-500">{translate("Loading staff…")}</p>}
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      {data?.users.map((user) => <div key={user.id} aria-label={`Staff member ${user.name}`} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex items-start justify-between gap-2"><div className="flex min-w-0 items-center gap-3"><div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${colors[user.role]} text-sm font-bold text-white`}>{user.name.split(' ').map((s) => s[0]).join('').slice(0, 2)}</div><div className="min-w-0"><h3 className="break-words text-sm font-bold text-slate-800">{user.name}</h3><p className="text-xs text-slate-400">{user.phone}</p></div></div><Badge status={roleName(user.role)} variant={user.role === 'owner' ? "info" : "neutral"} /></div>
        <div className="mt-4 flex flex-wrap gap-2"><Badge status={user.is_active ? translate("Active") : translate("Inactive")} variant={user.is_active ? "success" : "neutral"} />{user.two_factor_enabled && <Badge status="Authenticator enabled" variant="success" />}{user.must_change_pin && <Badge status="PIN change required" variant="warning" />}</div>
        <div className="mt-4 flex flex-wrap gap-1">{user.permissions.map((p) => <span key={p} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">{p.replace(/_/g, ' ')}</span>)}</div>
        <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-3"><button onClick={() => setForm(user)} className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600">{translate("Edit staff")}</button><button onClick={() => { setAction({ user, action: user.is_active ? 'deactivate' : 'reactivate' }); setFailure(''); }} className="rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-600">{user.is_active ? translate("Deactivate") : translate("Reactivate")}</button><button onClick={() => { setAction({ user, action: 'reset' }); setFailure(''); }} className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600">{translate("Reset PIN")}</button></div>
      </div>)}
    </div>
    {data && data.meta.total > 50 && <div className="flex gap-4 text-sm"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>{translate("Previous")}</button><span>{translate("Page")} {page}</span><button disabled={page * 50 >= data.meta.total} onClick={() => setPage(page + 1)}>{translate("Next")}</button></div>}
    {form && <StaffForm actor={actor} user={form === 'new' ? undefined : form} onClose={() => setForm(null)} onSaved={() => { setForm(null); setNotice('Staff saved.'); }} />}
    {action && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><form onSubmit={confirm} role="dialog" aria-modal="true" aria-label={translate("Change staff access")} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 className="text-lg font-bold text-slate-800">{action.action === 'reset' ? translate("Reset PIN for") : action.action === 'deactivate' ? translate("Deactivate") : translate("Reactivate")} {action.user.name}?</h2><p className="mt-3 text-sm text-slate-500">{action.action === 'reset' ? translate("Give them a temporary PIN. They must choose a new PIN when they next sign in.") : translate("This changes their access to the shop. Their history is preserved.")}</p>
      {action.action === 'reset' && <div className="mt-4"><label htmlFor="reset-pin" className="text-xs text-slate-500">{translate("Temporary PIN")}</label><input id="reset-pin" type="password" inputMode="numeric" pattern="[0-9]{4,6}" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value)} required className={inputClass} autoComplete="new-password" /></div>}
      {failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={() => { setAction(null); setPin(''); }} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : translate("Confirm change")}</button></div>
    </form></div>}
  </div>;
}

function StaffForm({ actor, user, onClose, onSaved }: { actor: ServerUser; user?: ManagedUser; onClose: () => void; onSaved: () => void }) {
  useLanguage();
  const [name, setName] = useState(user?.name ?? '');
  const [phone, setPhone] = useState(user?.phone ?? '');
  const [role, setRole] = useState<StaffRole>(user?.role ?? 'cashier');
  const [pin, setPin] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [create] = useCreateUserMutation();
  const [update] = useUpdateUserMutation();
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy) return; setBusy(true); setError('');
    const pending = user ? update({ id: user.id, body: { name, phone, role } }) : create({ name, phone, role, pin, branch_id: actor.branch_id });
    try { await pending.unwrap(); onSaved(); } catch (e) { setError(errorMessage(e)); }
    finally { pending.reset(); setPin(''); setBusy(false); }
  }
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><form onSubmit={submit} role="dialog" aria-modal="true" aria-label={user ? translate("Edit Staff Member") : translate("Add Staff Member")} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
    <div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{user ? translate("Edit Staff Member") : translate("Add Staff Member")}</h2><button type="button" aria-label={translate("Close staff form")} onClick={onClose} disabled={busy} className="text-slate-400"><X className="h-5 w-5" /></button></div>
    {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{translate(error)}</p>}
    <div className="space-y-4"><div><label htmlFor="staff-name" className="mb-1 block text-xs text-slate-500">{translate("Full Name")}</label><input id="staff-name" value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} disabled={busy} className={inputClass} /></div><div><label htmlFor="staff-phone" className="mb-1 block text-xs text-slate-500">{translate("Phone Number")}</label><input id="staff-phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} required disabled={busy} className={inputClass} /></div><div><label htmlFor="staff-role" className="mb-1 block text-xs text-slate-500">{translate("Role")}</label><select id="staff-role" value={role} onChange={(e) => setRole(e.target.value as StaffRole)} disabled={busy} className={inputClass}>{roles.map((r) => <option key={r} value={r}>{roleName(r)}</option>)}</select></div>{!user && <div><label htmlFor="staff-pin" className="mb-1 block text-xs text-slate-500">{translate("Temporary PIN")}</label><input id="staff-pin" type="password" inputMode="numeric" pattern="[0-9]{4,6}" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value)} required disabled={busy} className={inputClass} autoComplete="new-password" /></div>}</div>
    <div className="mt-6 flex gap-3"><button type="button" disabled={busy} onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : user ? translate("Save Changes") : translate("Add Staff")}</button></div>
  </form></div>;
}
