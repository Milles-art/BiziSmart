import { translate, useLanguage } from '../../i18n';
import { Calculator } from 'lucide-react';
import { Badge } from '../../components/ui/Primitives';
import type { Till } from './api';

const money = (value: number) => `TSh ${value.toLocaleString('en-TZ')}`;
export function TillCards({ tills }: { tills: Till[] }) {
  useLanguage();
  return <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">{tills.map(till => <div key={till.id} aria-label={`Till for ${till.cashier_name}`} className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
    <div className="flex items-center justify-between gap-3"><div className="flex min-w-0 items-center gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-500"><Calculator className="h-5 w-5 text-white" /></div><div><h3 className="break-words text-sm font-bold text-slate-800">{till.branch_name}</h3><p className="break-words text-xs text-slate-400">{till.cashier_name}</p></div></div><Badge status="Open" variant="success" /></div>
    <div className="mt-4 grid grid-cols-2 gap-3 text-sm"><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Opening Float")}</p><p className="font-semibold text-slate-700">{money(till.opening_float)}</p></div><div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-400">{translate("Expected Cash")}</p><p className="font-semibold text-slate-700">{money(till.expected_cash)}</p></div></div>
    <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-400">{translate("Opened")} {new Date(till.opened_at).toLocaleString('en-TZ', { timeZone: 'Africa/Dar_es_Salaam' })}</p>
  </div>)}{!tills.length && <p className="py-5 text-sm text-slate-400">{translate("No open sessions.")}</p>}</div>;
}



