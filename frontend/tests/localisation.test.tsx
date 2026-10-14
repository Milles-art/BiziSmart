// @vitest-environment jsdom
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import { afterEach, expect, it, vi } from "vitest";
import { useState } from "react";
import { Provider, useSelector } from "react-redux";
import { configureStore } from "@reduxjs/toolkit";
import {
  currentLanguage,
  setLanguage,
  translate,
  useLanguage,
} from "../src/i18n";
import { errorMessage, type CashSale } from "../src/features/live/api";
import {
  checkoutActions,
  checkoutReducer,
  type CheckoutState,
} from "../src/features/live/checkoutState";
import { receiptHtml, receiptRows } from "../src/features/live/receiptOutput";
import { kiswahili } from "../src/i18n/kiswahili";

afterEach(() => {
  cleanup();
  setLanguage("en");
  localStorage.clear();
  vi.restoreAllMocks();
});
function Cart() {
  const { language, setLanguage, t } = useLanguage();
  const entries = useSelector(
    (state: { checkout: CheckoutState }) => state.checkout.entries,
  );
  const [input, setInput] = useState("0.750");
  return (
    <>
      <button onClick={() => setLanguage(language === "en" ? "sw" : "en")}>
        English / Kiswahili
      </button>
      <h1>{t("Point of Sale")}</h1>
      <input
        aria-label="quantity"
        value={input}
        onChange={(event) => setInput(event.target.value)}
      />
      <p>{entries[0]?.product.name}</p>
    </>
  );
}
it("switches immediately without remounting fields or modifying an exact checkout draft", () => {
  const store = configureStore({ reducer: { checkout: checkoutReducer } });
  store.dispatch(
    checkoutActions.add({
      key: "line_one",
      product: {
        id: "prod_one",
        name: "Fresh Choice Rice",
        barcode: "123",
        category: null,
        quantity_type: "weight",
        unit: "kg",
        selling_price: 12000,
        reorder_level: "1.000",
        tracks_expiry: false,
        is_active: true,
        stock: "5.000",
        stock_available: true,
      },
      qty: "0.750",
      packaging_id: null,
      discount: 150,
    }),
  );
  store.dispatch(
    checkoutActions.submitting({
      key: "immutable_uuid",
      body: {
        items: [{ product_id: "prod_one", qty: "0.750", discount: 150 }],
        cash_tendered: 10000,
      },
    }),
  );
  const original = store.getState().checkout;
  render(
    <Provider store={store}>
      <Cart />
    </Provider>,
  );
  fireEvent.change(screen.getByLabelText("quantity"), {
    target: { value: "0.650" },
  });
  fireEvent.click(screen.getByRole("button", { name: "English / Kiswahili" }));
  expect(screen.getByRole("heading").textContent).toBe("Sehemu ya mauzo");
  expect((screen.getByLabelText("quantity") as HTMLInputElement).value).toBe(
    "0.650",
  );
  expect(screen.getByText("Fresh Choice Rice")).toBeTruthy();
  expect(store.getState().checkout).toBe(original);
  expect(localStorage.getItem("bizismart-language")).toBe("sw");
  expect(localStorage.length).toBe(1);
  expect(document.documentElement.lang).toBe("sw");
});
it("uses an English fallback and retains language switching when storage is unavailable", () => {
  vi.spyOn(localStorage, "setItem").mockImplementation(() => {
    throw new Error("disabled");
  });
  act(() => setLanguage("sw"));
  expect(currentLanguage()).toBe("sw");
  expect(translate("A new contextual server message")).toBe(
    "A new contextual server message",
  );
  expect(translate("Total")).toBe("Jumla");
});
it("maps stable API error codes without changing English server validation or contextual fallback", () => {
  const error = {
    data: {
      error: {
        code: "INSUFFICIENT_STOCK",
        message: "Available batch balance: 0.500 kg.",
      },
    },
  };
  expect(errorMessage(error)).toBe("Available batch balance: 0.500 kg.");
  setLanguage("sw");
  expect(errorMessage(error)).toBe(
    "Stoo inayoruhusiwa kuuzwa haitoshi kwa kiasi hiki.",
  );
  expect(
    errorMessage({
      data: {
        error: {
          code: "UNKNOWN_CONTEXT",
          message: "Product Fresh Choice Rice is unavailable.",
        },
      },
    }),
  ).toBe("Product Fresh Choice Rice is unavailable.");
  expect(errorMessage({})).toBe(
    "Muunganisho umeshindikana. Tafadhali jaribu tena.",
  );
});
const sale = {
  receipt_number: "RCP-001",
  created_at: "2026-10-13T10:00:00Z",
  subtotal: 9000,
  discount: 500,
  total: 8500,
  vat_rate: 18,
  vat_amount: 1297,
  cash_tendered: 10000,
  change_given: 1500,
  lines: [
    {
      name: "Fresh Choice Rice",
      qty: "0.750",
      unit: "kg",
      packaging_name: null,
      unit_price: 12000,
      line_discount: 500,
      discount: 500,
      line_total: 8500,
    },
  ],
  payments: [{ method: "cash", amount: 8500 }],
} as CashSale;
it("localises saved receipt output while preserving original product names, quantities and money", () => {
  const snapshot = JSON.stringify(sale);
  const rows = receiptRows(sale, "Fresh Choice Supermarket", "sw");
  expect(rows).toContain("Jumla: TSh 8,500");
  expect(rows).toContain("Chenji: TSh 1,500");
  expect(rows).toContain("Punguzo la bidhaa: TSh 500");
  expect(rows).toContain("0.750 kg @ TSh 12,000");
  expect(rows).toContain("Fresh Choice Rice");
  expect(receiptHtml(sale, "<script>shop</script>", "sw")).toContain(
    '<html lang="sw">',
  );
  expect(receiptHtml(sale, "<script>shop</script>", "sw")).not.toContain(
    "<script>shop</script>",
  );
  expect(JSON.stringify(sale)).toBe(snapshot);
});
it("prints the original offline event time while retaining the separate server recording time", () => {
  const offline = { ...sale, occurred_at: "2026-10-12T20:00:00Z", is_offline: true };
  const rows = receiptRows(offline, "Fresh Choice", "en");
  expect(rows).toContain(new Date(offline.occurred_at).toLocaleString("en-TZ", { timeZone: "Africa/Dar_es_Salaam" }));
  expect(rows).not.toContain(new Date(offline.created_at).toLocaleString("en-TZ", { timeZone: "Africa/Dar_es_Salaam" }));
  expect(offline.created_at).toBe(sale.created_at);
});
it("accepts only language values and shares validated preferences across tabs", () => {
  setLanguage("malicious" as "sw");
  expect(currentLanguage()).toBe("en");
  localStorage.setItem("bizismart-language", "sw");
  window.dispatchEvent(
    new StorageEvent("storage", { key: "bizismart-language" }),
  );
  expect(currentLanguage()).toBe("sw");
  localStorage.setItem("bizismart-language", "invalid");
  window.dispatchEvent(
    new StorageEvent("storage", { key: "bizismart-language" }),
  );
  expect(currentLanguage()).toBe("en");
});
it("provides explicit Kiswahili keys for the live navigation and all receipt labels", () => {
  for (const key of [
    "Dashboard",
    "Point of Sale",
    "Products",
    "Inventory",
    "Suppliers",
    "Tills & Cash",
    "Customers",
    "Staff",
    "Reports",
    "Settings",
    "Subtotal",
    "Discount",
    "Total",
    "VAT included",
    "Line discount",
    "Cart discount share",
    "Customer credit",
    "Selcom mobile payment",
    "Cash tendered",
    "Change",
  ])
    expect(kiswahili[key], key).toBeTruthy();
});

