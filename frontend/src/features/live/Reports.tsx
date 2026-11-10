import { useState, type FormEvent } from 'react';
import { Activity, ArrowDownToLine, BarChart3, Banknote, CalendarDays, ChevronRight, ClipboardList, Layers, Package, Printer, ShieldAlert, TrendingUp, Wallet } from 'lucide-react';
import { SectionCard, StatCard, SummaryCard } from '../../components/ui/Primitives';
import { downloadReportCsv as download } from './reportCsv';
import { LossReportPanels } from './LossReportPanels';
import { errorMessage, type ServerUser } from './api';
import { BusinessReceiptPrint } from './ReceiptPrint';
import { useLazyReportReceiptQuery, useReportSummaryQuery, useReportSalesQuery, useReportStockQuery, useReportSalesAnalyticsQuery, type SalesAnalytics } from './financeApi';
import { Expenses } from './Expenses';
import { useLanguage, translate as t } from '../../i18n';

const reports = [
  { id: 'summary', label: 'Profit & cash flow', icon: BarChart3, group: 'Business performance' },
  { id: 'sales', label: 'Sales ledger', icon: ClipboardList, group: 'Business performance' },
  { id: 'stock', label: 'Stock valuation', icon: Package, group: 'Business performance' },
  { id: 'analytics', label: 'Sales analytics', icon: TrendingUp, group: 'Business performance' },
  { id: 'departments', label: 'Departments', icon: Layers, group: 'Business performance' },
  { id: 'tills', label: 'Till reports', icon: Banknote, group: 'Cash & controls' },
  { id: 'losses', label: 'Damage & wastage', icon: ShieldAlert, group: 'Cash & controls' },
  { id: 'alerts', label: 'Activity alerts', icon: Activity, group: 'Cash & controls' },
  { id: 'expenses', label: 'Expenses', icon: Wallet, group: 'Cash & controls' },
] as const;
type ReportId = typeof reports[number]['id'];
const money = (n: number) => n === undefined ? '—' : `TSh ${n.toLocaleString('en-TZ')}`;
const input = 'w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100';
const secondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-40';
function today() { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Dar_es_Salaam', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
const eastAfricaNow = () => new Date(Date.now() + 3 * 3600000);
const ymd = (date: Date) => date.toISOString().slice(0, 10);
function datePresets() {
  const now = eastAfricaNow();
  const day = (offset: number) => ymd(new Date(now.getTime() - offset * 86400000));
  return [
    { id: 'today', label: 'Today', from: day(0), to: day(0) },
    { id: 'yesterday', label: 'Yesterday', from: day(1), to: day(1) },
    { id: 'week', label: 'Last 7 days', from: day(6), to: day(0) },
    { id: 'month', label: 'This month', from: ymd(new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1))), to: day(0) },
  ] as const;
}

export function Reports({ actor, shopName = '' }: { actor: ServerUser; shopName?: string }) {
  useLanguage();
  const reportAccess = actor.role === 'owner' && actor.permissions.includes('view_reports');
  const [tab, setTab] = useState<ReportId>('summary');
  const [from, setFrom] = useState(today); const [to, setTo] = useState(today);
  const [filters, setFilters] = useState({ from: today(), to: today(), page: 1 });
  const summary = useReportSummaryQuery(filters, { skip: !reportAccess || tab !== 'summary', refetchOnMountOrArgChange: true });
  const sales = useReportSalesQuery(filters, { skip: !reportAccess || tab !== 'sales', refetchOnMountOrArgChange: true });
  const stock = useReportStockQuery(filters, { skip: !reportAccess || tab !== 'stock', refetchOnMountOrArgChange: true });
  const analytics = useReportSalesAnalyticsQuery(filters, { skip: !reportAccess || tab !== 'analytics', refetchOnMountOrArgChange: true });
  const active = tab === 'sales' ? sales : tab === 'stock' ? stock : summary;
  const directReport = ['summary', 'sales', 'stock'].includes(tab);
  const selected = reports.find(report => report.id === tab)!;
  const SelectedIcon = selected.icon;
  function runReport(event: FormEvent) {
    event.preventDefault();
    if (directReport && filters.from === from && filters.to === to && filters.page === 1 && !active.isUninitialized) void active.refetch();
    setFilters({ from, to, page: 1 });
  }
  function applyPreset(id: string) {
    const preset = datePresets().find(option => option.id === id);
    if (!preset) return;
    setFrom(preset.from); setTo(preset.to); setFilters({ from: preset.from, to: preset.to, page: 1 });
  }
  if (!reportAccess) return <div className="p-4 lg:p-6"><SectionCard title={t('Reports')}><div className="flex flex-col items-center p-10 text-center"><p className="max-w-lg text-sm text-slate-500">{t('Financial reports are available to the owner.')}</p></div></SectionCard></div>;
  return <div className="p-4 lg:p-6">
    <div className="mb-4 lg:hidden"><label className="block text-xs font-medium text-slate-500">{t('Choose report')}<select value={tab} onChange={event => setTab(event.target.value as ReportId)} className={input + ' mt-1 font-medium'}>{['Business performance', 'Cash & controls'].map(group => <optgroup key={group} label={t(group)}>{reports.filter(report => report.group === group).map(report => <option key={report.id} value={report.id}>{t(report.label)}</option>)}</optgroup>)}</select></label></div>
    <div className="grid items-start gap-5 lg:grid-cols-[208px_minmax(0,1fr)] xl:grid-cols-[224px_minmax(0,1fr)]">
      <nav aria-label={t('Report navigation')} className="hidden rounded-xl border border-slate-200 bg-white p-2 lg:block">
        {['Business performance', 'Cash & controls'].map((group, index) => <div key={group} className={index ? 'mt-3 border-t border-slate-100 pt-3' : ''}><p className="px-3 pb-2 pt-1 text-xs font-medium text-slate-400">{t(group)}</p>{reports.filter(report => report.group === group).map(report => { const Icon = report.icon; return <button key={report.id} aria-pressed={tab === report.id} onClick={() => setTab(report.id)} className={'flex w-full items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-sm font-medium transition-colors ' + (tab === report.id ? 'bg-emerald-50 text-emerald-700' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800')}><Icon className="h-4 w-4 shrink-0" /><span className="flex-1">{t(report.label)}</span>{tab === report.id && <ChevronRight className="h-3.5 w-3.5 shrink-0" />}</button>; })}</div>)}
      </nav>
      <div className="min-w-0 space-y-4">
        <div className="flex items-center gap-3"><div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700"><SelectedIcon className="h-5 w-5" /></div><div><h2 className="text-lg font-bold text-slate-800">{t(selected.label)}</h2>{!['alerts', 'expenses'].includes(tab) && <p className="mt-0.5 text-xs text-slate-500">{filters.from} — {filters.to}</p>}</div></div>
        {tab === 'expenses' ? <Expenses /> : <>
          {tab !== 'alerts' && <div className="rounded-xl border border-slate-200 bg-white p-4"><div className="mb-3 flex flex-wrap gap-2" role="group" aria-label={t('Quick date ranges')}>{datePresets().map(preset => <button key={preset.id} type="button" aria-pressed={filters.from === preset.from && filters.to === preset.to} onClick={() => applyPreset(preset.id)} className={'min-h-11 rounded-lg border px-3 py-2 text-xs font-semibold ' + (filters.from === preset.from && filters.to === preset.to ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600')}>{t(preset.label)}</button>)}</div><form onSubmit={runReport} className="grid grid-cols-2 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"><label className="text-xs font-medium text-slate-500">{t('From')}<input type="date" required value={from} onChange={event => setFrom(event.target.value)} className={input + ' mt-1 min-w-0'} /></label><label className="text-xs font-medium text-slate-500">{t('To')}<input type="date" required value={to} onChange={event => setTo(event.target.value)} className={input + ' mt-1 min-w-0'} /></label><button className="col-span-2 inline-flex items-center justify-center gap-2 rounded-lg bg-emerald-500 px-4 py-2.5 sm:col-span-1 text-sm font-semibold text-white hover:bg-emerald-600"><CalendarDays className="h-4 w-4" />{t('Run report')}</button></form><p className="mt-3 text-xs leading-relaxed text-slate-400">{t('Dates use Tanzania time. Sales, refunds and expenses keep their own event dates. Stock valuation shows the current recorded balances.')}</p></div>}
          {directReport && active.isFetching && <p role="status" className="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-500">{t('Loading report…')}</p>}
          {directReport && active.error && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{errorMessage(active.error)} <button onClick={() => void active.refetch()} className="font-medium underline">{t('Try again')}</button></div>}
          {['departments', 'tills', 'losses', 'alerts'].includes(tab) && <LossReportPanels key={tab + filters.from + filters.to} tab={tab} filters={filters} />}
          {tab === 'summary' && summary.currentData && !summary.error && <>
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">{[{ label: 'Net sales', key: 'net_revenue', Icon: BarChart3, color: 'bg-emerald-500' }, { label: 'Gross profit', key: 'gross_profit', Icon: TrendingUp, color: 'bg-blue-500' }, { label: 'Net profit', key: 'net_profit', Icon: Wallet, color: 'bg-violet-500' }, { label: 'Net cash flow', key: 'net_cashflow', Icon: Banknote, color: 'bg-amber-500' }].map(({ label, key, Icon, color }) => <StatCard key={key} title={t(label)} value={money(summary.currentData!.summary[key])} color={color} icon={<Icon className="h-5 w-5 text-white" />} />)}</div>
            <SectionCard title={t('Income, costs & cash')} action={<button className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700" onClick={() => download('bizismart-report.csv', [['Metric', 'Value', 'Unit'], ...Object.entries(summary.currentData!.summary).map(([key, value]) => [key, value, ['sale_count', 'legacy_cost_allocations'].includes(key) ? 'count' : 'TSh'])])}><ArrowDownToLine className="h-4 w-4" />{t('Export report (CSV)')}</button>}><dl className="grid gap-x-6 sm:grid-cols-2">{[['Cost of goods sold', 'net_cogs'], ['Expenses', 'net_expenses'], ['Stock loss cost', 'stock_loss_cost'], ['Commission accrual', 'commission_accrual'], ['Cash received', 'cashflow_in'], ['Cash paid', 'cashflow_out'], ['Output VAT', 'net_output_vat']].map(([label, key]) => <div key={key} className="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-100 py-3 text-sm"><dt className="text-slate-500">{t(label)}</dt><dd className="font-semibold tabular-nums text-slate-800">{money(summary.currentData!.summary[key])}</dd></div>)}</dl><p className="mt-4 text-xs text-slate-400">{t('VAT is a bookkeeping summary of saved receipts. This is not a tax filing or TRA integration.')}</p>{summary.currentData.summary.legacy_cost_allocations > 0 && <p className="mt-2 text-xs text-amber-700">{t('Older sale costs use their original recorded batch costs.')}</p>}</SectionCard>
            <SectionCard title={t('Current balances')}><div className="grid grid-cols-2 gap-3 xl:grid-cols-4"><SummaryCard title={t('Customer debt')} value={money(summary.currentData.outstanding_customer_debt)} /><SummaryCard title={t('Recorded stock value')} value={money(summary.currentData.stock.recorded_value)} /><SummaryCard title={t('Sellable stock value')} value={money(summary.currentData.stock.sellable_value)} /><SummaryCard title={t('Held stock value')} value={money(summary.currentData.stock.held_value)} /></div>{summary.currentData.stock.negative_batches > 0 && <p role="alert" className="mt-3 text-sm text-amber-700">{t('Negative stock conflicts are excluded from physical valuation.')}</p>}</SectionCard>
          </>}
          {tab === 'sales' && sales.currentData && !sales.error && <SectionCard title={t('Sales ledger')} action={<span className="text-xs text-slate-400">{t('Page')} {filters.page}</span>}><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr>{['Receipt', 'Branch', 'Cashier', 'Total', 'VAT', 'Receipt'].map(label => <th key={label} className="p-3 text-left">{t(label)}</th>)}</tr></thead><tbody>{sales.currentData.sales.map(sale => <tr key={sale.id} className="border-t"><td className="p-3">{sale.receipt_number}</td><td className="p-3">{sale.branch}</td><td className="p-3">{sale.cashier}</td><td className="p-3 font-semibold tabular-nums">{money(sale.total)}</td><td className="p-3 tabular-nums">{money(sale.vat_amount)}</td><td className="p-3"><ReprintReceipt saleId={sale.id} shopName={shopName}/></td></tr>)}</tbody></table>{!sales.currentData.sales.length && <p className="py-8 text-center text-sm text-slate-400">{t('No sales in this period.')}</p>}</div><button className={secondary + ' mt-4'} onClick={() => download('bizismart-sales-page.csv', [['Receipt', 'Branch', 'Cashier', 'Gross', 'VAT', 'Net'], ...sales.currentData!.sales.map(sale => [sale.receipt_number, sale.branch, sale.cashier, sale.total, sale.vat_amount, sale.net_revenue])])}><ArrowDownToLine className="h-4 w-4" />{t('Export this page (CSV)')}</button></SectionCard>}
          {tab === 'analytics' && <SalesAnalyticsPanel query={analytics}/>}
          {tab === 'stock' && stock.currentData && !stock.error && <SectionCard title={t('Stock valuation')} action={<span className="text-xs text-slate-400">{t('Page')} {filters.page}</span>}><div className="space-y-3">{stock.currentData.stock.map(batch => <div key={batch.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-100 p-3"><div className="min-w-0"><p className="break-words text-sm font-semibold text-slate-700">{batch.product_name}</p><p className="mt-1 text-xs text-slate-500">{batch.batch_number} · {batch.qty} {batch.unit} · {t(batch.status)}</p></div><span className="text-sm font-semibold tabular-nums text-slate-800">{money(batch.value)}</span></div>)}{!stock.currentData.stock.length && <p className="py-8 text-center text-sm text-slate-400">{t('No stock batches match this report.')}</p>}</div><button className={secondary + ' mt-4'} onClick={() => download('bizismart-stock-page.csv', [['Product', 'Batch', 'Quantity', 'Unit', 'Cost', 'Value', 'Status'], ...stock.currentData!.stock.map(batch => [batch.product_name, batch.batch_number, batch.qty, batch.unit, batch.cost_price, batch.value, batch.status])])}><ArrowDownToLine className="h-4 w-4" />{t('Export this page (CSV)')}</button></SectionCard>}
          {['sales', 'stock'].includes(tab) && <div className="flex items-center justify-between gap-3"><button disabled={filters.page === 1 || active.isFetching} className={secondary} onClick={() => setFilters({ ...filters, page: filters.page - 1 })}>{t('Previous')}</button><span className="text-xs text-slate-500">{t('Page')} {filters.page}</span><button disabled={active.isFetching || (tab === 'sales' ? !sales.currentData || filters.page * sales.currentData.meta.per_page >= sales.currentData.meta.total : !stock.currentData || filters.page * stock.currentData.meta.per_page >= stock.currentData.meta.total)} className={secondary} onClick={() => setFilters({ ...filters, page: filters.page + 1 })}>{t('Next')}</button></div>}
        </>}
      </div>
    </div>
  </div>;
}

/** Reprints a saved receipt from the sales ledger using the immutable receipt snapshot. */
function ReprintReceipt({ saleId, shopName }: { saleId: string; shopName: string }) {
  const [getSale, { currentData, isFetching, error }] = useLazyReportReceiptQuery();
  const [reprint, setReprint] = useState(false);
  const [failed, setFailed] = useState(false);
  const request = (id = saleId) => { setReprint(true); setFailed(false); void getSale(id).unwrap().catch(() => setFailed(true)); };
  if (!reprint) return <button type="button" onClick={() => request()} className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:underline"><Printer className="h-3.5 w-3.5" />{t('Reprint')}</button>;
  if (isFetching) return <span role="status" className="text-xs text-slate-400">{t('Loading')}…</span>;
  if (error || failed || !currentData) return <button type="button" onClick={() => request()} className="text-xs font-semibold text-red-600 underline">{t('Try again')}</button>;
  return <BusinessReceiptPrint sale={currentData} shopName={shopName} compact trigger={<><Printer className="h-3.5 w-3.5" />{t('Reprint')}</>} />;
}

function SalesAnalyticsPanel({ query }: { query: { currentData?: SalesAnalytics; isFetching: boolean; error?: unknown; refetch: () => unknown } }) {
  const data = query.currentData;
  if (query.isFetching && !data) return <p role="status" className="rounded-lg bg-slate-100 px-4 py-3 text-sm text-slate-500">{t('Loading report�')}</p>;
  if (query.error) return <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-600">{errorMessage(query.error)} <button onClick={() => void query.refetch()} className="font-medium underline">{t('Try again')}</button></div>;
  if (!data) return null;
  const peak = Math.max(1, ...data.hourly.map(bucket => Math.max(bucket.sales, bucket.refunds)));
  const exportCsv = () => download('bizismart-sales-analytics.csv', [
    ['Section', 'Name', 'Value', 'Detail'],
    ...data.payment_methods.map(method => [t('Payment method'), method.label, String(method.sales), `${t('Refunds')}: ${method.refunds}`]),
    ...data.hourly.map(bucket => [t('Hour'), bucket.label, String(bucket.sales), `${t('Refunds')}: ${bucket.refunds}`]),
    ...data.top_sellers.map(row => [t('Top seller'), row.name, String(row.revenue), `${row.qty} ${row.unit}`]),
    ...data.slow_movers.map(row => [t('Slow mover'), row.name, String(row.stock_value), `${row.stock_qty} ${row.unit}`]),
  ]);
  return <div className="space-y-4">
    <SectionCard title={t('Payment methods')} action={<button className="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700" onClick={exportCsv}><ArrowDownToLine className="h-4 w-4" />{t('Export analytics (CSV)')}</button>}>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">{data.payment_methods.map(method => <SummaryCard key={method.method} title={method.label} value={money(method.net)}/>)}</div>
      <div className="mt-4 space-y-2">{data.payment_methods.map(method => <div key={method.method} className="flex items-baseline justify-between gap-3 border-b border-slate-100 pb-2 text-sm"><span className="text-slate-600">{method.label}</span><span className="tabular-nums text-slate-800">{money(method.sales)}{method.refunds > 0 && <span className="ml-2 text-amber-700">{t('Refunds')} {money(method.refunds)}</span>}</span></div>)}</div>
      <p className="mt-3 text-xs text-slate-400">{t('Cash, mobile money and customer credit are counted from saved payment records, never from a typed reference.')}</p>
    </SectionCard>
    <SectionCard title={t('Sales by hour')}><div className="space-y-2">{data.hourly.map(bucket => <div key={bucket.hour} className="flex items-center gap-3"><span className="w-12 shrink-0 text-xs tabular-nums text-slate-500">{bucket.label}</span><div className="h-3 flex-1 rounded bg-slate-100"><div className="h-3 rounded bg-emerald-500" style={{ width: `${Math.round((bucket.sales / peak) * 100)}%` }}/></div><span className="w-28 shrink-0 text-right text-xs tabular-nums text-slate-700">{money(bucket.net)}</span></div>)}{!data.hourly.length && <p className="py-4 text-center text-sm text-slate-400">{t('No sales in this period.')}</p>}</div><p className="mt-3 text-xs text-slate-400">{t('Hours use Tanzania time and follow each saved sale event date.')}</p></SectionCard>
    <SectionCard title={t('Top sellers')}><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr>{['Product', 'Quantity', 'Sales', 'Cost', 'Profit'].map(label => <th key={label} className="p-2 text-left text-xs text-slate-400">{t(label)}</th>)}</tr></thead><tbody>{data.top_sellers.map(row => <tr key={row.product_id} className="border-t border-slate-100"><td className="p-2 text-slate-700">{row.name}</td><td className="p-2 tabular-nums text-slate-600">{row.qty} {row.unit}</td><td className="p-2 font-semibold tabular-nums text-slate-800">{money(row.revenue)}</td><td className="p-2 tabular-nums text-slate-500">{money(row.cost)}</td><td className="p-2 font-semibold tabular-nums text-emerald-700">{money(row.profit)}</td></tr>)}</tbody></table>{!data.top_sellers.length && <p className="py-4 text-center text-sm text-slate-400">{t('No sales in this period.')}</p>}</div><p className="mt-3 text-xs text-slate-400">{t('Refunded lines reduce the quantity, sales and cost of the same product.')}</p></SectionCard>
    <SectionCard title={t('Slow-moving stock')}><div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr>{['Product', 'In stock', 'Stock value', 'Nearest expiry'].map(label => <th key={label} className="p-2 text-left text-xs text-slate-400">{t(label)}</th>)}</tr></thead><tbody>{data.slow_movers.map(row => <tr key={row.product_id} className="border-t border-slate-100"><td className="p-2 text-slate-700">{row.name}</td><td className="p-2 tabular-nums text-slate-600">{row.stock_qty} {row.unit}</td><td className="p-2 font-semibold tabular-nums text-amber-700">{money(row.stock_value)}</td><td className="p-2 text-slate-500">{row.expiry_date ?? t('Not tracked')}</td></tr>)}</tbody></table>{!data.slow_movers.length && <p className="py-4 text-center text-sm text-slate-400">{t('Every product with stock recorded a sale in this period.')}</p>}</div></SectionCard>
  </div>;
}
