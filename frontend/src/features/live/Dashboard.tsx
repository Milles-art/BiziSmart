import { ResponsiveContainer, AreaChart, Area, CartesianGrid, XAxis, YAxis, Tooltip } from 'recharts';
import { useReportSummaryQuery, useReportSalesQuery } from './financeApi';
import { translate, useLanguage } from '../../i18n';
import { ArrowRight, Calculator, Clock, DollarSign, Package, ShoppingCart, TrendingUp } from 'lucide-react';
import { Badge, SectionCard, StatCard } from '../../components/ui/Primitives';
import { errorMessage, useExpiryAlertsQuery, useReorderAlertsQuery, useTillsQuery, type CatalogueFilters, type ServerUser } from './api';
import type { Screen } from '../../types';

const money = (amount: number) => `TSh ${amount.toLocaleString('en-TZ')}`;
type Navigate = (screen: Screen, filters?: CatalogueFilters) => void;
export function Dashboard({ shopName, actor, onNavigate }: { shopName: string; actor: ServerUser; onNavigate: Navigate }) {
  const {language}=useLanguage();
  const reportAccess=actor.role==='owner'&&actor.permissions.includes('view_reports');
  const date=(daysAgo=0)=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Dar_es_Salaam',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date(Date.now()-daysAgo*86400000));
  const day=date(),week=date(6);
  const summary=useReportSummaryQuery({from:day,to:day},{skip:!reportAccess,refetchOnMountOrArgChange:true});
  const weekly=useReportSummaryQuery({from:week,to:day},{skip:!reportAccess,refetchOnMountOrArgChange:true});
  const recent=useReportSalesQuery({from:week,to:day,per_page:5},{skip:!reportAccess,refetchOnMountOrArgChange:true});
  const figures=!summary.error?summary.currentData?.summary:undefined;
  const trend=!weekly.error?weekly.currentData?.daily:undefined;
  const stockAccess = actor.permissions.includes('receive_stock');
  const tillAccess = actor.permissions.includes('manage_till');
  const reorder = useReorderAlertsQuery(undefined, { skip: !stockAccess });
  const expiry = useExpiryAlertsQuery(undefined, { skip: !stockAccess });
  const tills = useTillsQuery({ page: 1, status: 'open' }, { skip: !tillAccess });
  const low = !reorder.error ? reorder.currentData : undefined;
  const near = !expiry.error ? expiry.currentData : undefined;
  const active = !tills.error ? tills.currentData : undefined;
  const productLink = (filters?: CatalogueFilters) => onNavigate('products', filters);
  const action = (label: string, go: () => void) => <button onClick={go} className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700">{translate(label)}<ArrowRight className="h-3 w-3" /></button>;
  return <div className="space-y-6 p-4 lg:p-6">
    <h2 className="text-lg font-bold text-slate-800">{shopName}</h2>
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <StatCard title={translate("Today's Sales")} value={figures?money(figures.net_sales_gross):'—'} icon={<DollarSign className="h-5 w-5 text-white" />} color="bg-emerald-500" />
      <StatCard title={translate("Gross Profit Today")} value={figures?money(figures.gross_profit):'—'} icon={<TrendingUp className="h-5 w-5 text-white" />} color="bg-blue-500" />
      <StatCard title={translate("Cash + M-Pesa")} value={figures?money(figures.paid_sales):'—'} icon={<ShoppingCart className="h-5 w-5 text-white" />} color="bg-violet-500" />
      <StatCard title={translate("Low Stock Items")} value={low ? String(low.meta.total) : '—'} icon={<Package className="h-5 w-5 text-white" />} color="bg-amber-500" />
    </div>
    {reportAccess&&summary.error&&<QueryError error={summary.error} retry={()=>void summary.refetch()}/>}<div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <SectionCard title={translate("Weekly Sales Trend")} className="min-w-0 lg:col-span-2" action={<span className="text-xs text-slate-400">{translate("Last 7 days")}</span>}>{!reportAccess?<p className="py-20 text-center text-sm text-slate-400">{translate('Financial reports are available to the owner.')}</p>:weekly.error?<QueryError error={weekly.error} retry={()=>void weekly.refetch()}/>:!trend?<p role="status" className="py-20 text-center text-sm text-slate-400">{translate('Loading report…')}</p>:<div className="h-[230px] w-full" role="img" aria-label={translate('Weekly net sales from saved sales and returns')}><ResponsiveContainer width="100%" height="100%"><AreaChart data={trend} margin={{top:10,right:12,bottom:4,left:0}}><defs><linearGradient id="live-sales-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#10b981" stopOpacity={0.22}/><stop offset="100%" stopColor="#10b981" stopOpacity={0.02}/></linearGradient></defs><CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e2e8f0"/><XAxis dataKey="date" tick={{fontSize:11}} axisLine={false} tickLine={false} tickFormatter={value=>new Intl.DateTimeFormat(language==='sw'?'sw-TZ':'en-TZ',{weekday:'short',timeZone:'Africa/Dar_es_Salaam'}).format(new Date(String(value)+'T12:00:00+03:00'))}/><YAxis tick={{fontSize:11}} axisLine={false} tickLine={false} width={65}/><Tooltip formatter={value=>[typeof value==='number'?money(value):String(value),translate('Net sales')]} labelFormatter={value=>String(value)}/><Area type="monotone" dataKey="net_sales_gross" stroke="#10b981" fill="url(#live-sales-fill)" strokeWidth={2} isAnimationActive={false}/></AreaChart></ResponsiveContainer></div>}</SectionCard>
      <SectionCard title={translate("Alerts & Notifications")}><div className="space-y-2">
        {stockAccess && low && low.meta.total > 0 && <Alert icon="stock" title={translate("Low Stock")} message={`${low.meta.total} ${translate('products below reorder level')}`} onClick={() => productLink({ page: 1, low_stock: 1 })} />}
        {stockAccess && near && near.meta.total > 0 && <Alert icon="expiry" title={translate("Near Expiry")} message={`${near.meta.total} ${translate('batches expiring within 7 days')}`} onClick={() => productLink({ page: 1, expiring_in: 7 })} />}
        {actor.role === 'owner' && active && active.meta.owner_review_count > 0 && <Alert icon="till" title={translate("Till Variance")} message={`${active.meta.owner_review_count} ${translate('closed tills need review')}`} onClick={() => onNavigate('tills')} />}
        {stockAccess && (reorder.error || expiry.error) && <p role="alert" className="text-sm text-red-600">{translate("Stock alerts could not be loaded. Retry the panels below.")}</p>}
        {tillAccess && tills.error && <p role="alert" className="text-sm text-red-600">{translate("Till alerts could not be loaded. Retry Till Status below.")}</p>}
        {(!stockAccess || (low && near && low.meta.total === 0 && near.meta.total === 0)) && (!tillAccess || (active && (actor.role !== 'owner' || active.meta.owner_review_count === 0))) && <p className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">{translate("No stock or till alerts for your access.")}</p>}
        {(stockAccess && (reorder.isFetching || expiry.isFetching) || tillAccess && tills.isFetching) && <p role="status" className="text-sm text-slate-400">{translate("Checking alerts…")}</p>}
      </div></SectionCard>
    </div>
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <SectionCard title={translate("Recent Transactions")} className="min-w-0 lg:col-span-2" action={reportAccess?action('View reports',()=>onNavigate('reports')):undefined}>
      {!reportAccess?<p className="py-6 text-center text-sm text-slate-400">{translate('Financial reports are available to the owner.')}</p>:recent.error?<QueryError error={recent.error} retry={()=>void recent.refetch()}/>:!recent.currentData?<p role="status" className="py-6 text-center text-sm text-slate-400">{translate('Loading report…')}</p>:recent.currentData.sales.length===0?<p className="py-6 text-center text-sm text-slate-400">{translate('No sales in this period.')}</p>:<div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr>{['Receipt','Cashier','Total'].map(label=><th key={label} className="px-3 py-2 text-left text-xs text-slate-400">{translate(label)}</th>)}</tr></thead><tbody>{recent.currentData.sales.map(sale=><tr key={sale.id} className="border-t border-slate-100"><td className="px-3 py-3 text-slate-600">{sale.receipt_number}</td><td className="px-3 py-3 text-slate-500">{sale.cashier}</td><td className="px-3 py-3 font-semibold text-emerald-600">{money(sale.total)}</td></tr>)}</tbody></table></div>}
    </SectionCard>
      <SectionCard title={translate("Till Status")} action={tillAccess ? action('Manage', () => onNavigate('tills')) : undefined}>
        {!tillAccess ? <p className="text-sm text-slate-400">{translate("Your role does not have till access.")}</p> : tills.error ? <QueryError error={tills.error} retry={() => void tills.refetch()} /> : !active ? <p role="status" className="text-sm text-slate-400">{translate("Loading tills…")}</p> : <div className="space-y-3">{active.tills.map(till => <button key={till.id} onClick={() => onNavigate('tills')} className="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-100 p-3 text-left hover:border-slate-200"><div className="min-w-0"><p className="break-words text-sm font-semibold text-slate-700">{till.branch_name}</p><p className="break-words text-xs text-slate-500">{till.cashier_name}</p></div><div className="shrink-0 text-right"><p className="text-sm font-bold text-slate-800">{till.expected_cash === null ? '—' : money(till.expected_cash)}</p><p className="text-xs text-slate-400">{translate("Expected cash")}</p></div></button>)}{active.meta.total === 0 && <p className="py-4 text-center text-sm text-slate-400">{translate("No open tills.")}</p>}{active.meta.total > active.tills.length && <p className="text-xs text-slate-400">{translate("Showing")} {active.tills.length} {translate("of")} {active.meta.total} {translate("open tills.")}</p>}</div>}
      </SectionCard>
    </div>
    <StockAlertPanels actor={actor} onNavigate={onNavigate} />
  </div>;
}
export function StockAlertPanels({ actor, onNavigate, inventory = false }: { actor: ServerUser; onNavigate: Navigate; inventory?: boolean }) {
  useLanguage();
  const stockAccess = actor.permissions.includes('receive_stock');
  const reorder = useReorderAlertsQuery(undefined, { skip: !stockAccess });
  const expiry = useExpiryAlertsQuery(undefined, { skip: !stockAccess });
  const low = !reorder.error ? reorder.currentData : undefined;
  const near = !expiry.error ? expiry.currentData : undefined;
  const productLink = (filters?: CatalogueFilters) => onNavigate('products', filters);
  const action = (label: string, go: () => void) => <button onClick={go} className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700">{translate(label)}<ArrowRight className="h-3 w-3" /></button>;
  return (    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
      <SectionCard title={inventory ? translate("Low Stock — Reorder Needed") : translate("Low Stock Alerts")} action={stockAccess ? action('Restock', () => productLink({ page: 1, low_stock: 1 })) : undefined}>
        {!stockAccess ? <p className="text-sm text-slate-400">{translate("Your role does not have stock alert access.")}</p> : reorder.error ? <QueryError error={reorder.error} retry={() => void reorder.refetch()} /> : !low ? <p role="status" className="text-sm text-slate-400">{translate("Loading stock alerts…")}</p> : <div className="space-y-2">{low.products.map(product => <button key={product.id} onClick={() => productLink({ page: 1, search: product.name })} className="flex w-full items-center justify-between gap-3 rounded-lg p-2.5 text-left hover:bg-slate-50"><div className="flex min-w-0 items-center gap-3"><div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-100"><Package className="h-4 w-4 text-amber-600" /></div><div><p className="break-words text-sm font-medium text-slate-700">{product.name}</p><p className="text-xs text-slate-400">{product.department_name ?? translate('Unassigned department')}</p></div></div><div className="shrink-0 text-right"><p className="text-sm font-semibold text-red-600">{product.stock_available && product.stock !== null ? `${product.stock} ${product.unit}` : '—'}</p><p className="text-xs text-slate-400">{translate("Min:")} {product.reorder_level} {product.unit}</p></div></button>)}{low.meta.total === 0 && <p className="py-4 text-center text-sm text-slate-400">{translate("All products well stocked.")}</p>}</div>}
      </SectionCard>
      <SectionCard title={inventory ? translate("Near-Expiry Batches") : translate("Near-Expiry Products")} action={stockAccess ? action('Review', () => productLink({ page: 1, expiring_in: 7 })) : undefined}>
        {!stockAccess ? <p className="text-sm text-slate-400">{translate("Your role does not have expiry alert access.")}</p> : expiry.error ? <QueryError error={expiry.error} retry={() => void expiry.refetch()} /> : !near ? <p role="status" className="text-sm text-slate-400">{translate("Loading expiry alerts…")}</p> : <div className="space-y-2">{near.batches.map(batch => <button key={batch.id} onClick={() => productLink({ page: 1, search: batch.product_name })} className="flex w-full flex-wrap items-center justify-between gap-3 rounded-lg p-2.5 text-left hover:bg-slate-50"><div className="flex min-w-0 items-center gap-3"><div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-100"><Clock className="h-4 w-4 text-red-600" /></div><div><p className="break-words text-sm font-medium text-slate-700">{batch.product_name}</p><p className="text-xs text-slate-400">{translate("Batch:")} {batch.batch_number} · {batch.qty_remaining} {batch.unit}</p></div></div><Badge status={`${translate('Expires')} ${batch.expiry_date}`} variant="warning" /></button>)}{near.meta.total === 0 && <p className="py-4 text-center text-sm text-slate-400">{translate("No batches expiring within 7 days.")}</p>}</div>}
      </SectionCard>
    </div>);
}

function QueryError({ error, retry }: { error: unknown; retry: () => void }) {
  useLanguage(); return <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{errorMessage(error)} <button onClick={retry} className="font-medium underline">{translate("Try again")}</button></div>; }
function Alert({ icon, title, message, onClick }: { icon: 'stock' | 'expiry' | 'till'; title: string; message: string; onClick: () => void }) {
  useLanguage();
  const Icon = icon === 'stock' ? Package : icon === 'expiry' ? Clock : Calculator;
  return <button onClick={onClick} className="flex w-full items-start gap-3 rounded-lg p-2 text-left hover:bg-slate-50"><div className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${icon === 'stock' ? 'bg-amber-100 text-amber-600' : icon === 'expiry' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'}`}><Icon className="h-4 w-4" /></div><div className="min-w-0 flex-1"><p className="text-sm font-medium text-slate-700">{title}</p><p className="text-xs text-slate-500">{message}</p><p className="mt-0.5 text-[10px] text-slate-400">{translate("Tap to review")}</p></div><ArrowRight className="mt-1 h-4 w-4 shrink-0 text-slate-300" /></button>;
}




