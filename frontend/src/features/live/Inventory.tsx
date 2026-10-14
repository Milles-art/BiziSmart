import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { AlertTriangle, Boxes, Printer, Search, TrendingDown, Upload } from 'lucide-react';
import { ScreenTabs, SectionCard, StatCard } from '../../components/ui/Primitives';
import { StockAlertPanels } from './Dashboard';
import { StockReceiving } from './StockReceiving';
import { useReportSummaryQuery } from './financeApi';
import { errorMessage, useProductsQuery, useReorderAlertsQuery, useDepartmentsQuery, type CatalogueFilters, type CatalogueProduct, type ServerUser } from './api';
import type { Screen } from '../../types';

export function Inventory({ actor, onNavigate }: { actor: ServerUser; onNavigate: (screen: Screen, filters?: CatalogueFilters) => void }) {
  useLanguage();
  const access = actor.permissions.includes('receive_stock');
  const [tab, setTab] = useState('overview');
  const [search, setSearch] = useState('');
  const [department, setDepartment] = useState('');
  const [departmentPage, setDepartmentPage] = useState(1);
  const [filters, setFilters] = useState<CatalogueFilters>({ page: 1 });
  const [selected, setSelected] = useState<CatalogueProduct | null>(null);
  const catalogue = useProductsQuery(filters, { skip: !access });
  const reorder = useReorderAlertsQuery(undefined, { skip: !access });
  const departments = useDepartmentsQuery({page:departmentPage}, {skip:!access||tab!=='stocktake'});
  const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Dar_es_Salaam',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  const canViewValuation=access&&actor.role==='owner'&&actor.permissions.includes('view_reports');
  const valuation=useReportSummaryQuery({from:today,to:today},{skip:!canViewValuation});
  const financial=canViewValuation&&!valuation.error?valuation.currentData:undefined;
  const data = !catalogue.error ? catalogue.currentData : undefined;
  function find(event: FormEvent) { event.preventDefault(); setFilters({ page: 1, ...(search ? { search } : {}), ...(tab==='stocktake'&&department?{department_id:department}:{}) }); }
  if (!access) return <div className="p-6"><p role="alert" className="text-sm text-slate-500">{translate("Your role does not have inventory access.")}</p></div>;
  return <div className="space-y-5 p-4 lg:p-6">
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4"><StatCard title={translate("Matching Products")} value={data ? String(data.meta.total) : '—'} icon={<Boxes className="h-5 w-5 text-white" />} color="bg-blue-500" /><StatCard title={translate("Inventory Value")} value={financial ? `TSh ${financial.stock.recorded_value.toLocaleString('en-TZ')}` : '—'} icon={<TrendingDown className="h-5 w-5 text-white" />} color="bg-emerald-500" /><StatCard title={translate("Low Stock Items")} value={!reorder.error && reorder.currentData ? String(reorder.currentData.meta.total) : '—'} icon={<AlertTriangle className="h-5 w-5 text-white" />} color="bg-amber-500" /><StatCard title={translate("Stock Loss Today")} value={financial && financial.summary.stock_loss_cost !== undefined ? `TSh ${financial.summary.stock_loss_cost.toLocaleString('en-TZ')}` : '—'} icon={<TrendingDown className="h-5 w-5 text-white" />} color="bg-red-500" /></div>
    <div className="flex flex-wrap items-center justify-between gap-3"><ScreenTabs value={tab} onChange={value => { setTab(value); setSelected(null); }} tabs={[{id:'overview',label:'Overview'},{id:'movements',label:'Stock Movements'},{id:'adjustments',label:'Adjustments',unavailable:actor.permissions.includes('adjust_stock') ? undefined : translate("Your role cannot correct stock.")},{id:'stocktake',label:'Stock Take',unavailable:actor.permissions.includes('adjust_stock') ? undefined : translate('Your role cannot correct stock.')},{id:'loss',label:'Damage & wastage',unavailable:actor.permissions.includes('adjust_stock') ? undefined : translate('Your role cannot correct stock.')}]} /><div className="flex gap-2"><button disabled title={translate("Stock imports are not connected yet")} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-400"><Upload className="h-4 w-4" />{translate("Import")}</button><button disabled title={translate("Shelf labels are not connected yet")} className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-400"><Printer className="h-4 w-4" />{translate("Labels")}</button></div></div>
    <p className="text-xs text-slate-500">{translate("Stock balances come from your batches. Department valuations and classified losses are available in Reports.")}</p>
    {tab === 'overview' ? <StockAlertPanels inventory actor={actor} onNavigate={onNavigate} /> : <SectionCard title={tab === 'movements' ? translate("Browse stock movements") : translate("Correct batch stock")}>
      {tab==='stocktake'&&<div className="mb-4 space-y-2"><label className="block text-sm">{translate('Department')}<select value={department} onChange={e=>{setDepartment(e.target.value);setFilters({page:1,...(e.target.value?{department_id:e.target.value}:{})});}} className="ml-2 rounded-lg border border-slate-200 px-3 py-2"><option value="">{translate('Choose a department')}</option>{department&&!departments.currentData?.departments.some(d=>d.id===department)&&<option value={department}>{translate('Selected department')}</option>}{departments.currentData?.departments.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>{departments.error&&<p role="alert" className="text-red-600">{errorMessage(departments.error)}</p>}{departments.currentData&&departments.currentData.meta.total>departments.currentData.meta.per_page&&<div className="flex gap-3 text-sm"><button disabled={departmentPage===1} onClick={()=>setDepartmentPage(departmentPage-1)}>{translate('Previous departments')}</button><button disabled={departmentPage*departments.currentData.meta.per_page>=departments.currentData.meta.total} onClick={()=>setDepartmentPage(departmentPage+1)}>{translate('Next departments')}</button></div>}<p className="text-xs text-slate-500">{translate('Select a department, search or scan a product barcode, then count each physical batch. The server flags variance against the recorded batch balance.')}</p></div>}
      <p className="mb-4 text-sm text-slate-500">{tab === 'movements' ? translate("Choose a product to view its movement history.") : translate("Choose a product, then select a batch to review a correction. Stockkeeper corrections require owner approval.")}</p>
      <form onSubmit={find} className="mb-4 flex flex-wrap gap-3"><div className="flex min-w-0 flex-1 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2"><Search className="h-4 w-4 shrink-0 text-slate-400" /><input aria-label={translate("Inventory product search")} maxLength={160} value={search} onChange={e => setSearch(e.target.value)} placeholder={translate("Search products…")} className="min-w-0 w-full bg-transparent text-sm outline-none" /></div><button className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600">{translate("Search")}</button></form>
      {catalogue.error ? <p role="alert" className="text-sm text-red-600">{errorMessage(catalogue.error)} <button onClick={() => void catalogue.refetch()} className="underline">{translate("Try again")}</button></p> : catalogue.isFetching ? <p role="status" className="text-sm text-slate-400">{translate("Loading products…")}</p> : <div className="space-y-2">{data?.products.map(product => <div key={product.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-100 p-3"><div className="min-w-0"><p className="break-words text-sm font-medium text-slate-700">{product.name}</p><p className="text-xs text-slate-400">{translate("Available:")} {product.stock_available && product.stock !== null ? `${product.stock} ${product.unit}` : '—'}</p></div><button aria-label={`${tab === 'movements' ? translate("View movements") : translate("Review batches")} for ${product.name}`} disabled={tab === "stocktake" && (!department || product.department_id !== department)} onClick={() => setSelected(product)} className="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700">{tab === 'movements' ? translate("View movements") : translate("Review batches")}</button></div>)}{data?.products.length === 0 && <p className="py-6 text-center text-sm text-slate-400">{translate("No products match this search.")}</p>}</div>}
      {data && <div className="mt-4 flex items-center justify-between text-sm text-slate-500"><button disabled={filters.page === 1 || catalogue.isFetching} onClick={() => setFilters({...filters,page:filters.page-1})}>{translate("Previous")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={catalogue.isFetching || filters.page * data.meta.per_page >= data.meta.total} onClick={() => setFilters({...filters,page:filters.page+1})}>{translate("Next")}</button></div>}
    </SectionCard>}
    {selected && <StockReceiving product={selected} showCost={actor.permissions.includes('view_buying_price')} canAdjust={actor.permissions.includes('adjust_stock')} actionMode={tab === "stocktake" ? "count" : tab === "loss" ? "loss" : "adjustment"} initialView={tab === 'movements' ? "history" : "batches"} onClose={() => setSelected(null)} />}
  </div>;
}







