import { Badge, SectionCard, StatCard } from "@/components/ui/Primitives";
import type { Store as StoreType } from "@/store/useStore";
import { formatKES } from "@/store/useStore";
import {
  AlertTriangle,
  ArrowRight,
  Clock,
  DollarSign,
  Package,
  ShoppingCart,
  TrendingUp,
} from "lucide-react";
import {
  Bar,
  BarChart,
  LabelList,
  ResponsiveContainer,
  Tooltip,
  XAxis,
} from "recharts";

interface Props {
  store: StoreType;
}

export function Dashboard({ store }: Props) {
  const {
    todaySales,
    lowStockProducts,
    expiringProducts,
    transactions,
    tills,
    pendingApprovals,
    weeklySalesData,
  } = store;
  const openTills = tills.filter((t) => t.status === "open");
  const notifications = [
    ...(lowStockProducts.length > 0
      ? [
          {
            id: "n1",
            type: "warning",
            title: "Low Stock",
            message: `${lowStockProducts.length} products below reorder level`,
            time: "5 min ago",
          },
        ]
      : []),
    ...(expiringProducts.length > 0
      ? [
          {
            id: "n2",
            type: "danger",
            title: "Near Expiry",
            message: `${expiringProducts.length} products expiring within 7 days`,
            time: "12 min ago",
          },
        ]
      : []),
    ...(pendingApprovals.length > 0
      ? [
          {
            id: "n3",
            type: "info",
            title: "Pending Approvals",
            message: `${pendingApprovals.length} requests awaiting supervisor`,
            time: "now",
          },
        ]
      : []),
    {
      id: "n4",
      type: "info",
      title: "Till 3 Variance",
      message: "Cash variance of KSh 150 detected",
      time: "25 min ago",
    },
  ];

  return (
    <div className="space-y-6 p-4 lg:p-6">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          title="Today's Sales"
          value={formatKES(todaySales.total)}
          change={`${todaySales.count} txns`}
          trend="up"
          icon={<DollarSign className="h-5 w-5 text-white" />}
          color="bg-emerald-500"
        />
        <StatCard
          title="Gross Profit Today"
          value={formatKES(todaySales.grossProfit)}
          change={`${((todaySales.grossProfit / todaySales.total) * 100 || 0).toFixed(1)}% margin`}
          trend="up"
          icon={<TrendingUp className="h-5 w-5 text-white" />}
          color="bg-blue-500"
        />
        <StatCard
          title="Cash + M-Pesa"
          value={formatKES(todaySales.cashTotal + todaySales.mpesaTotal)}
          icon={<ShoppingCart className="h-5 w-5 text-white" />}
          color="bg-violet-500"
        />
        <StatCard
          title="Low Stock Items"
          value={String(lowStockProducts.length)}
          change="Needs attention"
          trend="down"
          icon={<Package className="h-5 w-5 text-white" />}
          color="bg-amber-500"
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <SectionCard
          title="Weekly Sales Trend"
          className="lg:col-span-2"
          action={<span className="text-xs text-slate-400">Last 7 days</span>}
        >
          <div style={{ height: 220 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={weeklySalesData}
                margin={{ top: 20, right: 0, left: 0, bottom: 0 }}
                barCategoryGap="18%"
              >
                <defs>
                  <linearGradient id="salesGreen" x1="0" y1="1" x2="0" y2="0">
                    <stop offset="0%" stopColor="#10b981" />
                    <stop offset="100%" stopColor="#34d399" />
                  </linearGradient>
                </defs>
                <XAxis
                  dataKey="label"
                  axisLine={false}
                  tickLine={false}
                  tick={{ fill: "#94a3b8", fontSize: 10 }}
                />
                <Tooltip
                  formatter={(value) => formatKES(Number(value))}
                  cursor={{ fill: "#f1f5f9" }}
                />
                <Bar
                  dataKey="totalSales"
                  name="Sales"
                  fill="url(#salesGreen)"
                  radius={[6, 6, 0, 0]}
                >
                  <LabelList
                    dataKey="totalSales"
                    position="top"
                    formatter={(value) =>
                      Number(value) > 0
                        ? `${(Number(value) / 1000).toFixed(0)}K`
                        : "—"
                    }
                    fill="#64748b"
                    fontSize={10}
                  />
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        </SectionCard>

        <SectionCard title="Alerts & Notifications">
          <div className="space-y-3">
            {notifications.map((n) => (
              <div
                key={n.id}
                className="flex items-start gap-3 rounded-lg p-2 hover:bg-slate-50"
              >
                <div
                  className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${n.type === "danger" ? "bg-red-100" : n.type === "warning" ? "bg-amber-100" : "bg-blue-100"}`}
                >
                  {n.type === "danger" ? (
                    <AlertTriangle className="h-4 w-4 text-red-600" />
                  ) : n.type === "warning" ? (
                    <Package className="h-4 w-4 text-amber-600" />
                  ) : (
                    <Clock className="h-4 w-4 text-blue-600" />
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-slate-700">
                    {n.title}
                  </p>
                  <p className="text-xs text-slate-500">{n.message}</p>
                  <p className="mt-0.5 text-[10px] text-slate-400">{n.time}</p>
                </div>
              </div>
            ))}
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <SectionCard
          title="Recent Transactions"
          className="lg:col-span-2"
          action={
            <button
              onClick={() => store.setScreen("tills")}
              className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700"
            >
              View all <ArrowRight className="h-3 w-3" />
            </button>
          }
        >
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs text-slate-400">
                  <th className="pb-2 font-medium">Receipt</th>
                  <th className="pb-2 font-medium">Time</th>
                  <th className="pb-2 font-medium">Cashier</th>
                  <th className="pb-2 font-medium">Method</th>
                  <th className="pb-2 text-right font-medium">Total</th>
                  <th className="pb-2 text-center font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {transactions.slice(0, 7).map((tx) => (
                  <tr key={tx.id} className="hover:bg-slate-50">
                    <td className="py-2.5 font-medium text-slate-700">
                      {tx.receiptNo}
                    </td>
                    <td className="py-2.5 text-slate-500">{tx.time}</td>
                    <td className="py-2.5 text-slate-600">{tx.cashier}</td>
                    <td className="py-2.5 capitalize text-slate-600">
                      {tx.method}
                    </td>
                    <td className="py-2.5 text-right font-semibold text-slate-700">
                      {tx.status === "voided" || tx.status === "held"
                        ? "—"
                        : formatKES(tx.total)}
                    </td>
                    <td className="py-2.5 text-center">
                      <Badge
                        status={tx.status}
                        variant={
                          tx.status === "completed"
                            ? "success"
                            : tx.status === "held"
                              ? "warning"
                              : tx.status === "refunded"
                                ? "info"
                                : tx.status === "partial_refund"
                                  ? "warning"
                                  : "danger"
                        }
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>

        <SectionCard
          title="Till Status"
          action={
            <button
              onClick={() => store.setScreen("tills")}
              className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700"
            >
              Manage <ArrowRight className="h-3 w-3" />
            </button>
          }
        >
          <div className="space-y-3">
            {openTills.map((till) => (
              <div
                key={till.id}
                className="flex items-center justify-between rounded-lg border border-slate-100 p-3 hover:border-slate-200"
              >
                <div>
                  <p className="text-sm font-semibold text-slate-700">
                    {till.name}
                  </p>
                  <p className="text-xs text-slate-500">{till.cashier}</p>
                </div>
                <div className="text-right">
                  <p className="text-sm font-bold text-slate-800">
                    {formatKES(till.cashSales + till.mpesaSales)}
                  </p>
                  <p className="text-xs text-slate-400">
                    {till.transactions} txns · {till.voids} voids
                  </p>
                </div>
              </div>
            ))}
          </div>
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <SectionCard
          title="Low Stock Alerts"
          action={
            <button
              onClick={() => store.setScreen("inventory")}
              className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700"
            >
              Restock <ArrowRight className="h-3 w-3" />
            </button>
          }
        >
          <div className="space-y-2">
            {lowStockProducts.slice(0, 5).map((p) => (
              <div
                key={p.id}
                className="flex items-center justify-between rounded-lg p-2.5 hover:bg-slate-50"
              >
                <div className="flex items-center gap-3">
                  <div className={`h-8 w-8 rounded-lg ${p.imageColor}`} />
                  <div>
                    <p className="text-sm font-medium text-slate-700">
                      {p.name}
                    </p>
                    <p className="text-xs text-slate-400">{p.department}</p>
                  </div>
                </div>
                <div className="text-right">
                  <p className="text-sm font-semibold text-red-600">
                    {p.stock} {p.unit}
                  </p>
                  <p className="text-xs text-slate-400">
                    Min: {p.reorderLevel}
                  </p>
                </div>
              </div>
            ))}
            {lowStockProducts.length === 0 && (
              <p className="py-4 text-center text-sm text-slate-400">
                All products well stocked
              </p>
            )}
          </div>
        </SectionCard>

        <SectionCard
          title="Near-Expiry Products"
          action={
            <button
              onClick={() => store.setScreen("inventory")}
              className="flex items-center gap-1 text-xs font-medium text-emerald-600 hover:text-emerald-700"
            >
              Review <ArrowRight className="h-3 w-3" />
            </button>
          }
        >
          <div className="space-y-2">
            {expiringProducts.slice(0, 5).map((p) => {
              const days = Math.ceil(
                (new Date(p.expiry!).getTime() -
                  new Date("2026-10-08").getTime()) /
                  (1000 * 60 * 60 * 24),
              );
              return (
                <div
                  key={p.id}
                  className="flex items-center justify-between rounded-lg p-2.5 hover:bg-slate-50"
                >
                  <div className="flex items-center gap-3">
                    <div className={`h-8 w-8 rounded-lg ${p.imageColor}`} />
                    <div>
                      <p className="text-sm font-medium text-slate-700">
                        {p.name}
                      </p>
                      <p className="text-xs text-slate-400">Batch: {p.batch}</p>
                    </div>
                  </div>
                  <Badge
                    status={`${days} day${days !== 1 ? "s" : ""} left`}
                    variant={days <= 2 ? "danger" : "warning"}
                  />
                </div>
              );
            })}
            {expiringProducts.length === 0 && (
              <p className="py-4 text-center text-sm text-slate-400">
                No products expiring soon
              </p>
            )}
          </div>
        </SectionCard>
      </div>
    </div>
  );
}
