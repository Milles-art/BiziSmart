import type { Transaction } from "../types";
import { businessDate, money } from "./business";
const escapeCsv = (value: unknown) => {
  const s = String(value ?? "");
  // Treat staff/customer text as text, not spreadsheet formulas.
  const safe = /^[=+@-]/.test(s) ? `'${s}` : s;
  return `"${safe.replace(/"/g, '""')}"`;
};
export function salesCsv(transactions: Transaction[]) {
  const headers = [
    "Receipt",
    "Date",
    "Time",
    "Cashier",
    "Customer",
    "Method",
    "Subtotal",
    "Tax",
    "Discount",
    "Total",
    "Cost",
    "Profit",
  ];
  return [
    headers,
    ...transactions.map((t) => [
      t.receiptNo,
      t.date,
      t.time,
      t.cashier,
      t.customer ?? "Walk-in",
      t.method,
      t.subtotal,
      t.tax,
      t.discount,
      t.total,
      t.cost,
      money(t.subtotal - t.cost),
    ]),
  ]
    .map((row) => row.map(escapeCsv).join(","))
    .join("\r\n");
}
export async function salesPdf(transactions: Transaction[], period: string) {
  const { jsPDF } = await import("jspdf");
  const doc = new jsPDF({ orientation: "landscape" });
  const header = () => {
    doc.setFontSize(16);
    doc.text("BiziSmart Sales Report", 14, 16);
    doc.setFontSize(10);
    doc.text(
      `${period} | Generated ${businessDate()} | KES | Local demo data`,
      14,
      23,
    );
    doc.text("Receipt", 14, 33);
    doc.text("Date", 61, 33);
    doc.text("Cashier", 88, 33);
    doc.text("Method", 144, 33);
    doc.text("Total", 194, 33);
    doc.text("Cost", 225, 33);
    doc.text("Profit (ex VAT)", 250, 33);
  };
  header();
  let y = 42;
  for (const t of transactions) {
    if (y > 184) {
      doc.addPage();
      header();
      y = 42;
    }
    doc.setFontSize(9);
    doc.text(t.receiptNo, 14, y);
    doc.text(t.date, 61, y);
    doc.text(t.cashier.slice(0, 27), 88, y);
    doc.text(t.method, 144, y);
    doc.text(t.total.toFixed(2), 194, y);
    doc.text(t.cost.toFixed(2), 225, y);
    doc.text(money(t.subtotal - t.cost).toFixed(2), 250, y);
    y += 8;
  }
  if (y > 182) {
    doc.addPage();
    y = 22;
  }
  doc.text(
    `${transactions.length} transactions | Sales KES ${money(transactions.reduce((s, t) => s + t.total, 0))} | Profit ex VAT KES ${money(transactions.reduce((s, t) => s + t.subtotal - t.cost, 0))}`,
    14,
    y + 5,
  );
  return doc;
}
