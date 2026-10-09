import { Badge,SectionCard,StatCard } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { formatKES } from '@/store/useStore';
import type { Role, Staff } from '@/types';
import { ROLE_PERMISSIONS } from '@/types';
import { CheckCircle,Clock,Lock,LogIn,LogOut,Plus,Shield,TrendingUp,UserCog,X,XCircle } from 'lucide-react';
import { useState } from 'react';

interface Props { store: StoreType; }

const roleColors: Record<Role, string> = { Owner: 'bg-violet-500', Manager: 'bg-blue-500', Supervisor: 'bg-amber-500', Cashier: 'bg-emerald-500' };

export function StaffScreen({ store }: Props) {
  const [tab, setTab] = useState<'staff'|'roles'|'audit'>('staff');
  const [showAdd, setShowAdd] = useState(false);
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState<Staff | null>(null);
  const [activation, setActivation] = useState<Staff | null>(null);
  const owner = store.currentUser?.role === 'Owner';

  const presentCount = store.staffList.filter(s => s.attendance === 'present').length;
  const totalSalesToday = store.staffList.reduce((s, st) => s + st.salesToday, 0);
  const twoFACount = store.staffList.filter(s => s.twoFactor).length;

  const showNotice = (msg: string) => { setNotice(msg); setTimeout(() => setNotice(''), 4000); };

  const roleDescriptions: Record<Role, string[]> = {
    Owner: ['Full access', 'All settings', '2FA required', 'Manage all tills'],
    Manager: ['Reports', 'Inventory', 'Approve refunds', 'Manage staff'],
    Supervisor: ['Approve voids', 'Till management', 'Stock adjustments'],
    Cashier: ['Process sales', 'Hold/resume sales', 'No refunds or voids'],
  };

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="Total Staff" value={String(store.staffList.length)} icon={<UserCog className="h-5 w-5 text-white" />} color="bg-blue-500" />
        <StatCard title="Present Today" value={String(presentCount)} icon={<CheckCircle className="h-5 w-5 text-white" />} color="bg-emerald-500" />
        <StatCard title="Sales Today" value={formatKES(totalSalesToday)} icon={<TrendingUp className="h-5 w-5 text-white" />} color="bg-amber-500" />
        <StatCard title="2FA Enabled" value={String(twoFACount)} icon={<Shield className="h-5 w-5 text-white" />} color="bg-violet-500" />
      </div>

      {notice && <div role="status" className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">{notice}</div>}

      <div className="flex items-center justify-between">
        <div className="flex gap-1 rounded-lg bg-slate-200 p-1">
          {([{ id:'staff', label:'Staff Members' },{ id:'roles', label:'Roles & Permissions' },{ id:'audit', label:'Audit Log' }] as const).map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{t.label}</button>
          ))}
        </div>
        {tab === 'staff' && owner && <button onClick={() => setShowAdd(true)} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" /> Add Staff</button>}
      </div>

      {tab === 'staff' && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {store.staffList.map(s => (
            <div key={s.id} aria-label={`Staff member ${s.name}`} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3"><div className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-300 text-sm font-bold text-slate-700">{s.name.split(' ').map(n => n[0]).join('').slice(0, 2)}</div><div><h3 className="text-sm font-bold text-slate-800">{s.name}</h3><p className="text-xs text-slate-400">{s.phone}</p></div></div>
                <div className="flex flex-col items-end gap-1"><Badge status={s.role} variant={s.role === 'Owner' ? 'info' : s.role === 'Manager' ? 'success' : s.role === 'Supervisor' ? 'warning' : 'neutral'} />{s.twoFactor && <Shield className="h-3.5 w-3.5 text-emerald-500" />}</div>
              </div>
              <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Sales Today</p><p className="font-semibold text-slate-700">{s.salesToday > 0 ? formatKES(s.salesToday) : '—'}</p></div>
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Attendance</p><div className="flex items-center gap-1">{s.attendance === 'present' ? <><CheckCircle className="h-3.5 w-3.5 text-emerald-500" /><span className="font-medium text-emerald-600">Present</span></> : s.attendance === 'absent' ? <><XCircle className="h-3.5 w-3.5 text-red-500" /><span className="font-medium text-red-600">Absent</span></> : <><Clock className="h-3.5 w-3.5 text-amber-500" /><span className="font-medium text-amber-600">On leave</span></>}</div></div>
              </div>
              {s.clockInTime && <div className="mt-2 text-xs text-slate-400">Clocked in: {s.clockInTime}</div>}
              <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                <div className="flex items-center gap-2">
                  {s.attendance === 'present' ? <button onClick={() => { store.clockOut(s.id); showNotice(`${s.name} clocked out`); }} className="flex items-center gap-1 rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-100"><LogOut className="h-3 w-3" /> Clock Out</button> : <button onClick={() => { store.clockIn(s.id); showNotice(`${s.name} clocked in`); }} className="flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-600 hover:bg-emerald-100"><LogIn className="h-3 w-3" /> Clock In</button>}
                  <button onClick={() => { store.toggleStaff2FA(s.id); showNotice(`2FA ${!s.twoFactor ? 'enabled' : 'disabled'} for ${s.name}`); }} className={`flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ${s.twoFactor ? 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'bg-slate-100 text-slate-500 hover:bg-slate-200'}`}><Shield className="h-3 w-3" /> 2FA</button>
                </div>
                <Badge status={s.active ? 'Active' : 'Inactive'} variant={s.active ? 'success' : 'neutral'} />
              </div>
              {owner && <div className="mt-3 flex gap-2">
                <button onClick={() => setEditing(s)} className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200">Edit staff</button>
                <button onClick={() => setActivation(s)} className={`rounded-lg px-3 py-2 text-xs font-medium ${s.active ? 'bg-red-50 text-red-600 hover:bg-red-100' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100'}`}>{s.active ? 'Deactivate' : 'Reactivate'}</button>
              </div>}
            </div>
          ))}
        </div>
      )}

      {tab === 'roles' && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {(Object.keys(ROLE_PERMISSIONS) as Role[]).map(role => (
            <div key={role} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex items-center gap-3"><div className={`flex h-10 w-10 items-center justify-center rounded-lg ${roleColors[role]}`}><Lock className="h-5 w-5 text-white" /></div><h3 className="text-sm font-bold text-slate-800">{role}</h3></div>
              <div className="mt-4 space-y-2">
                {roleDescriptions[role].map(perm => <div key={perm} className="flex items-center gap-2 text-sm text-slate-600"><CheckCircle className="h-4 w-4 text-emerald-500" />{perm}</div>)}
                <div className="mt-2 border-t border-slate-100 pt-2"><p className="mb-1 text-xs font-medium text-slate-400">Permissions ({ROLE_PERMISSIONS[role].length}):</p><div className="flex flex-wrap gap-1">{ROLE_PERMISSIONS[role].map(p => <span key={p} className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">{p}</span>)}</div></div>
              </div>
            </div>
          ))}
        </div>
      )}

      {tab === 'audit' && (
        <SectionCard title="Staff Action Audit Log">
          <div className="space-y-2">
            {store.auditLog.map(log => (
              <div key={log.id} className="flex items-center gap-3 rounded-lg border border-slate-100 p-3 hover:bg-slate-50">
                <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${log.type === 'void' ? 'bg-red-100' : log.type === 'approval' ? 'bg-emerald-100' : log.type === 'discount' ? 'bg-amber-100' : log.type === 'cash' ? 'bg-blue-100' : log.type === 'refund' ? 'bg-red-100' : log.type === 'price_change' ? 'bg-amber-100' : 'bg-slate-100'}`}><Shield className={`h-4 w-4 ${log.type === 'void' ? 'text-red-600' : log.type === 'approval' ? 'text-emerald-600' : log.type === 'discount' ? 'text-amber-600' : log.type === 'cash' ? 'text-blue-600' : 'text-slate-600'}`} /></div>
                <div className="flex-1"><p className="text-sm font-medium text-slate-700">{log.action}</p><p className="text-xs text-slate-400">By {log.user}</p></div>
                <span className="text-xs text-slate-400">{log.time}</span>
              </div>
            ))}
          </div>
        </SectionCard>
      )}

      {showAdd && <AddStaffModal store={store} onClose={() => setShowAdd(false)} onAdded={(name) => showNotice(`Staff member ${name} added`)} />}
      {editing && <AddStaffModal store={store} staff={editing} onClose={() => setEditing(null)} onAdded={(name) => showNotice(`Staff member ${name} updated`)} />}
      {activation && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div role="dialog" aria-modal="true" aria-label="Change staff access" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
          <h2 className="text-lg font-bold text-slate-800">{activation.active ? 'Deactivate' : 'Reactivate'} {activation.name}?</h2>
          <p className="mt-3 text-sm text-slate-500">{activation.active ? 'This person will no longer be able to sign in to this demo. Their sales history stays available.' : 'This person will be able to sign in to this demo again.'} This change is local to this browser.</p>
          <div className="mt-6 flex gap-3">
            <button onClick={() => setActivation(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button>
            <button onClick={() => { const result = store.setStaffActive(activation.id, !activation.active); showNotice(result.error ?? 'Demo staff access updated'); setActivation(null); }} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">Confirm change</button>
          </div>
        </div>
      </div>}
    </div>
  );
}

function AddStaffModal({ store, staff, onClose, onAdded }: { store: StoreType; staff?: Staff; onClose: () => void; onAdded: (name: string) => void }) {
  const [name, setName] = useState(staff?.name ?? '');
  const [phone, setPhone] = useState(staff?.phone ?? '');
  const [role, setRole] = useState<Role>(staff?.role ?? 'Cashier');
  const [pin, setPin] = useState('');
  const [error, setError] = useState('');

  const handleSave = () => {
    const result = store.saveStaffProfile({ name, role, phone, ...(staff ? {} : { pin }) }, staff?.id);
    if (!result.success) { setError(result.error ?? 'Could not save staff.'); return; }
    onAdded(name);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div role="dialog" aria-modal="true" aria-label={staff ? 'Edit Staff Member' : 'Add Staff Member'} className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{staff ? 'Edit Staff Member' : 'Add Staff Member'}</h2><button onClick={onClose} aria-label="Close staff form" className="text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button></div>
        {error && <p role="alert" className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">{error}</p>}
        <div className="space-y-4">
          <div><label htmlFor="staff-name" className="mb-1 block text-xs font-medium text-slate-500">Full Name</label><input id="staff-name" value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="Staff name" /></div>
          <div><label htmlFor="staff-phone" className="mb-1 block text-xs font-medium text-slate-500">Phone Number</label><input id="staff-phone" value={phone} onChange={(e) => setPhone(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="0712345678" /></div>
          <div><label htmlFor="staff-role" className="mb-1 block text-xs font-medium text-slate-500">Role</label><select id="staff-role" value={role} onChange={(e) => setRole(e.target.value as Role)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"><option>Cashier</option><option>Supervisor</option><option>Manager</option><option>Owner</option></select></div>
          {!staff && <div><label htmlFor="staff-pin" className="mb-1 block text-xs font-medium text-slate-500">4–6 digit demo PIN</label><input id="staff-pin" type="password" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="••••" /></div>}
        </div>
        <div className="mt-6 flex gap-3"><button onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button><button onClick={handleSave} disabled={!name || !phone || (!staff && !pin)} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">{staff ? 'Save Changes' : 'Add Staff'}</button></div>
      </div>
    </div>
  );
}
