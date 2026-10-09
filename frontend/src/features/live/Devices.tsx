import { useState } from 'react';
import { Shield } from 'lucide-react';
import { Badge, SectionCard } from '../../components/ui/Primitives';
import { errorMessage, useDevicesQuery, useDeviceStatusMutation, type Device } from './api';

export function Devices({ shopName }: { shopName: string }) {
  const { data, error, isLoading, refetch } = useDevicesQuery();
  const [action, setAction] = useState<{ device: Device; action: 'approve' | 'block' } | null>(null);
  const [failure, setFailure] = useState('');
  const [busy, setBusy] = useState(false);
  const [save] = useDeviceStatusMutation();
  async function confirm() {
    if (!action || busy) return; setBusy(true); setFailure('');
    const pending = save({ id: action.device.id, action: action.action });
    try { await pending.unwrap(); setAction(null); } catch (e) { setFailure(errorMessage(e)); }
    finally { pending.reset(); setBusy(false); }
  }
  return <div className="space-y-5 p-4 lg:p-6"><SectionCard title="Shop account"><p className="p-5 text-sm font-semibold text-slate-700">{shopName}</p></SectionCard><SectionCard title="Checkout devices">
    <div className="space-y-4 p-5">{isLoading && <p role="status">Loading devices…</p>}{error && <p role="alert" className="text-sm text-red-600">{errorMessage(error)} <button onClick={refetch}>Try again</button></p>}
      {data?.map((device) => <div key={device.id} aria-label={`Device ${device.name}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4"><div className="flex items-center gap-3"><Shield className="h-5 w-5 text-emerald-500" /><div><p className="text-sm font-semibold text-slate-700">{device.name}</p><p className="text-xs text-slate-400">{device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : 'Not yet used'}</p></div></div><div className="flex flex-wrap items-center gap-2"><Badge status={device.status} variant={device.status === 'approved' ? 'success' : device.status === 'pending' ? 'warning' : 'neutral'} />{device.status !== 'approved' && <button onClick={() => { setAction({ device, action: 'approve' }); setFailure(''); }} className="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700">Approve</button>}{device.status !== 'blocked' && <button onClick={() => { setAction({ device, action: 'block' }); setFailure(''); }} className="rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-600">Block</button>}</div></div>)}
    </div></SectionCard>
    {action && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div role="dialog" aria-modal="true" aria-label="Change device access" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><h2 className="text-lg font-bold text-slate-800">{action.action === 'approve' ? 'Approve' : 'Block'} {action.device.name}?</h2><p className="mt-3 text-sm text-slate-500">{action.action === 'block' ? 'All staff sessions on this device will be revoked.' : 'Shop staff will be able to sign in on this device.'}</p>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{failure}</p>}<div className="mt-6 flex gap-3"><button disabled={busy} onClick={() => setAction(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">Cancel</button><button disabled={busy} onClick={confirm} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? 'Saving…' : 'Confirm change'}</button></div></div></div>}
  </div>;
}
