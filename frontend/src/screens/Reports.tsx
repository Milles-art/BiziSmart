import { SectionCard, StatCard } from "@/components/ui/Primitives";
import { businessDate, netTransaction, periodStart } from "@/lib/business";
import { salesCsv, salesPdf } from "@/lib/exports";
import { demoApi } from "@/store/redux";
import type { Store as StoreType } from "@/store/useStore";
import { formatKES } from "@/store/useStore";
import {
  BarChart3,
  Calculator,
  Calendar,
  DollarSign,
  Download,
  FileText,
  PieChart,
  TrendingUp,
} from "lucide-react";
import { useMemo, useState } from "react";

interface Props {
  store: StoreType;
}

export function Reports({ store }: Props) {
  const [period, setPeriod] = useState<"daily" | "weekly" | "monthly">("daily");
  const [exportNotice, setExportNotice] = useState("");

  const { data: queriedProducts } = demoApi.useProductsQuery();
  const reportProducts = queriedProducts?.length
    ? queriedProducts
    : store.products;
  const completedTxns = store.transactions
    .filter(
      (t) =>
        ["completed", "partial_refund", "refunded"].includes(t.status) &&
        t.date >= periodStart(period) &&
        t.date <= businessDate(),
    )
    .map(netTransaction);
  const totalRevenue = completedTxns.reduce((s, t) => s + t.total, 0);
  const totalCost = completedTxns.reduce((s, t) => s + t.cost, 0);
  const netRevenue = completedTxns.reduce((s, t) => s + t.subtotal, 0);
  const grossProfit = netRevenue - totalCost;
  const marginPct = netRevenue > 0 ? (grossProfit / netRevenue) * 100 : 0;
  const cashTotal = completedTxns.reduce(
    (s, t) => s + (t.cashAmount ?? (t.method === "cash" ? t.total : 0)),
    0,
  );
  const mpesaTotal = completedTxns.reduce(
    (s, t) => s + (t.mpesaAmount ?? (t.method === "mpesa" ? t.total : 0)),
    0,
  );
  const creditTotal = completedTxns.reduce(
    (s, t) => s + (t.method === "credit" ? t.total : 0),
    0,
  );
  const totalTax = completedTxns.reduce((s, t) => s + t.tax, 0);
  const avgBasket =
    completedTxns.length > 0 ? totalRevenue / completedTxns.length : 0;

  // Top products from transaction items
  const topProducts = useMemo(() => {
    const productMap = new Map<
      string,
      { name: string; qty: number; revenue: number; cost: number }
    >();
    completedTxns.forEach((tx) => {
      tx.items.forEach((item) => {
        const existing = productMap.get(item.productId);
        if (existing) {
          existing.qty += item.qty;
          existing.revenue += item.price * item.qty - item.discount;
        } else
          productMap.set(item.productId, {
            name: item.name,
            qty: item.qty,
            revenue: item.price * item.qty - item.discount,
            cost: 0,
          });
      });
    });
    return Array.from(productMap.values())
      .sort((a, b) => b.revenue - a.revenue)
      .slice(0, 8);
  }, [completedTxns]);

  // Department breakdown
  const deptBreakdown = useMemo(() => {
    const deptMap = new Map<string, { revenue: number; cost: number }>();
    completedTxns.forEach((tx) => {
      tx.items.forEach((item) => {
        const product = reportProducts.find((p) => p.id === item.productId);
        const dept = product?.department ?? "Other";
        const existing = deptMap.get(dept);
        const rev = item.price * item.qty - item.discount;
        const c = (item.unitCost ?? product?.cost ?? 0) * item.qty;
        if (existing) {
          existing.revenue += rev;
          existing.cost += c;
        } else deptMap.set(dept, { revenue: rev, cost: c });
      });
    });
    return Array.from(deptMap.entries())
      .map(([dept, data]) => ({
        dept,
        ...data,
        profit: data.revenue - data.cost,
      }))
      .sort((a, b) => b.revenue - a.revenue);
  }, [completedTxns, reportProducts]);

  // M-Pesa reconciliation
  const matchedMpesa = store.mpesaPayments.filter((m) => m.matched);
  const unmatchedMpesa = store.mpesaPayments.filter((m) => !m.matched);

  // Inventory valuation
  const inventoryCost = store.products.reduce(
    (s, p) => s + p.cost * p.stock,
    0,
  );

  // Customer debt aging

  const maxRevenue = topProducts.length > 0 ? topProducts[0].revenue : 1;

  const reportTypes = [
    {
      name: "Sales Summary",
      icon: DollarSign,
      desc: "Daily, weekly, monthly sales breakdown",
    },
    {
      name: "Payment Methods",
      icon: PieChart,
      desc: "Cash, M-Pesa, credit breakdown",
    },
    {
      name: "Gross Profit",
      icon: TrendingUp,
      desc: "Revenue minus cost of goods sold",
    },
    {
      name: "Department P&L",
      icon: FileText,
      desc: "Profit & loss by department",
    },
    {
      name: "Inventory Valuation",
      icon: BarChart3,
      desc: "Stock value at cost and retail",
    },
    { name: "Tax Report", icon: FileText, desc: "VAT collected and filed" },
    {
      name: "Expiry & Wastage",
      icon: Calendar,
      desc: "Expired and written-off items",
    },
    {
      name: "Customer Debt",
      icon: DollarSign,
      desc: "Aging debt and credit limits",
    },
    {
      name: "Cash Flow",
      icon: TrendingUp,
      desc: "Income, expenses, bank reconciliation",
    },
    {
      name: "Audit History",
      icon: FileText,
      desc: "All staff actions and overrides",
    },
  ];

  const handleExport = async (format: "csv" | "pdf") => {
    const filename = `sales-report-${period}-${businessDate()}`;
    if (format === "pdf")
      (await salesPdf(completedTxns, period)).save(filename + ".pdf");
    else {
      const url = URL.createObjectURL(
        new Blob([salesCsv(completedTxns)], { type: "text/csv;charset=utf-8" }),
      );
      const a = document.createElement("a");
      a.href = url;
      a.download = filename + ".csv";
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    setExportNotice(
      `Exported ${completedTxns.length} transactions as ${format.toUpperCase()}`,
    );
    setTimeout(() => setExportNotice(""), 4000);
  };

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-lg bg-slate-200 p-1">
          {(
            [
              { id: "daily", label: "Today" },
              { id: "weekly", label: "This Week" },
              { id: "monthly", label: "This Month" },
            ] as const
          ).map((p) => (
            <button
              key={p.id}
              onClick={() => setPeriod(p.id)}
              className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${period === p.id ? "bg-white text-slate-800 shadow-sm" : "text-slate-500 hover:text-slate-700"}`}
            >
              {p.label}
            </button>
          ))}
        </div>
        <div className="flex gap-2">
          <button
            onClick={() => handleExport("csv")}
            className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"
          >
            <Download className="h-4 w-4" /> Export CSV
          </button>
          <button
            onClick={() => handleExport("pdf")}
            className="flex items-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"
          >
            <FileText className="h-4 w-4" /> Export PDF
          </button>
        </div>
      </div>

      {exportNotice && (
        <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">
          {exportNotice}
        </div>
      )}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          title="Total Sales"
          value={formatKES(totalRevenue)}
          icon={<DollarSign className="h-5 w-5 text-white" />}
          color="bg-emerald-500"
        />
        <StatCard
          title="Transactions"
          value={String(completedTxns.length)}
          icon={<BarChart3 className="h-5 w-5 text-white" />}
          color="bg-blue-500"
        />
        <StatCard
          title="Avg Basket Size"
          value={formatKES(Math.round(avgBasket))}
          icon={<TrendingUp className="h-5 w-5 text-white" />}
          color="bg-amber-500"
        />
        <StatCard
          title="Gross Profit"
          value={formatKES(grossProfit)}
          icon={<Calculator className="h-5 w-5 text-white" />}
          color="bg-violet-500"
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <SectionCard title="Sales Breakdown" className="lg:col-span-2">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs text-slate-400">
                  <th className="pb-2 font-medium">Receipt</th>
                  <th className="pb-2 font-medium">Cashier</th>
                  <th className="pb-2 font-medium">Method</th>
                  <th className="pb-2 text-right font-medium">Subtotal</th>
                  <th className="pb-2 text-right font-medium">Tax</th>
                  <th className="pb-2 text-right font-medium">Total</th>
                  <th className="pb-2 text-right font-medium">Cost</th>
                  <th className="pb-2 text-right font-medium">Profit</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {completedTxns.slice(0, 10).map((tx) => (
                  <tr key={tx.id} className="hover:bg-slate-50">
                    <td className="py-2.5 font-mono text-xs text-slate-700">
                      {tx.receiptNo}
                    </td>
                    <td className="py-2.5 text-slate-600">{tx.cashier}</td>
                    <td className="py-2.5 capitalize text-slate-600">
                      {tx.method}
                    </td>
                    <td className="py-2.5 text-right text-slate-600">
                      {formatKES(tx.subtotal)}
                    </td>
                    <td className="py-2.5 text-right text-slate-600">
                      {formatKES(tx.tax)}
                    </td>
                    <td className="py-2.5 text-right font-semibold text-slate-700">
                      {formatKES(tx.total)}
                    </td>
                    <td className="py-2.5 text-right text-slate-500">
                      {formatKES(tx.cost)}
                    </td>
                    <td className="py-2.5 text-right font-semibold text-emerald-600">
                      {formatKES(tx.subtotal - tx.cost)}
                    </td>
                  </tr>
                ))}
                <tr className="border-t-2 border-slate-200 font-semibold">
                  <td className="py-2.5 text-slate-800" colSpan={3}>
                    Total ({completedTxns.length} txns)
                  </td>
                  <td className="py-2.5 text-right text-slate-700">
                    {formatKES(
                      completedTxns.reduce((s, t) => s + t.subtotal, 0),
                    )}
                  </td>
                  <td className="py-2.5 text-right text-slate-700">
                    {formatKES(totalTax)}
                  </td>
                  <td className="py-2.5 text-right text-emerald-600">
                    {formatKES(totalRevenue)}
                  </td>
                  <td className="py-2.5 text-right text-slate-500">
                    {formatKES(totalCost)}
                  </td>
                  <td className="py-2.5 text-right text-emerald-600">
                    {formatKES(grossProfit)}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </SectionCard>

        <SectionCard title="Payment Method Split">
          <div className="space-y-4">
            {[
              { label: "Cash", amount: cashTotal, color: "bg-emerald-500" },
              { label: "M-Pesa", amount: mpesaTotal, color: "bg-blue-500" },
              { label: "Credit", amount: creditTotal, color: "bg-amber-500" },
            ].map((m) => {
              const pct =
                totalRevenue > 0 ? (m.amount / totalRevenue) * 100 : 0;
              return (
                <div key={m.label}>
                  <div className="mb-1 flex justify-between text-sm">
                    <span className="text-slate-600">{m.label}</span>
                    <span className="font-semibold text-slate-700">
                      {formatKES(m.amount)}
                    </span>
                  </div>
                  <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div
                      className={`h-full rounded-full ${m.color} transition-all`}
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                  <p className="mt-0.5 text-xs text-slate-400">
                    {pct.toFixed(1)}% of total
                  </p>
                </div>
              );
            })}
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <SectionCard title="Top Products by Revenue">
          <div className="space-y-3">
            {topProducts.map((p, i) => (
              <div key={p.name} className="flex items-center gap-3">
                <span className="w-5 text-xs font-bold text-slate-400">
                  {i + 1}
                </span>
                <div className="flex-1">
                  <div className="mb-1 flex justify-between text-sm">
                    <span className="text-slate-700">{p.name}</span>
                    <span className="font-semibold text-slate-700">
                      {formatKES(p.revenue)}
                    </span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div
                      className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-emerald-400"
                      style={{ width: `${(p.revenue / maxRevenue) * 100}%` }}
                    />
                  </div>
                </div>
                <span className="w-12 text-right text-xs text-slate-400">
                  {p.qty} sold
                </span>
              </div>
            ))}
            {topProducts.length === 0 && (
              <p className="py-4 text-center text-sm text-slate-400">
                No sales data yet
              </p>
            )}
          </div>
        </SectionCard>

        <SectionCard title="Department Profit & Loss">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs text-slate-400">
                  <th className="pb-2 font-medium">Department</th>
                  <th className="pb-2 text-right font-medium">Revenue</th>
                  <th className="pb-2 text-right font-medium">Cost</th>
                  <th className="pb-2 text-right font-medium">Profit</th>
                  <th className="pb-2 text-right font-medium">Margin</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {deptBreakdown.map((d) => {
                  const m = d.revenue > 0 ? (d.profit / d.revenue) * 100 : 0;
                  return (
                    <tr key={d.dept} className="hover:bg-slate-50">
                      <td className="py-2.5 font-medium text-slate-700">
                        {d.dept}
                      </td>
                      <td className="py-2.5 text-right text-slate-600">
                        {formatKES(d.revenue)}
                      </td>
                      <td className="py-2.5 text-right text-slate-500">
                        {formatKES(d.cost)}
                      </td>
                      <td className="py-2.5 text-right font-semibold text-emerald-600">
                        {formatKES(d.profit)}
                      </td>
                      <td className="py-2.5 text-right text-slate-600">
                        {m.toFixed(1)}%
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <SectionCard title="Financial Reconciliation">
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-lg bg-emerald-50 p-3">
                <p className="text-xs text-slate-400">Total Revenue</p>
                <p className="text-lg font-bold text-emerald-600">
                  {formatKES(totalRevenue)}
                </p>
              </div>
              <div className="rounded-lg bg-red-50 p-3">
                <p className="text-xs text-slate-400">Total COGS</p>
                <p className="text-lg font-bold text-red-600">
                  {formatKES(totalCost)}
                </p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400">Gross Profit</p>
                <p className="text-lg font-bold text-slate-700">
                  {formatKES(grossProfit)}
                </p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400">Profit Margin</p>
                <p className="text-lg font-bold text-slate-700">
                  {marginPct.toFixed(1)}%
                </p>
              </div>
              <div className="rounded-lg bg-blue-50 p-3">
                <p className="text-xs text-slate-400">VAT Collected</p>
                <p className="text-lg font-bold text-blue-600">
                  {formatKES(totalTax)}
                </p>
              </div>
              <div className="rounded-lg bg-amber-50 p-3">
                <p className="text-xs text-slate-400">Inventory (Cost)</p>
                <p className="text-lg font-bold text-amber-600">
                  {formatKES(inventoryCost)}
                </p>
              </div>
            </div>
            <div className="rounded-lg border border-slate-100 p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-slate-600">M-Pesa Matched</span>
                <span className="font-semibold text-emerald-600">
                  {matchedMpesa.length} payments ·{" "}
                  {formatKES(matchedMpesa.reduce((s, m) => s + m.amount, 0))}
                </span>
              </div>
            </div>
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-amber-700">M-Pesa Unmatched</span>
                <span className="font-semibold text-amber-600">
                  {unmatchedMpesa.length} payments ·{" "}
                  {formatKES(unmatchedMpesa.reduce((s, m) => s + m.amount, 0))}
                </span>
              </div>
            </div>
            <div className="rounded-lg border border-slate-100 p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-slate-600">
                  Outstanding Customer Debt
                </span>
                <span className="font-semibold text-red-600">
                  {formatKES(store.customers.reduce((s, c) => s + c.debt, 0))}
                </span>
              </div>
            </div>
            <div className="rounded-lg border border-slate-100 p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm text-slate-600">
                  Outstanding Payables
                </span>
                <span className="font-semibold text-red-600">
                  {formatKES(
                    store.suppliers.reduce((s, sup) => s + sup.balance, 0),
                  )}
                </span>
              </div>
            </div>
          </div>
        </SectionCard>

        <SectionCard title="Available Reports">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {reportTypes.map((r) => {
              const Icon = r.icon;
              return (
                <button
                  key={r.name}
                  className="flex items-start gap-3 rounded-lg border border-slate-100 p-3 text-left hover:border-emerald-200 hover:bg-emerald-50"
                >
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                    <Icon className="h-4 w-4 text-slate-600" />
                  </div>
                  <div>
                    <p className="text-sm font-medium text-slate-700">
                      {r.name}
                    </p>
                    <p className="text-xs text-slate-400">{r.desc}</p>
                  </div>
                </button>
              );
            })}
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
