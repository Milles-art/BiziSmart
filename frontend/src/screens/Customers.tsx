import { SectionCard,StatCard } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { formatKES } from '@/store/useStore';
import { DollarSign,Mail,Phone,Plus,ShoppingBag,Star,TrendingUp,Users,Wallet,X } from 'lucide-react';
import { useState } from 'react';

interface Props { store: StoreType; }

export function Customers({ store }: Props) {
  const [selected, setSelected] = useState<string | null>(null);
  const [showAdd, setShowAdd] = useState(false);
  const [search, setSearch] = useState('');
  const [payAmount, setPayAmount] = useState('');
  const [notice, setNotice] = useState('');

  const totalDebt = store.customers.reduce((s, c) => s + c.debt, 0);
  const totalLoyalty = store.customers.reduce((s, c) => s + c.loyaltyPoints, 0);
  const filtered = store.customers.filter(c => c.name.toLowerCase().includes(search.toLowerCase()) || c.phone.includes(search));
  const selectedCustomer = store.customers.find(c => c.id === selected);
  const customerTxns = selectedCustomer ? store.transactions.filter(t => t.customerId === selectedCustomer.id) : [];

  const showNotice = (msg: string) => { setNotice(msg); setTimeout(() => setNotice(''), 4000); };

  const handlePayDebt = () => {
    if (!selectedCustomer || !payAmount) return;
    const amt = parseFloat(payAmount);
    if (amt <= 0) return;
    store.payDebt(selectedCustomer.id, amt);
    showNotice(`Debt payment of ${formatKES(amt)} recorded for ${selectedCustomer.name}`);
    setPayAmount('');
  };

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="Total Customers" value={String(store.customers.length)} icon={<Users className="h-5 w-5 text-white" />} color="bg-blue-500" />
        <StatCard title="Outstanding Debt" value={formatKES(totalDebt)} icon={<Wallet className="h-5 w-5 text-white" />} color="bg-red-500" />
        <StatCard title="Loyalty Points" value={totalLoyalty.toLocaleString()} icon={<Star className="h-5 w-5 text-white" />} color="bg-amber-500" />
        <StatCard title="Avg Spend / Customer" value={formatKES(Math.round(store.customers.reduce((s, c) => s + c.totalSpent, 0) / store.customers.length))} icon={<TrendingUp className="h-5 w-5 text-white" />} color="bg-emerald-500" />
      </div>

      {notice && <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">{notice}</div>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <div className="rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between border-b border-slate-100 p-4">
              <div className="flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search by name or phone..." className="w-40 bg-transparent text-sm outline-none placeholder:text-slate-400 sm:w-56" /></div>
              <button onClick={() => setShowAdd(true)} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" /> Add Customer</button>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="px-4 py-3 font-medium">Customer</th><th className="px-4 py-3 font-medium">Phone</th><th className="px-4 py-3 text-right font-medium">Total Spent</th><th className="px-4 py-3 text-right font-medium">Debt</th><th className="px-4 py-3 text-center font-medium">Loyalty</th></tr></thead>
                <tbody className="divide-y divide-slate-50">
                  {filtered.map(c => (
                    <tr key={c.id} onClick={() => setSelected(c.id)} className={`cursor-pointer hover:bg-slate-50 ${selected === c.id ? 'bg-emerald-50' : ''}`}>
                      <td className="px-4 py-3"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold text-slate-600">{c.name.split(' ').map(n => n[0]).join('').slice(0, 2)}</div><div><p className="font-medium text-slate-700">{c.name}</p><p className="text-xs text-slate-400">Since {c.joinedDate}</p></div></div></td>
                      <td className="px-4 py-3 text-slate-600">{c.phone}</td>
                      <td className="px-4 py-3 text-right font-semibold text-slate-700">{formatKES(c.totalSpent)}</td>
                      <td className="px-4 py-3 text-right">{c.debt > 0 ? <div><p className="font-semibold text-red-600">{formatKES(c.debt)}</p><p className="text-xs text-slate-400">Limit: {formatKES(c.creditLimit)}</p></div> : <span className="text-emerald-600">Clear</span>}</td>
                      <td className="px-4 py-3 text-center"><div className="flex items-center justify-center gap-1 text-amber-600"><Star className="h-3.5 w-3.5" /><span className="font-medium">{c.loyaltyPoints}</span></div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div>
          {selectedCustomer ? (
            <SectionCard title="Customer Profile">
              <div className="space-y-4">
                <div className="flex items-center gap-3"><div className="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-500 text-lg font-bold text-white">{selectedCustomer.name.split(' ').map(n => n[0]).join('').slice(0, 2)}</div><div><h3 className="font-bold text-slate-800">{selectedCustomer.name}</h3><p className="text-xs text-slate-400">Customer since {selectedCustomer.joinedDate}</p></div></div>
                <div className="space-y-2 text-sm">
                  <div className="flex items-center gap-2 text-slate-600"><Phone className="h-4 w-4 text-slate-400" /> {selectedCustomer.phone}</div>
                  {selectedCustomer.email && <div className="flex items-center gap-2 text-slate-600"><Mail className="h-4 w-4 text-slate-400" /> {selectedCustomer.email}</div>}
                </div>
                <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Total Spent</p><p className="font-bold text-slate-700">{formatKES(selectedCustomer.totalSpent)}</p></div>
                  <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Loyalty Points</p><p className="font-bold text-amber-600">{selectedCustomer.loyaltyPoints}</p></div>
                  <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Credit Limit</p><p className="font-bold text-slate-700">{formatKES(selectedCustomer.creditLimit)}</p></div>
                  <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Current Debt</p><p className={`font-bold ${selectedCustomer.debt > 0 ? 'text-red-600' : 'text-emerald-600'}`}>{selectedCustomer.debt > 0 ? formatKES(selectedCustomer.debt) : 'Clear'}</p></div>
                </div>
                {selectedCustomer.debt > 0 && (
                  <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <p className="mb-2 text-xs font-semibold text-amber-700">Debt Aging & Payment</p>
                    <p className="mb-3 text-xs text-amber-600">Outstanding: {formatKES(selectedCustomer.debt)} · Available credit: {formatKES(selectedCustomer.creditLimit - selectedCustomer.debt)}</p>
                    <div className="flex gap-2">
                      <div className="relative flex-1"><DollarSign className="absolute left-2 top-2.5 h-4 w-4 text-slate-400" /><input type="number" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} placeholder="Amount" className="w-full rounded-lg border border-slate-200 py-2 pl-8 pr-2 text-sm outline-none focus:border-emerald-400" /></div>
                      <button onClick={handlePayDebt} disabled={!payAmount} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">Pay Debt</button>
                    </div>
                  </div>
                )}
                <div>
                  <h4 className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-500"><ShoppingBag className="h-3.5 w-3.5" /> Recent Purchases</h4>
                  <div className="space-y-1.5">
                    {customerTxns.slice(0, 5).map(tx => (
                      <div key={tx.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-2"><div><p className="text-xs font-medium text-slate-600">{tx.date}</p><p className="text-[10px] text-slate-400">{tx.receiptNo} · {tx.method}</p></div><span className="text-sm font-semibold text-slate-700">{formatKES(tx.total)}</span></div>
                    ))}
                    {customerTxns.length === 0 && <p className="py-2 text-center text-xs text-slate-400">No purchases yet</p>}
                  </div>
                </div>
              </div>
            </SectionCard>
          ) : (
            <div className="flex h-full min-h-[300px] flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-white p-8 text-center"><Users className="mb-3 h-10 w-10 text-slate-300" /><p className="text-sm text-slate-400">Select a customer to view their profile</p></div>
          )}
        </div>
      </div>

      {showAdd && <AddCustomerModal store={store} onClose={() => setShowAdd(false)} onAdded={(name) => { showNotice(`Customer ${name} added`); }} />}
    </div>
  );
}

function AddCustomerModal({ store, onClose, onAdded }: { store: StoreType; onClose: () => void; onAdded: (name: string) => void }) {
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [creditLimit, setCreditLimit] = useState('5000');

  const handleSave = () => {
    if (!name || !phone) return;
    store.addCustomer({ name, phone, email: email || undefined, creditLimit: parseFloat(creditLimit) || 0 });
    onAdded(name);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-5 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Add Customer</h2><button onClick={onClose} className="text-slate-400 hover:text-slate-600"><X className="h-5 w-5" /></button></div>
        <div className="space-y-4">
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Full Name</label><input value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="Customer name" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Phone Number</label><input value={phone} onChange={(e) => setPhone(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="0712345678" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Email (optional)</label><input value={email} onChange={(e) => setEmail(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="email@example.com" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Credit Limit (KSh)</label><input type="number" value={creditLimit} onChange={(e) => setCreditLimit(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" placeholder="0" /></div>
        </div>
        <div className="mt-6 flex gap-3"><button onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button><button onClick={handleSave} disabled={!name || !phone} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">Save</button></div>
      </div>
    </div>
  );
}
