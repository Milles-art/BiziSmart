// @vitest-environment jsdom
import React from "react";
import { afterEach, beforeEach, expect, it } from "vitest";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import { Provider } from "react-redux";
import { reduxStore } from "../src/store/redux";
import { useStore, type Store } from "../src/store/useStore";
import { POS } from "../src/screens/POS";
let store: Store;
let initial: Record<string, unknown>;
function Harness() {
  store = useStore();
  return <POS store={store} />;
}
beforeEach(() => {
  if (initial)
    for (const [key, value] of Object.entries(initial))
      reduxStore.dispatch({
        type: "business/setField",
        payload: { key, value: structuredClone(value) },
      });
  render(
    <Provider store={reduxStore}>
      <Harness />
    </Provider>,
  );
  initial ??= structuredClone(reduxStore.getState().business);
  act(() => {
    store.login("0711001100", "1234");
  });
});
afterEach(cleanup);
it("adds actual kilograms through the existing scale modal", () => {
  fireEvent.change(
    screen.getByPlaceholderText("Search or scan barcode, then press Enter..."),
    { target: { value: "Tomatoes" } },
  );
  fireEvent.click(screen.getByText("Tomatoes 1kg").closest("button")!);
  fireEvent.change(screen.getByRole("spinbutton"), {
    target: { value: "0.375" },
  });
  fireEvent.click(screen.getByRole("button", { name: /Add to Cart/ }));
  expect(store.cart[0].qty).toBe(0.375);
  expect(store.cart[0].price).toBe(120);
  expect(screen.getByText("0.375")).toBeTruthy();
});
it("selects one returned unit in the refund UI and leaves the remaining sale stock deducted", () => {
  act(() => store.addToCart("p1", 3));
  act(() => {
    store.completeSale("cash", { cashTendered: 1000 });
  });
  const txn = store.transactions[0];
  fireEvent.click(screen.getByRole("button", { name: /^Refund/ }));
  const selects = screen.getAllByRole("combobox");
  fireEvent.change(
    selects.find((s) => s.querySelector(`option[value="${txn.id}"]`))!,
    { target: { value: txn.id } },
  );
  fireEvent.change(
    screen.getByLabelText("Return quantity Sifted Maize Flour 2kg"),
    { target: { value: "1" } },
  );
  fireEvent.change(
    screen
      .getAllByRole("combobox")
      .find((s) =>
        Array.from(s.querySelectorAll("option")).some(
          (o) => o.textContent === "Wrong item sold",
        ),
      )!,
    { target: { value: "Wrong item sold" } },
  );
  fireEvent.click(screen.getByRole("button", { name: "Process Refund" }));
  expect(screen.getByText("Refund processed successfully")).toBeTruthy();
  expect(store.products.find((p) => p.id === "p1")!.stock).toBe(238);
});
it("shows the existing sidebar-independent POS layout and blocks applying a large discount early", () => {
  act(() => store.addToCart("p1"));
  fireEvent.click(screen.getByRole("button", { name: "Discount" }));
  const input = screen.getByPlaceholderText(/%/);
  fireEvent.change(input, { target: { value: "15" } });
  fireEvent.click(screen.getByRole("button", { name: "Apply" }));
  expect(store.cart[0].discount).toBe(0);
  expect(screen.getByText(/discount requested/)).toBeTruthy();
});
