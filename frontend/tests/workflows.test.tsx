import React from "react";
import { act, create, type ReactTestRenderer } from "react-test-renderer";
import { Provider } from "react-redux";
import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";
import { useStore, type Store } from "../src/store/useStore";
import { reduxStore, loadBusiness, demoApi } from "../src/store/redux";
import {
  expectedCash,
  returnPlan,
  fullReturnLines,
  businessDate,
  periodStart,
  netTransaction,
} from "../src/lib/business";
import { salesPdf, salesCsv } from "../src/lib/exports";
// Load the real PDF dependency before beforeEach freezes timers. The test still
// generates actual PDF bytes; dependency loading must not wait on mocked timers.
import "jspdf";

let store: Store;
let renderer: ReactTestRenderer;
let initial: Record<string, unknown>;
function Harness() {
  store = useStore();
  return null;
}
beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date("2026-11-04T10:00:00Z"));
  if (initial)
    for (const [key, value] of Object.entries(initial))
      reduxStore.dispatch({
        type: "business/setField",
        payload: { key, value: structuredClone(value) },
      });
  act(() => {
    renderer = create(
      <Provider store={reduxStore}>
        <Harness />
      </Provider>,
    );
  });
  initial ??= structuredClone(reduxStore.getState().business);
  act(() => {
    store.login("0711001100", "1234");
  });
});
afterEach(() => {
  act(() => renderer.unmount());
  vi.useRealTimers();
});
function add(productId: string, qty = 1) {
  act(() => store.addToCart(productId, qty));
}
function sell() {
  let result;
  act(() => {
    result = store.completeSale("cash", { cashTendered: 10000 });
  });
  expect(result).toMatchObject({ success: true });
  return store.transactions[0];
}

describe("corrected checkout and stock workflows", () => {
  it("uses kilograms for price, cost, stock and movements, including repeated weights", () => {
    add("p10", 0.125);
    add("p10", 0.25);
    const txn = sell();
    expect(txn.total).toBe(45);
    expect(txn.cost).toBe(33.75);
    expect(txn.cashAmount).toBe(45);
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59.625);
    expect(store.stockMovements[0].qty).toBe(-0.375);
  });
  it("rejects stale double checkout, insufficient stock and non-finite split amounts", () => {
    add("p1", 999);
    expect(store.validatePayment("cash", { cashTendered: 1000000 }).valid).toBe(
      false,
    );
    act(() => store.clearCart());
    add("p10");
    expect(
      store.validatePayment("split", { cashAmount: NaN, mpesaAmount: 120 })
        .valid,
    ).toBe(false);
    const old = store.completeSale;
    act(() => {
      expect(old("cash", { cashTendered: 200 }).success).toBe(true);
      expect(old("cash", { cashTendered: 200 }).success).toBe(false);
    });
  });
  it("returns only selected quantities, and rejects repeat/over refunds", () => {
    add("p1", 3);
    add("p2", 2);
    const txn = sell();
    const lines = [{ index: 0, qty: 1, condition: "sellable" as const }];
    const amount = returnPlan(txn, lines).amount;
    act(() => {
      expect(
        store.processRefund(txn.id, amount, "Wrong item", true, lines).approved,
      ).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p1")!.stock).toBe(238);
    expect(store.products.find((p) => p.id === "p2")!.stock).toBe(178);
    expect(store.transactions[0].items[0].returnedQty).toBe(1);
    act(() => {
      expect(
        store.processRefund(txn.id, txn.total, "Too much", true, lines)
          .approved,
      ).toBe(false);
    });
    const rest = fullReturnLines(store.transactions[0]);
    const remaining = returnPlan(store.transactions[0], rest).amount;
    act(() => {
      expect(
        store.processRefund(txn.id, remaining, "Full remainder", true, rest)
          .approved,
      ).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p1")!.stock).toBe(240);
    expect(store.transactions[0].status).toBe("refunded");
    act(() => {
      expect(store.processRefund(txn.id, 1, "Duplicate", true).approved).toBe(
        false,
      );
    });
  });
  it("keeps damaged returns out of sellable inventory and amount-only refunds do not return items", () => {
    add("p10", 0.5);
    const txn = sell();
    act(() => {
      store.processRefund(txn.id, 10, "Overcharge", true, []);
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59.5);
    const lines = [{ index: 0, qty: 0.5, condition: "damaged" as const }];
    act(() => {
      expect(
        store.processRefund(txn.id, 50, "Damaged", true, lines).approved,
      ).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59.5);
    expect(store.stockMovements[0].type).toBe("Damage");
    expect(netTransaction(store.transactions[0]).tax).toBe(0);
  });
  it("refunds zero VAT on a non-taxable line in a mixed-tax sale", () => {
    add("p1");
    add("p10");
    const txn = sell();
    expect(txn.tax).toBe(30);
    const lines = [{ index: 1, qty: 1, condition: "sellable" as const }];
    expect(returnPlan(txn, lines).amount).toBe(120);
    act(() => {
      store.processRefund(txn.id, 120, "Return produce", true, lines);
    });
    expect(netTransaction(store.transactions[0]).tax).toBe(30);
  });
  it("defers discounts until approval and refuses an altered-cart approval", () => {
    add("p1");
    act(() => {
      store.applyCartDiscount(15);
    });
    expect(store.cart[0].discount).toBe(0);
    const approval = store.approvals.at(-1)!;
    act(() => store.approveRequest(approval.id, true));
    expect(store.cart[0].discount).toBe(27.75);
    act(() => store.applyCartDiscount(20));
    const stale = store.approvals.at(-1)!;
    add("p2");
    act(() => store.approveRequest(stale.id, true));
    expect(store.approvals.find((a) => a.id === stale.id)!.status).toBe(
      "pending",
    );
  });
  it("requires supervisor approval for cashier returns, even if caller bypasses the UI flag", () => {
    add("p10");
    const txn = sell();
    act(() => {
      store.logout();
      store.login("0711004400", "4567");
    });
    act(() => {
      expect(
        store.processRefund(txn.id, 120, "Return", false).needsApproval,
      ).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59);
    const ap = store.approvals.at(-1)!;
    act(() => store.approveRequest(ap.id, true));
    expect(store.approvals.at(-1)!.status).toBe("pending");
    act(() => {
      store.logout();
      store.login("0711001100", "1234");
    });
    act(() => store.approveRequest(ap.id, true));
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(60);
    act(() => store.approveRequest(ap.id, true));
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(60);
  });
  it("leaves exchange drafts untouched, then records replacement, stock and positive cash difference", () => {
    add("p10");
    const txn = sell();
    const before = store.activeTill.cashSales;
    act(() => {
      expect(store.processExchange(txn.id, "Wrong item").approved).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59);
    add("p1");
    act(() => {
      expect(store.completeExchange(txn.id, "Wrong item", 0).success).toBe(
        false,
      );
    });
    expect(store.transactions[0].id).toBe(txn.id);
    act(() => {
      expect(store.completeExchange(txn.id, "Wrong item", 100).success).toBe(
        true,
      );
    });
    expect(store.transactions[0].total).toBe(215);
    expect(store.transactions[0].exchangeReceiptId).toBe(txn.id);
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(60);
    expect(store.products.find((p) => p.id === "p1")!.stock).toBe(239);
    expect(store.activeTill.cashSales).toBe(before + 95);
  });
  it("records cheaper replacements and pays the negative difference from the till", () => {
    add("p1");
    const txn = sell();
    const cash = store.activeTill.cashSales;
    add("p10");
    act(() => {
      expect(
        store.completeExchange(txn.id, "Cheaper replacement", 0).success,
      ).toBe(true);
    });
    expect(store.activeTill.cashSales).toBe(cash - 95);
    expect(store.transactions[0].cashAmount).toBe(120);
  });
  it("queues a cashier exchange without stock or payment mutation and executes once on approval", () => {
    add("p10");
    const txn = sell();
    act(() => {
      store.logout();
      store.login("0711004400", "4567");
    });
    add("p1");
    const before = store.activeTill.cashSales;
    act(() => {
      expect(store.completeExchange(txn.id, "Replace", 100).needsApproval).toBe(
        true,
      );
    });
    expect(store.activeTill.cashSales).toBe(before);
    expect(store.transactions[0].id).toBe(txn.id);
    const ap = store.approvals.at(-1)!;
    act(() => {
      store.logout();
      store.login("0711001100", "1234");
    });
    act(() => store.approveRequest(ap.id, true));
    expect(store.approvals.find((a) => a.id === ap.id)!.status).toBe(
      "approved",
    );
    expect(store.products.find((p) => p.id === "p1")!.stock).toBe(239);
    act(() => store.approveRequest(ap.id, true));
    expect(store.products.find((p) => p.id === "p1")!.stock).toBe(239);
  });
  it("accounts for drops and paid-outs once, before and after acknowledgement", () => {
    const initial = expectedCash(store.activeTill);
    act(() => {
      store.cashDrop("t1", 500);
    });
    act(() => {
      store.paidOut("t1", 200, "Delivery");
    });
    expect(expectedCash(store.activeTill)).toBe(initial - 700);
    act(() => store.acknowledgeDrop(store.activeTill.cashDrops[0].id));
    expect(expectedCash(store.activeTill)).toBe(initial - 700);
    act(() => {
      expect(store.closeTill("t1", initial - 700)).toEqual({
        expected: initial - 700,
        variance: 0,
      });
    });
    add("p10");
    expect(store.validatePayment("cash", { cashTendered: 1000 }).valid).toBe(
      false,
    );
  });
  it("uses current Nairobi dates, periods, and excludes yesterday from today totals", () => {
    expect(store.weeklySalesData.at(-1)!.date).toBe("2026-11-04");
    expect(store.todaySales.total).toBe(0);
    add("p10");
    sell();
    expect(store.todaySales.total).toBe(120);
    expect(businessDate(new Date("2026-11-04T22:00:00Z"))).toBe("2026-11-05");
    expect(periodStart("weekly", "2026-11-04")).toBe("2026-11-02");
  });
  it("loads versioned saved data, handles corrupt state, and produces actual PDF bytes and escaped CSV", async () => {
    add("p10");
    const txn = sell();
    const saved = { version: 2, business: reduxStore.getState().business };
    expect(
      loadBusiness({ getItem: () => JSON.stringify(saved) }).transactions,
    ).toEqual(store.transactions);
    expect(loadBusiness({ getItem: () => "{broken" })).toEqual({});
    expect(
      loadBusiness({
        getItem: () => '{"version":2,"business":{"products":4}}',
      }),
    ).toEqual({});
    const report = await salesPdf([txn], "daily");
    expect(report.output().startsWith("%PDF-")).toBe(
      true,
    );
    expect(
      salesCsv([{ ...txn, customer: "=SUM(1,2)", cashier: 'A, "B"' }]),
    ).toContain('"A, ""B"""');
  });
  it("preserves M-Pesa tender values when a cheaper exchange is settled in cash", () => {
    add("p1");
    act(() => {
      expect(
        store.completeSale("mpesa", {
          mpesaPhone: "0712345678",
          mpesaRef: "DEMO-TEST",
        }).success,
      ).toBe(true);
    });
    const txn = store.transactions[0];
    const before = store.activeTill.cashSales;
    add("p10");
    act(() => {
      expect(
        store.completeExchange(txn.id, "Cheaper replacement", 0).success,
      ).toBe(true);
    });
    expect(store.activeTill.cashSales).toBe(before - 95);
    expect(store.transactions[0].cashAmount).toBe(0);
    expect(store.transactions[0].mpesaAmount).toBe(120);
    const original = netTransaction(
      store.transactions.find((t) => t.id === txn.id)!,
    );
    expect(original.cashAmount).toBe(-95);
    expect(original.mpesaAmount).toBe(95);
  });
  it("does not restore damaged exchange stock and preserves all stock when completion fails", () => {
    add("p10");
    const txn = sell();
    add("p1");
    const lines = fullReturnLines(txn).map((l) => ({
      ...l,
      condition: "damaged" as const,
    }));
    act(() => {
      expect(store.completeExchange(txn.id, "Damaged", 10, lines).success).toBe(
        false,
      );
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59);
    act(() => {
      expect(
        store.completeExchange(txn.id, "Damaged", 100, lines).success,
      ).toBe(true);
    });
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59);
  });
  it("paginates large PDF reports", async () => {
    add("p10");
    const txn = sell();
    const doc = await salesPdf(
      Array.from({ length: 60 }, () => txn),
      "monthly",
    );
    expect(doc.getNumberOfPages()).toBeGreaterThan(2);
  });
  it("reads persisted products through RTK Query", async () => {
    const result = await reduxStore.dispatch(
      demoApi.endpoints.products.initiate(undefined, { forceRefetch: true }),
    );
    expect(result.data).toHaveLength(36);
  });
  it("writes the complete transaction to browser storage and restores it in a fresh store", async () => {
    add("p10", 0.125);
    const txn = sell();
    await Promise.resolve();
    const persisted = JSON.parse(localStorage.getItem("bizismart-demo-v2")!);
    expect(persisted.business.transactions[0].id).toBe(txn.id);
    expect(
      persisted.business.products.find((p: { id: string }) => p.id === "p10")
        .stock,
    ).toBe(59.875);
    act(() => renderer.unmount());
    vi.resetModules();
    const freshRedux = await import("../src/store/redux");
    const freshHook = await import("../src/store/useStore");
    function FreshHarness() {
      store = freshHook.useStore();
      return null;
    }
    act(() => {
      renderer = create(
        <Provider store={freshRedux.reduxStore}>
          <FreshHarness />
        </Provider>,
      );
    });
    expect(store.currentUser).toBe(null);
    expect(store.transactions[0].id).toBe(txn.id);
    expect(store.products.find((p) => p.id === "p10")!.stock).toBe(59.875);
  });
});
