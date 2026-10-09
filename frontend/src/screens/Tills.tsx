import { Badge, SectionCard, StatCard } from "@/components/ui/Primitives";
import { expectedCash as calculateExpectedCash } from "@/lib/business";
import type { Store as StoreType } from "@/store/useStore";
import { formatKES } from "@/store/useStore";
import {
  AlertTriangle,
  ArrowDownToLine,
  Banknote,
  Calculator,
  CheckCircle,
  DollarSign,
  Eye,
  Shield,
  Smartphone,
  X,
  XCircle,
} from "lucide-react";
import { useState } from "react";

interface Props {
  store: StoreType;
}

export function Tills({ store }: Props) {
  const [tab, setTab] = useState<
    "overview" | "transactions" | "alerts" | "approvals"
  >("overview");
  const [openTillId, setOpenTillId] = useState<string | null>(null);
  const [closeTillId, setCloseTillId] = useState<string | null>(null);
  const [dropTillId, setDropTillId] = useState<string | null>(null);
  const [paidOutTillId, setPaidOutTillId] = useState<string | null>(null);
  const [closeResult, setCloseResult] = useState<{
    variance: number;
    expected: number;
    counted: number;
  } | null>(null);
  const [notice, setNotice] = useState("");

  const totalCash = store.tills.reduce(
    (s, t) => s + t.cashSales + t.openingFloat,
    0,
  );
  const totalMpesa = store.tills.reduce((s, t) => s + t.mpesaSales, 0);
  const totalVoids = store.tills.reduce((s, t) => s + t.voids, 0);
  const totalVariance = store.tills.reduce(
    (s, t) => s + Math.abs(t.variance),
    0,
  );

  const showNotice = (msg: string) => {
    setNotice(msg);
    setTimeout(() => setNotice(""), 4000);
  };

  return (
    <div className="space-y-5 p-4 lg:p-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          title="Total Cash Sales"
          value={formatKES(totalCash)}
          icon={<Banknote className="h-5 w-5 text-white" />}
          color="bg-emerald-500"
        />
        <StatCard
          title="Total M-Pesa"
          value={formatKES(totalMpesa)}
          icon={<Smartphone className="h-5 w-5 text-white" />}
          color="bg-blue-500"
        />
        <StatCard
          title="Voids Today"
          value={String(totalVoids)}
          icon={<XCircle className="h-5 w-5 text-white" />}
          color="bg-amber-500"
        />
        <StatCard
          title="Total Variance"
          value={formatKES(totalVariance)}
          icon={<AlertTriangle className="h-5 w-5 text-white" />}
          color="bg-red-500"
        />
      </div>

      {notice && (
        <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">
          {notice}
        </div>
      )}

      <div className="flex gap-1 rounded-lg bg-slate-200 p-1 w-fit">
        {(
          [
            { id: "overview", label: "Till Overview" },
            { id: "transactions", label: "Transactions" },
            { id: "approvals", label: "Approvals" },
            { id: "alerts", label: "Loss Prevention" },
          ] as const
        ).map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`rounded-md px-4 py-2 text-sm font-medium transition-colors ${tab === t.id ? "bg-white text-slate-800 shadow-sm" : "text-slate-500 hover:text-slate-700"}`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "overview" && (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          {store.tills.map((till) => {
            const expectedCash = calculateExpectedCash(till);
            return (
              <div
                key={till.id}
                className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
              >
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div
                      className={`flex h-10 w-10 items-center justify-center rounded-lg ${till.status === "open" ? "bg-emerald-500" : "bg-slate-400"}`}
                    >
                      <Calculator className="h-5 w-5 text-white" />
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-slate-800">
                        {till.name}
                      </h3>
                      <p className="text-xs text-slate-400">{till.cashier}</p>
                    </div>
                  </div>
                  <Badge
                    status={till.status}
                    variant={
                      till.status === "open"
                        ? "success"
                        : till.status === "suspended"
                          ? "warning"
                          : "neutral"
                    }
                  />
                </div>
                <div className="mt-4 grid grid-cols-2 gap-3 text-sm">
                  <div className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs text-slate-400">Opening Float</p>
                    <p className="font-semibold text-slate-700">
                      {formatKES(till.openingFloat)}
                    </p>
                  </div>
                  <div className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs text-slate-400">Cash Sales</p>
                    <p className="font-semibold text-slate-700">
                      {formatKES(till.cashSales)}
                    </p>
                  </div>
                  <div className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs text-slate-400">M-Pesa Sales</p>
                    <p className="font-semibold text-slate-700">
                      {formatKES(till.mpesaSales)}
                    </p>
                  </div>
                  <div className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs text-slate-400">Transactions</p>
                    <p className="font-semibold text-slate-700">
                      {till.transactions}
                    </p>
                  </div>
                </div>
                <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-3">
                  <div className="flex items-center gap-2 text-xs">
                    <span className="text-slate-400">Expected Cash:</span>
                    <span className="font-semibold text-slate-700">
                      {formatKES(expectedCash)}
                    </span>
                  </div>
                  <div className="flex items-center gap-2 text-xs">
                    <span className="text-slate-400">Variance:</span>
                    <span
                      className={`font-semibold ${till.variance === 0 ? "text-emerald-600" : "text-red-600"}`}
                    >
                      {till.variance === 0
                        ? "None"
                        : formatKES(Math.abs(till.variance))}
                    </span>
                  </div>
                </div>
                {till.cashDrops.length > 0 && (
                  <div className="mt-2 text-xs text-slate-400">
                    {till.cashDrops.length} cash drop(s) ·{" "}
                    {formatKES(
                      till.cashDrops.reduce((s, d) => s + d.amount, 0),
                    )}
                  </div>
                )}
                {till.paidOuts.length > 0 && (
                  <div className="mt-1 text-xs text-slate-400">
                    {till.paidOuts.length} paid out(s) ·{" "}
                    {formatKES(till.paidOuts.reduce((s, p) => s + p.amount, 0))}
                  </div>
                )}
                {till.status === "open" && (
                  <div className="mt-3 flex flex-wrap gap-2">
                    <button
                      onClick={() => setDropTillId(till.id)}
                      className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200"
                    >
                      <ArrowDownToLine className="h-3.5 w-3.5" /> Cash Drop
                    </button>
                    <button
                      onClick={() => setPaidOutTillId(till.id)}
                      className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200"
                    >
                      <DollarSign className="h-3.5 w-3.5" /> Paid Out
                    </button>
                    <button
                      onClick={() => setCloseTillId(till.id)}
                      className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-red-50 py-2 text-xs font-medium text-red-600 hover:bg-red-100"
                    >
                      <Eye className="h-3.5 w-3.5" /> Close
                    </button>
                  </div>
                )}
                {till.status === "closed" && (
                  <button
                    onClick={() => setOpenTillId(till.id)}
                    className="mt-3 w-full rounded-lg bg-emerald-500 py-2 text-xs font-bold text-white hover:bg-emerald-600"
                  >
                    Open Till
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      {tab === "transactions" && (
        <SectionCard title="Today's Transactions">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs text-slate-400">
                  <th className="px-4 py-3 font-medium">Receipt</th>
                  <th className="px-4 py-3 font-medium">Time</th>
                  <th className="px-4 py-3 font-medium">Cashier</th>
                  <th className="px-4 py-3 font-medium">Customer</th>
                  <th className="px-4 py-3 font-medium">Method</th>
                  <th className="px-4 py-3 text-right font-medium">Total</th>
                  <th className="px-4 py-3 text-center font-medium">Status</th>
                  <th className="px-4 py-3 text-center font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-50">
                {store.transactions.map((tx) => (
                  <tr key={tx.id} className="hover:bg-slate-50">
                    <td className="px-4 py-3 font-mono text-xs font-medium text-slate-700">
                      {tx.receiptNo}
                    </td>
                    <td className="px-4 py-3 text-slate-500">{tx.time}</td>
                    <td className="px-4 py-3 text-slate-600">{tx.cashier}</td>
                    <td className="px-4 py-3 text-slate-500">
                      {tx.customer || "Walk-in"}
                    </td>
                    <td className="px-4 py-3 capitalize text-slate-600">
                      {tx.method}
                    </td>
                    <td className="px-4 py-3 text-right font-semibold text-slate-700">
                      {tx.status === "voided" || tx.status === "held"
                        ? "—"
                        : formatKES(tx.total)}
                    </td>
                    <td className="px-4 py-3 text-center">
                      <Badge
                        status={tx.status}
                        variant={
                          tx.status === "completed"
                            ? "success"
                            : tx.status === "held"
                              ? "warning"
                              : tx.status === "refunded" ||
                                  tx.status === "partial_refund"
                                ? "info"
                                : "danger"
                        }
                      />
                    </td>
                    <td className="px-4 py-3 text-center">
                      {tx.status === "completed" && (
                        <button
                          onClick={() => {
                            const r = store.processRefund(
                              tx.id,
                              tx.total,
                              "Customer request",
                              !store.hasPermission("refund"),
                            );
                            showNotice(
                              r.needsApproval
                                ? "Refund request sent for approval"
                                : r.approved
                                  ? "Refund processed"
                                  : "Refund failed",
                            );
                          }}
                          className="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-100"
                        >
                          Refund
                        </button>
                      )}
                      {tx.status === "completed" && (
                        <button
                          onClick={() => {
                            const r = store.voidTransaction(tx.id);
                            showNotice(
                              r.needsApproval
                                ? "Void request sent for approval"
                                : r.approved
                                  ? "Transaction voided"
                                  : "Void failed",
                            );
                          }}
                          className="ml-1 rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-100"
                        >
                          Void
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </SectionCard>
      )}

      {tab === "approvals" && (
        <SectionCard title="Pending Approvals">
          <div className="space-y-3">
            {store.pendingApprovals.map((ap) => (
              <div
                key={ap.id}
                className="rounded-lg border border-amber-200 bg-amber-50 p-4"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-sm font-semibold text-slate-700">
                      {ap.description}
                    </p>
                    <p className="text-xs text-slate-500 mt-1">
                      Requested by {ap.requestedBy} at {ap.requestedAt}
                    </p>
                    {ap.amount && (
                      <p className="text-xs text-slate-500">
                        Amount: {formatKES(ap.amount)}
                      </p>
                    )}
                  </div>
                  <Badge status={ap.type} variant="warning" />
                </div>
                <div className="mt-3 flex gap-2">
                  <button
                    onClick={() => {
                      const approved = store.approveRequest(ap.id, true);
                      showNotice(
                        approved
                          ? "Request approved"
                          : "Request was not applied. Review the cart, remaining quantities and active till.",
                      );
                    }}
                    className="flex flex-1 items-center justify-center gap-1 rounded-md bg-emerald-500 py-1.5 text-xs font-medium text-white hover:bg-emerald-600"
                  >
                    <CheckCircle className="h-3.5 w-3.5" /> Approve
                  </button>
                  <button
                    onClick={() => {
                      const denied = store.approveRequest(ap.id, false);
                      showNotice(
                        denied
                          ? "Request denied"
                          : "You cannot deny this request.",
                      );
                    }}
                    className="flex flex-1 items-center justify-center gap-1 rounded-md bg-red-500 py-1.5 text-xs font-medium text-white hover:bg-red-600"
                  >
                    <XCircle className="h-3.5 w-3.5" /> Deny
                  </button>
                </div>
              </div>
            ))}
            {store.pendingApprovals.length === 0 && (
              <p className="py-8 text-center text-sm text-slate-400">
                No pending approvals
              </p>
            )}
          </div>
        </SectionCard>
      )}

      {tab === "alerts" && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <SectionCard
            title="Suspicious Activity Alerts"
            className="lg:col-span-2"
          >
            <div className="space-y-3">
              {store.tills
                .filter((t) => t.variance !== 0 || t.voids > 1)
                .map((till) => (
                  <div
                    key={till.id}
                    className={`flex items-start gap-3 rounded-lg border p-4 ${till.variance !== 0 ? "border-amber-200 bg-amber-50" : "border-red-200 bg-red-50"}`}
                  >
                    <div
                      className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${till.variance !== 0 ? "bg-amber-500" : "bg-red-500"}`}
                    >
                      <Shield className="h-4 w-4 text-white" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center justify-between">
                        <p className="text-sm font-semibold text-slate-700">
                          {till.name} · {till.cashier}
                        </p>
                      </div>
                      <p className="mt-1 text-sm text-slate-600">
                        {till.variance !== 0
                          ? `Cash variance of ${formatKES(Math.abs(till.variance))}`
                          : `High void count (${till.voids})`}
                      </p>
                    </div>
                  </div>
                ))}
              {store.tills.filter((t) => t.variance !== 0 || t.voids > 1)
                .length === 0 && (
                <p className="py-4 text-center text-sm text-slate-400">
                  No alerts — all tills operating normally
                </p>
              )}
            </div>
          </SectionCard>
          <SectionCard title="Cash Drops Awaiting Acknowledgement">
            <div className="space-y-3">
              {store.tills
                .flatMap((t) =>
                  t.cashDrops
                    .filter((d) => !d.acknowledged)
                    .map((d) => ({ ...d, tillName: t.name })),
                )
                .map((d) => (
                  <div
                    key={d.id}
                    className="rounded-lg border border-slate-200 bg-slate-50 p-3"
                  >
                    <p className="text-sm font-medium text-slate-700">
                      {d.tillName}
                    </p>
                    <p className="text-xs text-slate-500">
                      {formatKES(d.amount)} · {d.time}
                    </p>
                    <button
                      onClick={() => {
                        store.acknowledgeDrop(d.id);
                        showNotice("Cash drop acknowledged");
                      }}
                      className="mt-2 rounded-md bg-emerald-500 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-600"
                    >
                      Acknowledge
                    </button>
                  </div>
                ))}
              {store.tills.flatMap((t) =>
                t.cashDrops.filter((d) => !d.acknowledged),
              ).length === 0 && (
                <p className="py-4 text-center text-sm text-slate-400">
                  No pending drops
                </p>
              )}
            </div>
          </SectionCard>
        </div>
      )}

      {/* Open Till Modal */}
      {openTillId && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setOpenTillId(null)}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">Open Till</h2>
              <button
                onClick={() => setOpenTillId(null)}
                className="text-slate-400"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-slate-600">
              {store.tills.find((t) => t.id === openTillId)?.name}
            </p>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Opening Float (KSh)
              </label>
              <input
                type="number"
                id="openFloat"
                defaultValue="5000"
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              />
            </div>
            <div className="mt-5 flex gap-3">
              <button
                onClick={() => setOpenTillId(null)}
                className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  const f =
                    parseFloat(
                      (document.getElementById("openFloat") as HTMLInputElement)
                        .value,
                    ) || 0;
                  const ok = store.openTill(openTillId, f);
                  if (ok) {
                    showNotice("Till opened successfully");
                    setOpenTillId(null);
                  } else {
                    showNotice("You do not have permission to open tills");
                  }
                }}
                className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"
              >
                Open Till
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Close Till Modal */}
      {closeTillId && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => {
            setCloseTillId(null);
            setCloseResult(null);
          }}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">Close Till</h2>
              <button
                onClick={() => {
                  setCloseTillId(null);
                  setCloseResult(null);
                }}
                className="text-slate-400"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            {!closeResult ? (
              <>
                <div className="mb-4 rounded-lg bg-slate-50 p-3">
                  <p className="text-xs text-slate-400">
                    Expected Cash (Float + Cash Sales)
                  </p>
                  <p className="text-lg font-bold text-slate-700">
                    {formatKES(
                      store.tills.find((t) => t.id === closeTillId)!
                        .openingFloat +
                        store.tills.find((t) => t.id === closeTillId)!
                          .cashSales,
                    )}
                  </p>
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-500">
                    Counted Cash (KSh)
                  </label>
                  <input
                    type="number"
                    id="countedCash"
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                  />
                </div>
                <div className="mt-5 flex gap-3">
                  <button
                    onClick={() => setCloseTillId(null)}
                    className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={() => {
                      const c =
                        parseFloat(
                          (
                            document.getElementById(
                              "countedCash",
                            ) as HTMLInputElement
                          ).value,
                        ) || 0;
                      const r = store.closeTill(closeTillId, c);
                      if (r) {
                        setCloseResult({
                          variance: r.variance,
                          expected: r.expected,
                          counted: c,
                        });
                      } else {
                        showNotice("You do not have permission to close tills");
                        setCloseTillId(null);
                      }
                    }}
                    className="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-bold text-white hover:bg-red-600"
                  >
                    Close Till
                  </button>
                </div>
              </>
            ) : (
              <div className="text-center">
                <div
                  className={`mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full ${closeResult.variance === 0 ? "bg-emerald-100" : "bg-amber-100"}`}
                >
                  <CheckCircle
                    className={`h-7 w-7 ${closeResult.variance === 0 ? "text-emerald-600" : "text-amber-600"}`}
                  />
                </div>
                <p className="text-sm text-slate-600">
                  Till closed successfully
                </p>
                <div className="mt-4 space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-slate-400">Expected:</span>
                    <span className="font-semibold text-slate-700">
                      {formatKES(closeResult.expected)}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-slate-400">Counted:</span>
                    <span className="font-semibold text-slate-700">
                      {formatKES(closeResult.counted)}
                    </span>
                  </div>
                  <div className="flex justify-between border-t border-slate-100 pt-2">
                    <span className="text-slate-400">Variance:</span>
                    <span
                      className={`font-bold ${closeResult.variance === 0 ? "text-emerald-600" : "text-red-600"}`}
                    >
                      {closeResult.variance === 0
                        ? "None"
                        : formatKES(Math.abs(closeResult.variance))}
                    </span>
                  </div>
                </div>
                <button
                  onClick={() => {
                    setCloseTillId(null);
                    setCloseResult(null);
                  }}
                  className="mt-4 w-full rounded-xl bg-slate-100 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-200"
                >
                  Done
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Cash Drop Modal */}
      {dropTillId && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setDropTillId(null)}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">Cash Drop</h2>
              <button
                onClick={() => setDropTillId(null)}
                className="text-slate-400"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-slate-600">
              Remove excess cash from{" "}
              {store.tills.find((t) => t.id === dropTillId)?.name}
            </p>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Amount (KSh)
              </label>
              <input
                type="number"
                id="dropAmount"
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              />
            </div>
            <div className="mt-5 flex gap-3">
              <button
                onClick={() => setDropTillId(null)}
                className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  const a =
                    parseFloat(
                      (
                        document.getElementById(
                          "dropAmount",
                        ) as HTMLInputElement
                      ).value,
                    ) || 0;
                  if (a > 0) {
                    store.cashDrop(dropTillId, a);
                    showNotice(`Cash drop of ${formatKES(a)} recorded`);
                    setDropTillId(null);
                  }
                }}
                className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"
              >
                Record Drop
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Paid Out Modal */}
      {paidOutTillId && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setPaidOutTillId(null)}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">Paid Out</h2>
              <button
                onClick={() => setPaidOutTillId(null)}
                className="text-slate-400"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-4 text-sm text-slate-600">
              Record expense from{" "}
              {store.tills.find((t) => t.id === paidOutTillId)?.name}
            </p>
            <div className="space-y-3">
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">
                  Amount (KSh)
                </label>
                <input
                  type="number"
                  id="paidOutAmount"
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-slate-500">
                  Reason
                </label>
                <input
                  type="text"
                  id="paidOutReason"
                  placeholder="e.g. Transport, supplies"
                  className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                />
              </div>
            </div>
            <div className="mt-5 flex gap-3">
              <button
                onClick={() => setPaidOutTillId(null)}
                className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  const a =
                    parseFloat(
                      (
                        document.getElementById(
                          "paidOutAmount",
                        ) as HTMLInputElement
                      ).value,
                    ) || 0;
                  const r = (
                    document.getElementById("paidOutReason") as HTMLInputElement
                  ).value;
                  if (a > 0 && r) {
                    store.paidOut(paidOutTillId, a, r);
                    showNotice(`Paid out ${formatKES(a)} for ${r}`);
                    setPaidOutTillId(null);
                  }
                }}
                className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white hover:bg-emerald-600"
              >
                Record
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
