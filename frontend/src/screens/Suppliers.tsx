import { Badge,SectionCard,StatCard } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { formatKES } from '@/store/useStore';
import type { PurchaseOrderItem } from '@/types';
import { CheckCircle,Clock,DollarSign,FileText,Package,Plus,RotateCcw,Search,Send,Truck,X } from 'lucide-react';
import { useState } from 'react';

interface Props { store: StoreType; }

export function Suppliers({ store }: Props) {
  const [tab, setTab] = useState<'suppliers'|'orders'>('suppliers');
  const [selectedSupplier, setSelectedSupplier] = useState<string | null>(null);
  const [showCreatePO, setShowCreatePO] = useState(false);
  const [viewPO, setViewPO] = useState<string | null>(null);

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard title="Active Suppliers" value={String(store.suppliers.length)} icon={<Package className="h-5 w-5 text-white" />} color="bg-blue-500" />
        <StatCard title="Pending POs" value={String(store.purchaseOrders.filter(po => po.status === 'sent' || po.status === 'draft').length)} icon={<Clock className="h-5 w-5 text-white" />} color="bg-amber-500" />
        <StatCard title="Outstanding Payables" value={formatKES(store.suppliers.reduce((s,sup)=>s+sup.balance,0))} icon={<DollarSign className="h-5 w-5 text-white" />} color="bg-red-500" />
        <StatCard title="Received This Month" value={String(store.purchaseOrders.filter(po => po.status === 'received').length)} icon={<CheckCircle className="h-5 w-5 text-white" />} color="bg-emerald-500" />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-lg bg-slate-200 p-1">
          {([{ id:'suppliers', label:'Suppliers' },{ id:'orders', label:'Purchase Orders' }] as const).map(t => (
            <button key={t.id} onClick={() => setTab(t.id)} className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${tab === t.id ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{t.label}</button>
          ))}
        </div>
        {tab === 'orders' && <button onClick={() => setShowCreatePO(true)} className="flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"><Plus className="h-4 w-4" /> Create PO</button>}
      </div>

      {tab === 'suppliers' && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {store.suppliers.map(s => (
            <div key={s.id} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between">
                <div><h3 className="text-sm font-bold text-slate-800">{s.name}</h3><p className="mt-0.5 text-xs text-slate-400">{s.contact}</p></div>
              </div>
              <div className="mt-3 space-y-1 text-xs text-slate-500">
                <p>{s.phone} · {s.email}</p>
                <p>Last order: {s.lastOrder} · Products: {s.products}</p>
              </div>
              <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                <div><p className="text-xs text-slate-400">Outstanding Balance</p><p className={`text-base font-bold ${s.balance > 0 ? 'text-red-600' : 'text-emerald-600'}`}>{formatKES(s.balance)}</p></div>
                <button onClick={() => setSelectedSupplier(s.id)} className="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-200">Details</button>
              </div>
            </div>
          ))}
        </div>
      )}

      {tab === 'orders' && (
        <SectionCard title="Purchase Orders">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="pb-2 font-medium">PO #</th><th className="pb-2 font-medium">Supplier</th><th className="pb-2 font-medium">Items</th><th className="pb-2 text-right font-medium">Total</th><th className="pb-2 font-medium">Status</th><th className="pb-2 font-medium">Payment</th><th className="pb-2 font-medium">Created</th><th className="pb-2 text-center font-medium">Actions</th></tr></thead>
              <tbody className="divide-y divide-slate-50">
                {store.purchaseOrders.map(po => (
                  <tr key={po.id} className="hover:bg-slate-50">
                    <td className="py-3 font-mono text-xs font-medium text-slate-700">{po.id}</td>
                    <td className="py-3 font-medium text-slate-700">{po.supplierName}</td>
                    <td className="py-3 text-slate-600">{po.items.length} items</td>
                    <td className="py-3 text-right font-semibold text-slate-700">{formatKES(po.total)}</td>
                    <td className="py-3"><Badge status={po.status} variant={po.status==='received'?'success':po.status==='sent'?'info':po.status==='draft'?'neutral':'warning'} /></td>
                    <td className="py-3"><Badge status={po.paymentStatus} variant={po.paymentStatus==='paid'?'success':po.paymentStatus==='partial'?'warning':'danger'} /></td>
                    <td className="py-3 text-slate-500">{po.date}</td>
                    <td className="py-3"><div className="flex items-center justify-center gap-1">
                      <button onClick={() => setViewPO(po.id)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600" title="View"><FileText className="h-4 w-4" /></button>
                      {po.status === 'draft' && <button onClick={() => store.sendPO(po.id)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-blue-600" title="Send"><Send className="h-4 w-4" /></button>}
                      {po.status === 'sent' && <button onClick={() => store.receivePO(po.id, false)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-emerald-600" title="Receive"><Truck className="h-4 w-4" /></button>}
                      {po.status === 'partial' && <button onClick={() => store.receivePO(po.id, false)} className="rounded-md p-1.5 text-slate-400 hover:bg-slate-100 hover:text-emerald-600" title="Complete"><CheckCircle className="h-4 w-4" /></button>}
                    </div></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

      {/* Supplier Detail Modal */}
      {selectedSupplier && (() => {
        const s = store.suppliers.find(s => s.id === selectedSupplier)!;
        const supplierPOs = store.purchaseOrders.filter(po => po.supplierId === s.id);
        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setSelectedSupplier(null)}>
            <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
              <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{s.name}</h2><button onClick={() => setSelectedSupplier(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
              <div className="grid grid-cols-2 gap-4 mb-5">
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Contact</p><p className="text-sm font-medium text-slate-700">{s.contact}</p><p className="text-xs text-slate-500">{s.phone}</p></div>
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Email</p><p className="text-sm font-medium text-slate-700">{s.email}</p></div>
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Outstanding Balance</p><p className="text-lg font-bold text-red-600">{formatKES(s.balance)}</p></div>
                <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">Products Supplied</p><p className="text-lg font-bold text-slate-700">{s.products}</p></div>
              </div>
              <h3 className="mb-2 text-xs font-semibold text-slate-500">Purchase Orders</h3>
              <div className="space-y-2 mb-4">
                {supplierPOs.map(po => (
                  <div key={po.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-3">
                    <div><p className="text-sm font-mono font-medium text-slate-700">{po.id}</p><p className="text-xs text-slate-400">{po.date} · {po.items.length} items</p></div>
                    <div className="flex items-center gap-3"><span className="text-sm font-semibold text-slate-700">{formatKES(po.total)}</span><Badge status={po.status} variant={po.status==='received'?'success':po.status==='sent'?'info':'neutral'} /></div>
                  </div>
                ))}
                {supplierPOs.length === 0 && <p className="py-3 text-center text-sm text-slate-400">No purchase orders</p>}
              </div>
              {s.priceHistory.length > 0 && (
                <>
                  <h3 className="mb-2 text-xs font-semibold text-slate-500">Supplier Price History</h3>
                  <div className="space-y-2 mb-4">
                    {s.priceHistory.map((ph, i) => {
                      const p = store.products.find(pr => pr.id === ph.productId);
                      return (
                        <div key={i} className="flex items-center justify-between rounded-lg border border-slate-100 p-2.5">
                          <div><p className="text-sm font-medium text-slate-700">{p?.name ?? 'Unknown'}</p><p className="text-xs text-slate-400">{ph.date}</p></div>
                          <span className="text-sm font-semibold text-slate-700">{formatKES(ph.price)}</span>
                        </div>
                      );
                    })}
                  </div>
                </>
              )}
              <div className="mt-5 flex gap-3">
                <button onClick={() => { setSelectedSupplier(null); setShowCreatePO(true); }} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">Create PO</button>
                <button onClick={() => setSelectedSupplier(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Close</button>
              </div>
            </div>
          </div>
        );
      })()}

      {showCreatePO && <CreatePOModal store={store} onClose={() => setShowCreatePO(false)} />}

      {viewPO && (() => {
        const po = store.purchaseOrders.find(p => p.id === viewPO);
        if (!po) return null;
        const supplier = store.suppliers.find(s => s.id === po.supplierId);
        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setViewPO(null)}>
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
              <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">{po.id}</h2><button onClick={() => setViewPO(null)} className="text-slate-400"><X className="h-5 w-5" /></button></div>
              <div className="mb-4 grid grid-cols-2 gap-3 text-sm">
                <div><p className="text-xs text-slate-400">Supplier</p><p className="font-medium text-slate-700">{supplier?.name}</p></div>
                <div><p className="text-xs text-slate-400">Status</p><Badge status={po.status} variant={po.status==='received'?'success':po.status==='sent'?'info':'neutral'} /></div>
                <div><p className="text-xs text-slate-400">Created</p><p className="font-medium text-slate-700">{po.date}</p></div>
                <div><p className="text-xs text-slate-400">Expected</p><p className="font-medium text-slate-700">{po.expected}</p></div>
                <div><p className="text-xs text-slate-400">Payment Terms</p><p className="font-medium text-slate-700">{po.paymentTerms}</p></div>
                <div><p className="text-xs text-slate-400">Payment Status</p><Badge status={po.paymentStatus} variant={po.paymentStatus==='paid'?'success':po.paymentStatus==='partial'?'warning':'danger'} /></div>
              </div>
              <table className="w-full text-sm mb-4">
                <thead><tr className="border-b border-slate-100 text-left text-xs text-slate-400"><th className="pb-2 font-medium">Product</th><th className="pb-2 text-right font-medium">Qty</th><th className="pb-2 text-right font-medium">Unit Cost</th><th className="pb-2 text-right font-medium">Subtotal</th></tr></thead>
                <tbody className="divide-y divide-slate-50">
                  {po.items.map((it, i) => (
                    <tr key={i}><td className="py-2 font-medium text-slate-700">{it.name}</td><td className="py-2 text-right text-slate-600">{it.qty}</td><td className="py-2 text-right text-slate-600">{formatKES(it.cost)}</td><td className="py-2 text-right font-semibold text-slate-700">{formatKES(it.qty * it.cost)}</td></tr>
                  ))}
                </tbody>
                <tfoot><tr className="border-t border-slate-100"><td colSpan={3} className="pt-2 text-right text-sm font-bold text-slate-700">Total:</td><td className="pt-2 text-right text-lg font-bold text-emerald-600">{formatKES(po.total)}</td></tr></tfoot>
              </table>
              {/* Payment Scheduling */}
              <div className="border-t border-slate-100 pt-4">
                <h3 className="mb-2 text-xs font-semibold text-slate-500">Schedule Payment</h3>
                <div className="flex gap-2">
                  <input type="date" id="payDate" className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" />
                  <input type="number" id="payAmount" placeholder={String(po.total)} className="w-32 rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" />
                  <button onClick={() => { const d = (document.getElementById('payDate') as HTMLInputElement).value; const a = parseFloat((document.getElementById('payAmount') as HTMLInputElement).value) || po.total; if (d) store.scheduleSupplierPayment(po.id, a, d); }} className="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600">Schedule</button>
                </div>
              </div>
              {/* Supplier Return */}
              {po.status === 'received' && po.items.length > 0 && (
                <div className="mt-4 border-t border-slate-100 pt-4">
                  <h3 className="mb-2 text-xs font-semibold text-slate-500">Return to Supplier / Credit Note</h3>
                  <button onClick={() => { store.supplierReturn(po.supplierId, po.items[0].productId, 1, 'Damaged goods'); }} className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-600 hover:bg-amber-100"><RotateCcw className="h-4 w-4" /> Create Return</button>
                </div>
              )}
              <div className="mt-5 flex gap-3">
                {po.status === 'draft' && <button onClick={() => { store.sendPO(po.id); setViewPO(null); }} className="flex-1 rounded-xl bg-blue-500 py-2.5 text-sm font-bold text-white hover:bg-blue-600">Send to Supplier</button>}
                {po.status === 'sent' && <button onClick={() => { store.receivePO(po.id, false); setViewPO(null); }} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600">Mark Received</button>}
                <button onClick={() => setViewPO(null)} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Close</button>
              </div>
            </div>
          </div>
        );
      })()}
    </div>
  );
}

function CreatePOModal({ store, onClose }: { store: StoreType; onClose: () => void }) {
  const [supplierId, setSupplierId] = useState('');
  const [items, setItems] = useState<{ productId: string; qty: number }[]>([]);
  const [search, setSearch] = useState('');
  const [expectedDate, setExpectedDate] = useState('');
  const [paymentTerms, setPaymentTerms] = useState('30 Days');

  const filteredProducts = store.products.filter(p => p.name.toLowerCase().includes(search.toLowerCase()));
  const total = items.reduce((sum, it) => { const p = store.products.find(pr => pr.id === it.productId); return sum + (p?.cost ?? 0) * it.qty; }, 0);

  const handleCreate = () => {
    if (!supplierId || items.length === 0) return;
    const poItems: PurchaseOrderItem[] = items.map(it => {
      const p = store.products.find(pr => pr.id === it.productId)!;
      return { productId: it.productId, name: p.name, qty: it.qty, cost: p.cost, total: p.cost * it.qty };
    });
    store.createPO(supplierId, poItems, expectedDate || '2026-10-15', paymentTerms);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={onClose}>
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-slate-800">Create Purchase Order</h2><button onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div>
        <div className="mb-4 grid grid-cols-3 gap-4">
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Supplier</label><select value={supplierId} onChange={(e) => { setSupplierId(e.target.value); setItems([]); }} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"><option value="">Select...</option>{store.suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Expected Delivery</label><input type="date" value={expectedDate} onChange={(e) => setExpectedDate(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400" /></div>
          <div><label className="mb-1 block text-xs font-medium text-slate-500">Payment Terms</label><select value={paymentTerms} onChange={(e) => setPaymentTerms(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"><option>COD</option><option>7 Days</option><option>14 Days</option><option>30 Days</option></select></div>
        </div>
        <div className="mb-3 flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 text-slate-400" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search products..." className="flex-1 bg-transparent text-sm outline-none" /></div>
        <div className="max-h-48 space-y-1 overflow-y-auto mb-4">
          {filteredProducts.map(p => {
            const inPO = items.find(it => it.productId === p.id);
            return (
              <div key={p.id} className="flex items-center justify-between rounded-lg border border-slate-100 p-2.5">
                <div><p className="text-sm font-medium text-slate-700">{p.name}</p><p className="text-xs text-slate-400">Cost: {formatKES(p.cost)} · Stock: {p.stock}</p></div>
                <input type="number" min="0" value={inPO?.qty ?? ''} onChange={(e) => { const q = parseInt(e.target.value) || 0; if (q === 0) setItems(items.filter(i => i.productId !== p.id)); else setItems([...items.filter(i => i.productId !== p.id), { productId: p.id, qty: q }]); }} placeholder="Qty" className="w-16 rounded-md border border-slate-200 px-2 py-1 text-sm outline-none focus:border-emerald-400" />
              </div>
            );
          })}
        </div>
        <div className="border-t border-slate-100 pt-3">
          <div className="mb-3 flex items-center justify-between"><span className="text-sm font-medium text-slate-600">{items.length} items</span><span className="text-lg font-bold text-emerald-600">{formatKES(total)}</span></div>
          <div className="flex gap-3"><button onClick={onClose} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Cancel</button><button onClick={handleCreate} disabled={!supplierId || items.length === 0} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40">Create PO</button></div>
        </div>
      </div>
    </div>
  );
}
