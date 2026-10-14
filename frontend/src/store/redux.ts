import { offlineReconciliationReducer } from '../features/live/offlineReconciliationState';
import { clearOfflineCatalogue } from '../features/live/offlineVault';
import { signedOut } from '../features/live/api';
import type { Middleware } from '@reduxjs/toolkit';
import { staffOperationsReducer } from '../features/live/staffOperationsState';
import { messagingReducer } from '../features/live/messagingState';
import { incentivesReducer } from '../features/live/incentivesState';
import {
  configureStore,
  createSlice,
  type PayloadAction,
} from "@reduxjs/toolkit";
import { createApi, fakeBaseQuery } from "@reduxjs/toolkit/query/react";
import {
  useCallback,
  useEffect,
  useRef,
  useSyncExternalStore,
  type SetStateAction,
} from "react";
import { useSelector } from "react-redux";
import type { Product } from "../types";
import { liveApi, sessionReducer } from '../features/live/api';
import { rememberSessionMiddleware, restoreRememberedSession } from '../features/live/rememberedSession';
import { checkoutReducer } from '../features/live/checkoutState';
import { returnReducer } from '../features/live/returnState';
import {mobileRefundReducer} from '../features/live/mobileRefundState';
import {catalogueImportReducer} from '../features/live/catalogueImportState';
import { financialReducer } from '../features/live/financeState';
import { supplierReducer } from '../features/live/supplierState';
import { pricingReducer } from '../features/live/pricingState';
import {heldReducer} from '../features/live/heldState';
import { collectionReducer } from '../features/live/collectionState';

const STORAGE_KEY = "bizismart-demo-v2";
export function loadBusiness(
  storage: Pick<Storage, "getItem">,
): Record<string, unknown> {
  try {
    const saved = JSON.parse(storage.getItem(STORAGE_KEY) ?? "null");
    if (
      saved?.version !== 2 ||
      !saved.business ||
      typeof saved.business !== "object" ||
      Array.isArray(saved.business)
    )
      return {};
    const business = saved.business;
    const arrayKeys = [
      "products",
      "customers",
      "suppliers",
      "purchaseOrders",
      "staffList",
      "tills",
      "transactions",
      "approvals",
      "stockMovements",
      "priceChanges",
      "promotions",
      "auditLog",
      "mpesaPayments",
      "heldSales",
      "cart",
    ];
    if (
      arrayKeys.some((key) => key in business && !Array.isArray(business[key]))
    )
      return {};
    if (
      "products" in business &&
      business.products.some(
        (p: Product) =>
          !p ||
          typeof p.id !== "string" ||
          !Number.isFinite(p.stock) ||
          !Number.isFinite(p.price),
      )
    )
      return {};
    return business;
  } catch {
    return {};
  }
}
const initialState =
  import.meta.env.MODE === 'test' && typeof localStorage !== 'undefined' ? loadBusiness(localStorage) : {};
const businessSlice = createSlice({
  name: "business",
  initialState: initialState as Record<string, unknown>,
  reducers: {
    setField(state, action: PayloadAction<{ key: string; value: unknown }>) {
      state[action.payload.key] = action.payload.value;
    },
  },
});
// Local demo queries are explicit; replace these queryFn adapters with authenticated API endpoints when the backend is ready.
export const demoApi = createApi({
  reducerPath: "demoApi",
  baseQuery: fakeBaseQuery(),
  tagTypes: ["Products"],
  endpoints: (builder) => ({
    products: builder.query<Product[], void>({
      queryFn: (_arg, api) => ({
        data: ((api.getState() as { business: Record<string, unknown> })
          .business.products ?? []) as Product[],
      }),
      providesTags: ["Products"],
    }),
  }),
});
const offlineSignOutMiddleware: Middleware = () => next => action => {
  if (signedOut.match(action)) void clearOfflineCatalogue().catch(() => { /* Retain encrypted pending records; storage failures never authenticate a workspace. */ });
  return next(action);
};
export const reduxStore = configureStore({
  reducer: {
    messaging:messagingReducer,offlineReconciliation:offlineReconciliationReducer,staffOperations:staffOperationsReducer,incentives:incentivesReducer,financial:financialReducer,supplierOperations:supplierReducer,pricing:pricingReducer,
    returns: returnReducer, mobileRefund:mobileRefundReducer,
    checkout: checkoutReducer,
    collection: collectionReducer,
    session: sessionReducer, held:heldReducer,catalogueImport:catalogueImportReducer,
    [liveApi.reducerPath]: liveApi.reducer,
    business: businessSlice.reducer,
    [demoApi.reducerPath]: demoApi.reducer,
  },
  middleware: (g) => g().concat(demoApi.middleware, liveApi.middleware, rememberSessionMiddleware, offlineSignOutMiddleware),
  devTools: false,
});
restoreRememberedSession(reduxStore.dispatch);
let queued = false;
let persistenceError: string | null = null;
const persistenceListeners = new Set<() => void>();
function setPersistenceError(error: string | null) {
  if (persistenceError !== error) {
    persistenceError = error;
    persistenceListeners.forEach((listener) => listener());
  }
}
export function usePersistenceError() {
  return useSyncExternalStore(
    (listener) => {
      persistenceListeners.add(listener);
      return () => {
        persistenceListeners.delete(listener);
      };
    },
    () => persistenceError,
    () => null,
  );
}
reduxStore.subscribe(() => {
  if (import.meta.env.MODE !== 'test') return;
  if (queued || typeof localStorage === "undefined") return;
  queued = true;
  queueMicrotask(() => {
    queued = false;
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          version: 2,
          business: reduxStore.getState().business,
        }),
      );
      setPersistenceError(null);
    } catch {
      setPersistenceError(
        "Local data could not be saved. Free browser storage before closing this browser.",
      );
    }
  });
});
export function useBusinessState<T>(
  key: string,
  initial: T,
): [T, (value: SetStateAction<T>) => void] {
  const fallback = useRef(initial);
  const value = useSelector(
    (state: ReturnType<typeof reduxStore.getState>) =>
      (state.business[key] ?? fallback.current) as T,
  );
  useEffect(() => {
    if (!(key in reduxStore.getState().business))
      reduxStore.dispatch(
        businessSlice.actions.setField({ key, value: fallback.current }),
      );
  }, [key]);
  const set = useCallback(
    (next: SetStateAction<T>) => {
      const previous = (reduxStore.getState().business[key] ??
        fallback.current) as T;
      const result =
        typeof next === "function" ? (next as (prev: T) => T)(previous) : next;
      reduxStore.dispatch(
        businessSlice.actions.setField({ key, value: result }),
      );
      if (key === "products")
        reduxStore.dispatch(demoApi.util.invalidateTags(["Products"]));
    },
    [key],
  );
  return [value, set];
}
export function liveField<T>(key: string, fallback: T): T {
  return (reduxStore.getState().business[key] ?? fallback) as T;
}
