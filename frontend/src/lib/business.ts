import type { CartItem, Product, Till, Transaction } from "../types";

export const quantity = (n: number) =>
  Math.round((n + Number.EPSILON) * 1000) / 1000;
export const money = (n: number) =>
  Math.round((n + Number.EPSILON) * 100) / 100;
export const businessDate = (date = new Date()) =>
  new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Nairobi",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(date);
export const expectedCash = (till: Till) =>
  money(
    till.openingFloat +
      till.cashSales -
      till.cashDrops.reduce((s, d) => s + d.amount, 0) -
      till.paidOuts.reduce((s, p) => s + p.amount, 0),
  );
export const cartTotals = (items: CartItem[], products: Product[]) => {
  const subtotal = money(
    items.reduce((s, i) => s + i.price * i.qty - i.discount, 0),
  );
  const taxable = items
    .filter((i) => products.find((p) => p.id === i.productId)?.taxable)
    .reduce((s, i) => s + i.price * i.qty - i.discount, 0);
  return {
    subtotal,
    tax: Math.round(taxable * 0.16),
    total: money(subtotal + Math.round(taxable * 0.16)),
  };
};
export interface ReturnLine {
  index: number;
  qty: number;
  condition: "sellable" | "damaged";
}
export function returnPlan(txn: Transaction, lines: ReturnLine[]) {
  if (!["completed", "partial_refund"].includes(txn.status) || !lines.length)
    throw new Error("Select items from an eligible receipt");
  const seen = new Set<number>();
  const selected = lines.map((line) => {
    const item = txn.items[line.index];
    if (
      !item ||
      seen.has(line.index) ||
      !Number.isFinite(line.qty) ||
      line.qty <= 0 ||
      line.qty > quantity(item.qty - (item.returnedQty ?? 0)) ||
      (!item.weighed && !Number.isInteger(line.qty)) ||
      !["sellable", "damaged"].includes(line.condition)
    )
      throw new Error("Invalid or already returned quantity");
    seen.add(line.index);
    return {
      ...line,
      item,
      net: money(
        ((item.price * item.qty - item.discount) * line.qty) / item.qty,
      ),
    };
  });
  const net = money(selected.reduce((s, l) => s + l.net, 0));
  const allRemaining = txn.items.every(
    (item, index) =>
      quantity(
        (item.returnedQty ?? 0) +
          (selected.find((l) => l.index === index)?.qty ?? 0),
      ) === item.qty,
  );
  const amount = allRemaining
    ? money(txn.total - (txn.refundAmount ?? 0))
    : money(
        net +
          selected.reduce(
            (s, l) =>
              s +
              (l.item.taxAmount !== undefined
                ? (l.item.taxAmount * l.qty) / l.item.qty
                : txn.subtotal
                  ? (txn.tax * l.net) / txn.subtotal
                  : 0),
            0,
          ),
      );
  const cost = money(
    selected.reduce((s, l) => s + (l.item.unitCost ?? 0) * l.qty, 0),
  );
  return {
    selected,
    amount,
    cost,
    tax: allRemaining
      ? money(txn.tax - (txn.refundTax ?? 0))
      : money(amount - net),
    allRemaining,
  };
}
export function fullReturnLines(txn: Transaction): ReturnLine[] {
  return txn.items
    .map((item, index) => ({
      index,
      qty: quantity(item.qty - (item.returnedQty ?? 0)),
      condition: "sellable" as const,
    }))
    .filter((l) => l.qty > 0);
}
export const netTransaction = (t: Transaction): Transaction => {
  const refund = t.refundAmount ?? 0;
  const ratio = t.total ? refund / t.total : 0;
  return {
    ...t,
    total: money(t.total - refund),
    subtotal: money(t.subtotal - (t.refundSubtotal ?? t.subtotal * ratio)),
    tax: money(t.tax - (t.refundTax ?? t.tax * ratio)),
    cost: money(t.cost - (t.refundCost ?? t.cost * ratio)),
    cashAmount: money(
      (t.cashAmount ?? (t.method === "cash" ? t.total : 0)) -
        (t.refundCash ??
          (t.cashAmount ?? (t.method === "cash" ? t.total : 0)) * ratio),
    ),
    mpesaAmount: money(
      (t.mpesaAmount ?? (t.method === "mpesa" ? t.total : 0)) -
        (t.refundMpesa ??
          (t.mpesaAmount ?? (t.method === "mpesa" ? t.total : 0)) * ratio),
    ),
    items: t.items.map((i) => ({
      ...i,
      qty: quantity(i.qty - (i.returnedQty ?? 0)),
      discount: money(i.discount * (1 - (i.returnedQty ?? 0) / i.qty)),
    })),
  };
};
export function periodStart(
  period: "daily" | "weekly" | "monthly",
  date = businessDate(),
) {
  if (period === "daily") return date;
  if (period === "monthly") return date.slice(0, 7) + "-01";
  const d = new Date(date + "T12:00:00Z");
  d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7));
  return d.toISOString().slice(0, 10);
}

export function saleItems(items: CartItem[], products: Product[]) {
  const taxable = items.reduce(
    (s, i) =>
      s +
      (products.find((p) => p.id === i.productId)?.taxable
        ? i.price * i.qty - i.discount
        : 0),
    0,
  );
  const tax = Math.round(taxable * 0.16);
  let allocated = 0;
  const taxableIndices = items.flatMap((i, index) =>
    products.find((p) => p.id === i.productId)?.taxable ? [index] : [],
  );
  return items.map((i, index) => {
    const p = products.find((p) => p.id === i.productId);
    const lineTax = p?.taxable
      ? index === taxableIndices[taxableIndices.length - 1]
        ? money(tax - allocated)
        : money((tax * (i.price * i.qty - i.discount)) / taxable)
      : 0;
    allocated = money(allocated + lineTax);
    return { ...i, unitCost: p?.cost ?? 0, taxAmount: lineTax, returnedQty: 0 };
  });
}
