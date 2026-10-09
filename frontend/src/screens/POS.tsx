import { cartTotals } from "@/lib/business";
import {
  fullReturnLines,
  money,
  quantity,
  returnPlan,
  type ReturnLine,
} from "@/lib/business";
import type { Store as StoreType } from "@/store/useStore";
import { formatKES } from "@/store/useStore";
import type { CartItem } from "@/types";
import {
  ArrowLeftRight,
  CheckCircle,
  CreditCard,
  Minus,
  Monitor,
  Pause,
  Percent,
  Play,
  Plus,
  Printer,
  RefreshCw,
  RotateCcw,
  Scale,
  ScanLine,
  Search,
  ShoppingCart,
  Smartphone,
  SplitSquareHorizontal,
  Star,
  Tag,
  Trash2,
  User,
  Wallet,
  X,
} from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

interface Props {
  store: StoreType;
}

export function POS({ store }: Props) {
  const paymentTimer = useRef<ReturnType<typeof setTimeout>>();
  useEffect(() => () => clearTimeout(paymentTimer.current), []);
  const [search, setSearch] = useState("");
  const [activeCategory, setActiveCategory] = useState("All");
  const [showPayment, setShowPayment] = useState(false);
  const [paymentMethod, setPaymentMethod] = useState<
    "cash" | "mpesa" | "split" | "credit"
  >("cash");
  const [showBarcode, setShowBarcode] = useState(false);
  const [showDiscount, setShowDiscount] = useState(false);
  const [discountValue, setDiscountValue] = useState("");
  const [discountNotice, setDiscountNotice] = useState("");
  const [showCustomerDisplay, setShowCustomerDisplay] = useState(false);
  const [showRefund, setShowRefund] = useState(false);
  const [showReceipt, setShowReceipt] = useState(false);
  const [lastReceipt, setLastReceipt] = useState<{
    receiptNo: string;
    change: number;
    total: number;
    method: string;
    items: CartItem[];
  } | null>(null);
  const [showHeld, setShowHeld] = useState(false);
  const [showScale, setShowScale] = useState(false);
  const [scaleProduct, setScaleProduct] = useState<string | null>(null);
  const [scaleWeight, setScaleWeight] = useState("");
  const [showExchange, setShowExchange] = useState(false);

  // payment form state
  const [cashTendered, setCashTendered] = useState("");
  const [mpesaPhone, setMpesaPhone] = useState("");
  const [splitCash, setSplitCash] = useState("");
  const [splitMpesa, setSplitMpesa] = useState("");
  const [paymentError, setPaymentError] = useState("");
  const [mpesaStatus, setMpesaStatus] = useState<"idle" | "sending" | "sent">(
    "idle",
  );

  const filteredProducts = useMemo(() => {
    let result = store.products;
    if (activeCategory !== "All")
      result = result.filter((p) => p.category === activeCategory);
    if (search) {
      const q = search.toLowerCase();
      result = result.filter(
        (p) => p.name.toLowerCase().includes(q) || p.barcode.includes(q),
      );
    }
    return result;
  }, [search, activeCategory, store.products]);

  const { subtotal, tax, total } = cartTotals(store.cart, store.products);
  const discountTotal = store.cart.reduce((s, i) => s + i.discount, 0);

  // promo check
  const activePromos = store.promotions.filter((p) => p.active);
  const promoApplied = activePromos.find((p) => {
    if (p.type === "percentage" && p.category)
      return store.cart.some(
        (i) =>
          store.products.find((pr) => pr.id === i.productId)?.category ===
          p.category,
      );
    if (p.type === "bxgy" && p.productId) {
      const item = store.cart.find((i) => i.productId === p.productId);
      return item && item.qty >= (p.buyQty ?? 0);
    }
    if (p.type === "bulk" && p.productId) {
      const item = store.cart.find((i) => i.productId === p.productId);
      return item && item.qty >= (p.buyQty ?? 0);
    }
    return false;
  });

  const handleScan = () => {
    const code = search.trim();
    if (!code) return;
    const product = store.scanBarcode(code);
    if (product) {
      if (product.weighed) {
        setScaleProduct(product.id);
        setScaleWeight("");
        setShowScale(true);
      } else {
        store.addToCart(product.id);
      }
      setSearch("");
    }
  };

  const handleProductClick = (productId: string) => {
    const product = store.products.find((p) => p.id === productId);
    if (!product) return;
    if (product.weighed) {
      setScaleProduct(productId);
      setScaleWeight("");
      setShowScale(true);
    } else {
      store.addToCart(productId);
    }
  };

  const handleScaleConfirm = () => {
    if (!scaleProduct) return;
    const product = store.products.find((p) => p.id === scaleProduct);
    if (!product) return;
    const weight = parseFloat(scaleWeight) || 0;
    if (weight <= 0) return;
    store.addToCart(product.id, weight, product.price);
    setShowScale(false);
    setScaleProduct(null);
    setScaleWeight("");
  };

  const quickSellProducts =
    store.topProducts.length > 0
      ? store.topProducts.slice(0, 8).map((t) => t.product!)
      : store.products.filter((p) => !p.weighed).slice(0, 8);

  const handleApplyDiscount = () => {
    const pct = parseFloat(discountValue);
    if (isNaN(pct) || pct <= 0 || pct > 100) {
      setDiscountNotice("Enter a valid percentage 1-100");
      return;
    }
    const result = store.applyCartDiscount(pct);
    if (result.needsApproval) {
      setDiscountNotice(
        `${pct}% discount requested — supervisor must approve before it applies`,
      );
    } else {
      setDiscountNotice(`${pct}% discount applied to all items`);
    }
    setDiscountValue("");
    setTimeout(() => setDiscountNotice(""), 4000);
  };

  const handleCheckout = () => {
    setPaymentError("");
    setShowPayment(true);
    setPaymentMethod("cash");
    setCashTendered("");
    setMpesaPhone("");
    setSplitCash("");
    setSplitMpesa("");
    setMpesaStatus("idle");
  };

  const handleCompleteSale = () => {
    setPaymentError("");
    const details: Record<string, unknown> = {};
    if (paymentMethod === "cash")
      details.cashTendered = parseFloat(cashTendered) || 0;
    if (paymentMethod === "mpesa") {
      details.mpesaPhone = mpesaPhone;
      details.mpesaRef = `DEMO${Date.now().toString().slice(-7)}`;
    }
    if (paymentMethod === "split") {
      details.cashAmount = parseFloat(splitCash) || 0;
      details.mpesaAmount = parseFloat(splitMpesa) || 0;
      details.mpesaPhone = mpesaPhone;
      details.mpesaRef = `DEMO${Date.now().toString().slice(-7)}`;
    }
    if (paymentMethod === "credit")
      details.customerId = store.selectedCustomerId;

    if (paymentMethod === "mpesa") {
      setMpesaStatus("sending");
      paymentTimer.current = setTimeout(() => {
        setMpesaStatus("sent");
        const result = store.completeSale(
          paymentMethod,
          details as {
            cashTendered?: number;
            mpesaPhone?: string;
            mpesaRef?: string;
            customerId?: string;
            cashAmount?: number;
            mpesaAmount?: number;
          },
        );
        if (result.success) {
          setLastReceipt({
            receiptNo: result.receiptNo!,
            change: result.change ?? 0,
            total,
            method: paymentMethod,
            items: [...store.cart],
          });
          setShowPayment(false);
          setShowReceipt(true);
        } else {
          setPaymentError(result.error ?? "Payment failed");
          setMpesaStatus("idle");
        }
      }, 1500);
      return;
    }

    const result = store.completeSale(
      paymentMethod,
      details as {
        cashTendered?: number;
        mpesaPhone?: string;
        mpesaRef?: string;
        customerId?: string;
        cashAmount?: number;
        mpesaAmount?: number;
      },
    );
    if (result.success) {
      setLastReceipt({
        receiptNo: result.receiptNo!,
        change: result.change ?? 0,
        total,
        method: paymentMethod,
        items: [...store.cart],
      });
      setShowPayment(false);
      setShowReceipt(true);
    } else {
      setPaymentError(result.error ?? "Payment failed");
    }
  };

  const cashTenderedNum = parseFloat(cashTendered) || 0;
  const changeDue = paymentMethod === "cash" ? cashTenderedNum - total : 0;
  const splitTotal =
    (parseFloat(splitCash) || 0) + (parseFloat(splitMpesa) || 0);
  const splitRemaining = total - splitTotal;
  const selectedCustomer = store.customers.find(
    (c) => c.id === store.selectedCustomerId,
  );

  return (
    <div className="flex h-full flex-col lg:flex-row">
      {/* Product Selection */}
      <div className="flex flex-1 flex-col overflow-hidden border-r border-slate-200">
        <div className="space-y-3 border-b border-slate-200 bg-white p-4">
          <div className="flex gap-2">
            <div className="flex flex-1 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2.5">
              <Search className="h-5 w-5 text-slate-400" />
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") handleScan();
                }}
                placeholder="Search or scan barcode, then press Enter..."
                className="flex-1 bg-transparent text-sm text-slate-700 outline-none placeholder:text-slate-400"
              />
              {search && (
                <button onClick={() => setSearch("")}>
                  <X className="h-4 w-4 text-slate-400" />
                </button>
              )}
            </div>
            <button
              onClick={() => setShowBarcode(!showBarcode)}
              className={`flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${showBarcode ? "bg-emerald-500 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}
            >
              <ScanLine className="h-5 w-5" />
              <span className="hidden sm:inline">Scan</span>
            </button>
            {store.hasPermission("refund") && (
              <button
                onClick={() => setShowRefund(true)}
                className="flex items-center gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm font-medium text-amber-600 hover:bg-amber-100"
              >
                <RotateCcw className="h-5 w-5" />
                <span className="hidden sm:inline">Refund</span>
              </button>
            )}
            {store.hasPermission("refund") && (
              <button
                onClick={() => setShowExchange(true)}
                className="flex items-center gap-2 rounded-lg bg-blue-50 px-3 py-2.5 text-sm font-medium text-blue-600 hover:bg-blue-100"
              >
                <ArrowLeftRight className="h-5 w-5" />
                <span className="hidden sm:inline">Exchange</span>
              </button>
            )}
          </div>
          {showBarcode && (
            <div className="flex items-center gap-3 rounded-lg border-2 border-dashed border-emerald-300 bg-emerald-50 p-4">
              <ScanLine className="h-8 w-8 text-emerald-500" />
              <div className="flex-1">
                <p className="text-sm font-semibold text-emerald-700">
                  Scanner ready — type barcode + press Enter
                </p>
                <p className="text-xs text-emerald-600">
                  Supports USB scanners, keyboard wedge, and scale-label
                  barcodes (20-prefix)
                </p>
              </div>
              <div className="flex gap-0.5">
                {[3, 7, 2, 5, 9, 1, 6, 4, 8, 2].map((h, i) => (
                  <div
                    key={i}
                    className="w-0.5 bg-emerald-400"
                    style={{ height: `${h * 3 + 4}px` }}
                  />
                ))}
              </div>
            </div>
          )}
          <div className="flex gap-2 overflow-x-auto pb-1">
            {["All", ...store.categories].map((cat) => (
              <button
                key={cat}
                onClick={() => setActiveCategory(cat)}
                className={`whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${activeCategory === cat ? "bg-emerald-500 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}
              >
                {cat}
              </button>
            ))}
          </div>
        </div>
        {/* Quick-sell favorites bar */}
        {quickSellProducts.length > 0 && (
          <div className="border-b border-slate-100 bg-slate-50/50 px-4 py-2.5">
            <div className="mb-1.5 flex items-center gap-1.5">
              <Star className="h-3.5 w-3.5 text-amber-400" />
              <span className="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">
                Quick Sell
              </span>
            </div>
            <div className="flex gap-2 overflow-x-auto pb-1">
              {quickSellProducts.map((p) => (
                <button
                  key={p.id}
                  onClick={() => handleProductClick(p.id)}
                  disabled={p.stock <= 0}
                  className="flex shrink-0 flex-col items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-2 transition-all hover:border-emerald-400 hover:shadow-sm disabled:opacity-40"
                >
                  <div
                    className={`flex h-8 w-8 items-center justify-center rounded-md ${p.imageColor}`}
                  >
                    {p.weighed ? (
                      <Scale className="h-4 w-4 text-white/80" />
                    ) : (
                      <ShoppingCart className="h-4 w-4 text-white/80" />
                    )}
                  </div>
                  <span className="text-[10px] font-medium text-slate-600 max-w-[70px] truncate">
                    {p.name}
                  </span>
                  <span className="text-[11px] font-bold text-emerald-600">
                    {formatKES(p.price)}
                  </span>
                </button>
              ))}
            </div>
          </div>
        )}
        <div className="flex-1 overflow-y-auto p-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
            {filteredProducts.map((p) => (
              <button
                key={p.id}
                onClick={() => handleProductClick(p.id)}
                disabled={p.stock <= 0}
                className="group flex flex-col items-center rounded-xl border border-slate-200 bg-white p-3 text-center transition-all hover:border-emerald-400 hover:shadow-md disabled:opacity-40"
              >
                <div
                  className={`mb-2 flex h-14 w-14 items-center justify-center rounded-lg ${p.imageColor} transition-transform group-hover:scale-110`}
                >
                  {p.weighed ? (
                    <Tag className="h-6 w-6 text-white/80" />
                  ) : (
                    <ShoppingCart className="h-6 w-6 text-white/80" />
                  )}
                </div>
                <p className="line-clamp-2 text-xs font-medium text-slate-700">
                  {p.name}
                </p>
                <p className="mt-1 text-sm font-bold text-emerald-600">
                  {formatKES(p.price)}
                  {p.weighed ? "/kg" : ""}
                </p>
                <p
                  className={`text-[10px] ${p.stock <= p.reorderLevel ? "text-red-500 font-medium" : "text-slate-400"}`}
                >
                  {p.stock} {p.unit} left
                </p>
                {p.packSize && (
                  <p className="text-[9px] text-slate-300">
                    Pack: {p.packSize}× {formatKES(p.packPrice ?? 0)}
                  </p>
                )}
              </button>
            ))}
          </div>
          {filteredProducts.length === 0 && (
            <div className="flex flex-col items-center justify-center py-16 text-slate-400">
              <Search className="mb-3 h-12 w-12" />
              <p>No products found</p>
            </div>
          )}
        </div>
      </div>

      {/* Cart Panel */}
      <div className="flex w-full flex-col bg-white lg:w-[400px] xl:w-[440px]">
        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
          <div className="flex items-center gap-2">
            <ShoppingCart className="h-5 w-5 text-slate-600" />
            <h2 className="text-sm font-bold text-slate-800">Current Sale</h2>
            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700">
              {store.cart.length}
            </span>
          </div>
          <div className="flex items-center gap-1">
            {store.heldSales.length > 0 && (
              <button
                onClick={() => setShowHeld(true)}
                className="flex items-center gap-1 rounded-lg bg-blue-50 px-2.5 py-1.5 text-xs font-medium text-blue-600 hover:bg-blue-100"
              >
                <Play className="h-3.5 w-3.5" /> Held ({store.heldSales.length})
              </button>
            )}
            <button
              onClick={() => store.holdSale()}
              disabled={store.cart.length === 0}
              className="flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1.5 text-xs font-medium text-amber-600 hover:bg-amber-100 disabled:opacity-40"
            >
              <Pause className="h-3.5 w-3.5" /> Hold
            </button>
          </div>
        </div>

        {/* Customer selection */}
        <div className="border-b border-slate-100 px-4 py-2">
          <div className="flex items-center gap-2">
            <User className="h-4 w-4 text-slate-400" />
            <select
              value={store.selectedCustomerId ?? ""}
              onChange={(e) =>
                store.setSelectedCustomerId(e.target.value || null)
              }
              className="flex-1 rounded-md border border-slate-200 px-2 py-1.5 text-sm text-slate-600 outline-none"
            >
              <option value="">Walk-in customer</option>
              {store.customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name} {c.debt > 0 ? `· Debt: ${formatKES(c.debt)}` : ""}
                </option>
              ))}
            </select>
            <button
              onClick={() => setShowCustomerDisplay(!showCustomerDisplay)}
              className={`rounded-md p-1.5 ${showCustomerDisplay ? "bg-blue-100 text-blue-600" : "text-slate-400 hover:bg-slate-100"}`}
            >
              <Monitor className="h-4 w-4" />
            </button>
          </div>
        </div>

        {showCustomerDisplay && (
          <div className="border-b border-blue-100 bg-blue-50 p-4 text-center">
            <p className="text-xs text-blue-500">Customer-Facing Display</p>
            <p className="text-3xl font-bold text-blue-700">
              {formatKES(total)}
            </p>
            <p className="text-xs text-blue-500 mt-1">
              {store.cart.length} items
            </p>
          </div>
        )}

        <div className="flex-1 overflow-y-auto px-4 py-3">
          {store.cart.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-16 text-slate-300">
              <ShoppingCart className="mb-3 h-12 w-12" />
              <p className="text-sm text-slate-400">Cart is empty</p>
              <p className="text-xs text-slate-400">
                Tap products or scan barcodes to add
              </p>
            </div>
          ) : (
            <div className="space-y-2">
              {store.cart.map((item) => (
                <div
                  key={item.productId}
                  className="flex items-center gap-3 rounded-lg border border-slate-100 p-2.5 hover:border-slate-200"
                >
                  <div className="flex flex-1 items-center gap-2.5">
                    <div
                      className={`h-9 w-9 rounded-lg ${store.products.find((p) => p.id === item.productId)?.imageColor ?? "bg-slate-300"}`}
                    />
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-slate-700">
                        {item.name}
                      </p>
                      <div className="flex items-center gap-2">
                        <p className="text-xs text-slate-500">
                          {formatKES(item.price)}
                          {item.weighed ? " (weighed)" : ""}
                        </p>
                        {item.discount > 0 && (
                          <span className="text-xs text-red-500">
                            -{formatKES(item.discount)}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                  <div className="flex items-center gap-1.5">
                    <button
                      onClick={() => store.updateCartQty(item.productId, -1)}
                      disabled={item.weighed}
                      className="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-600 hover:bg-slate-200 disabled:opacity-30"
                    >
                      <Minus className="h-3.5 w-3.5" />
                    </button>
                    <span className="w-6 text-center text-sm font-semibold text-slate-700">
                      {item.qty}
                    </span>
                    <button
                      onClick={() => store.updateCartQty(item.productId, 1)}
                      disabled={item.weighed}
                      className="flex h-6 w-6 items-center justify-center rounded-md bg-slate-100 text-slate-600 hover:bg-slate-200 disabled:opacity-30"
                    >
                      <Plus className="h-3.5 w-3.5" />
                    </button>
                  </div>
                  <div className="w-20 text-right">
                    <p className="text-sm font-bold text-slate-800">
                      {formatKES(item.price * item.qty - item.discount)}
                    </p>
                  </div>
                  <button
                    onClick={() => store.removeFromCart(item.productId)}
                    className="text-slate-300 hover:text-red-500"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              ))}
              {promoApplied && (
                <div className="rounded-lg bg-emerald-50 p-2 text-xs text-emerald-600">
                  <span className="font-semibold">Promo active:</span>{" "}
                  {promoApplied.name}
                  {promoApplied.type === "bxgy" &&
                    ` — Buy ${promoApplied.buyQty} Get ${promoApplied.getQty} Free`}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Totals & Checkout */}
        <div className="border-t border-slate-200 p-4">
          <div className="mb-3 flex gap-2">
            <button
              onClick={() => setShowDiscount(!showDiscount)}
              className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200"
            >
              <Percent className="h-4 w-4" /> Discount
            </button>
            <button
              onClick={() => store.setSelectedCustomerId(null)}
              className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200"
            >
              <User className="h-4 w-4" /> Walk-in
            </button>
            <button
              onClick={() => setShowCustomerDisplay(!showCustomerDisplay)}
              className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200"
            >
              <Monitor className="h-4 w-4" /> Display
            </button>
          </div>
          {showDiscount && (
            <div className="mb-3">
              <div className="flex gap-2">
                <input
                  type="number"
                  value={discountValue}
                  onChange={(e) => setDiscountValue(e.target.value)}
                  placeholder="Discount %"
                  className="flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm outline-none focus:border-emerald-400"
                />
                <button
                  onClick={handleApplyDiscount}
                  className="rounded-lg bg-emerald-500 px-4 py-1.5 text-sm font-medium text-white hover:bg-emerald-600"
                >
                  Apply
                </button>
              </div>
              {discountNotice && (
                <p className="mt-1 text-xs text-amber-600">{discountNotice}</p>
              )}
            </div>
          )}
          <div className="space-y-1.5">
            {discountTotal > 0 && (
              <div className="flex justify-between text-sm text-red-500">
                <span>Discount</span>
                <span>-{formatKES(discountTotal)}</span>
              </div>
            )}
            <div className="flex justify-between text-sm text-slate-500">
              <span>Subtotal</span>
              <span className="font-medium text-slate-700">
                {formatKES(subtotal)}
              </span>
            </div>
            <div className="flex justify-between text-sm text-slate-500">
              <span>Tax (16% VAT)</span>
              <span className="font-medium text-slate-700">
                {formatKES(tax)}
              </span>
            </div>
            <div className="flex justify-between border-t border-slate-100 pt-2 text-base">
              <span className="font-bold text-slate-800">Total</span>
              <span className="font-bold text-emerald-600">
                {formatKES(total)}
              </span>
            </div>
          </div>
          <button
            onClick={handleCheckout}
            disabled={store.cart.length === 0}
            className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3.5 text-sm font-bold text-white shadow-sm transition-all hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <Wallet className="h-5 w-5" /> Checkout · {formatKES(total)}
          </button>
        </div>
      </div>

      {/* Payment Modal */}
      {showPayment && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => {
            clearTimeout(paymentTimer.current);
            setMpesaStatus("idle");
            setShowPayment(false);
          }}
        >
          <div
            className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-5 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">Payment</h2>
              <button
                onClick={() => {
                  clearTimeout(paymentTimer.current);
                  setMpesaStatus("idle");
                  setShowPayment(false);
                }}
                className="text-slate-400 hover:text-slate-600"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            <p className="mb-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">Simulation only · No real cash sale, STK push or M-Pesa confirmation is sent to a backend.</p>
            <div className="mb-5 rounded-xl bg-emerald-50 p-4 text-center">
              <p className="text-xs text-emerald-600">Amount Due</p>
              <p className="text-3xl font-bold text-emerald-700">
                {formatKES(total)}
              </p>
              {selectedCustomer && (
                <p className="mt-1 text-xs text-emerald-500">
                  Customer: {selectedCustomer.name}
                </p>
              )}
            </div>
            {paymentError && (
              <div className="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-600">
                {paymentError}
              </div>
            )}
            <div className="mb-4 grid grid-cols-2 gap-3">
              {(
                [
                  { id: "cash", label: "Cash", icon: Wallet },
                  { id: "mpesa", label: "M-Pesa", icon: Smartphone },
                  {
                    id: "split",
                    label: "Split Pay",
                    icon: SplitSquareHorizontal,
                  },
                  { id: "credit", label: "Credit", icon: CreditCard },
                ] as const
              ).map((m) => {
                const Icon = m.icon;
                return (
                  <button
                    key={m.id}
                    onClick={() => {
                      setPaymentMethod(m.id);
                      setPaymentError("");
                    }}
                    className={`flex flex-col items-center gap-2 rounded-xl border-2 py-4 transition-all ${paymentMethod === m.id ? "border-emerald-500 bg-emerald-50" : "border-slate-200 hover:border-slate-300"}`}
                  >
                    <Icon
                      className={`h-6 w-6 ${paymentMethod === m.id ? "text-emerald-600" : "text-slate-400"}`}
                    />
                    <span
                      className={`text-sm font-medium ${paymentMethod === m.id ? "text-emerald-700" : "text-slate-600"}`}
                    >
                      {m.label}
                    </span>
                  </button>
                );
              })}
            </div>

            {paymentMethod === "cash" && (
              <div className="space-y-3">
                <input
                  type="number"
                  value={cashTendered}
                  onChange={(e) => setCashTendered(e.target.value)}
                  placeholder="Amount tendered"
                  className="w-full rounded-lg border border-slate-200 px-4 py-3 text-lg font-semibold text-slate-800 outline-none focus:border-emerald-400"
                />
                <div className="grid grid-cols-4 gap-2">
                  {[100, 200, 500, 1000].map((amt) => (
                    <button
                      key={amt}
                      onClick={() =>
                        setCashTendered(
                          String((parseFloat(cashTendered) || 0) + amt),
                        )
                      }
                      className="rounded-lg bg-slate-100 py-2 text-sm font-medium text-slate-700 hover:bg-slate-200"
                    >
                      {amt}
                    </button>
                  ))}
                </div>
                {cashTenderedNum > 0 && (
                  <div className="flex items-center justify-between rounded-lg bg-emerald-50 p-3">
                    <span className="text-sm text-emerald-600">Change due</span>
                    <span className="text-lg font-bold text-emerald-700">
                      {formatKES(Math.max(0, changeDue))}
                    </span>
                  </div>
                )}
              </div>
            )}

            {paymentMethod === "mpesa" && (
              <div className="space-y-3">
                <input
                  type="tel"
                  value={mpesaPhone}
                  onChange={(e) => setMpesaPhone(e.target.value)}
                  placeholder="Customer M-Pesa phone (e.g. 0712345678)"
                  className="w-full rounded-lg border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-400"
                />
                {mpesaStatus === "idle" && (
                  <div className="rounded-lg bg-blue-50 p-3 text-center text-sm text-blue-600">
                    Demo payment only. No STK push or real M-Pesa confirmation.
                  </div>
                )}
                {mpesaStatus === "sending" && (
                  <div className="flex items-center justify-center gap-2 rounded-lg bg-blue-50 p-3 text-sm text-blue-600">
                    <div className="h-4 w-4 animate-spin rounded-full border-2 border-blue-300 border-t-blue-600" />{" "}
                    Simulating demo payment...
                  </div>
                )}
                {mpesaStatus === "sent" && (
                  <div className="flex items-center justify-center gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">
                    <CheckCircle className="h-4 w-4" /> Demo payment recorded
                  </div>
                )}
              </div>
            )}

            {paymentMethod === "split" && (
              <div className="space-y-3">
                <div className="flex gap-2">
                  <div className="flex-1">
                    <label className="mb-1 block text-xs text-slate-500">
                      Cash
                    </label>
                    <input
                      type="number"
                      value={splitCash}
                      onChange={(e) => setSplitCash(e.target.value)}
                      placeholder="0"
                      className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                    />
                  </div>
                  <div className="flex-1">
                    <label className="mb-1 block text-xs text-slate-500">
                      M-Pesa
                    </label>
                    <input
                      type="number"
                      value={splitMpesa}
                      onChange={(e) => setSplitMpesa(e.target.value)}
                      placeholder="0"
                      className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                    />
                  </div>
                </div>
                <div
                  className="flex items-center justify-between rounded-lg p-3 text-sm"
                  style={{
                    background: splitRemaining === 0 ? "#ecfdf5" : "#fffbeb",
                  }}
                >
                  <span
                    style={{
                      color: splitRemaining === 0 ? "#059669" : "#d97706",
                    }}
                  >
                    {splitRemaining === 0 ? "Fully covered" : "Remaining"}
                  </span>
                  <span
                    className="font-bold"
                    style={{
                      color: splitRemaining === 0 ? "#059669" : "#d97706",
                    }}
                  >
                    {formatKES(Math.abs(splitRemaining))}
                  </span>
                </div>
                {splitMpesa && (
                  <input
                    type="tel"
                    value={mpesaPhone}
                    onChange={(e) => setMpesaPhone(e.target.value)}
                    placeholder="M-Pesa phone (demo only; no real confirmation)"
                    className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
                  />
                )}
              </div>
            )}

            {paymentMethod === "credit" && (
              <div className="space-y-3">
                <select
                  value={store.selectedCustomerId ?? ""}
                  onChange={(e) => store.setSelectedCustomerId(e.target.value)}
                  className="w-full rounded-lg border border-slate-200 px-4 py-3 text-sm outline-none focus:border-emerald-400"
                >
                  <option value="">Select customer...</option>
                  {store.customers.map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name} · Debt: {formatKES(c.debt)} · Limit:{" "}
                      {formatKES(c.creditLimit)}
                    </option>
                  ))}
                </select>
                {selectedCustomer && (
                  <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-600">
                    Current debt: {formatKES(selectedCustomer.debt)} · Available
                    credit:{" "}
                    {formatKES(
                      selectedCustomer.creditLimit -
                        selectedCustomer.debt -
                        total,
                    )}
                    {selectedCustomer.debt + total >
                      selectedCustomer.creditLimit && (
                      <p className="mt-1 font-bold text-red-600">
                        Credit limit will be exceeded!
                      </p>
                    )}
                  </div>
                )}
              </div>
            )}

            <div className="mt-5 flex gap-3">
              <button
                onClick={() => {
                  clearTimeout(paymentTimer.current);
                  setMpesaStatus("idle");
                  setShowPayment(false);
                }}
                className="flex-1 rounded-xl border border-slate-200 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50"
              >
                Cancel
              </button>
              <button
                onClick={handleCompleteSale}
                disabled={mpesaStatus === "sending"}
                className="flex flex-[2] items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-50"
              >
                {mpesaStatus === "sending" ? (
                  <div className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                ) : (
                  <Printer className="h-4 w-4" />
                )}
                Complete & Print
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Receipt Modal */}
      {showReceipt && lastReceipt && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowReceipt(false)}
        >
          <div
            className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 text-center">
              <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                <CheckCircle className="h-7 w-7 text-emerald-600" />
              </div>
              <h2 className="text-lg font-bold text-slate-800">
                Sale Complete
              </h2>
              <p className="text-xs text-slate-400">{lastReceipt.receiptNo}</p>
            </div>
            <div className="rounded-xl border border-slate-200 p-4 font-mono text-xs text-slate-700">
              <p className="text-center font-bold">BiziSmart Supermarket</p>
              <p className="text-center font-bold text-amber-700">SIMULATED RECEIPT · No real payment processed</p>
              <p className="text-center text-slate-400">Moi Avenue, Nairobi</p>
              <p className="text-center text-slate-400">PIN: P051234567A</p>
              <div className="my-2 border-t border-dashed border-slate-200" />
              <div className="flex justify-between">
                <span>Receipt:</span>
                <span>{lastReceipt.receiptNo}</span>
              </div>
              <div className="flex justify-between">
                <span>Cashier:</span>
                <span>{store.currentUser?.name}</span>
              </div>
              <div className="flex justify-between">
                <span>Date:</span>
                <span>
                  {new Date().toLocaleDateString("en-KE", {
                    timeZone: "Africa/Nairobi",
                  })}
                </span>
              </div>
              <div className="flex justify-between">
                <span>Payment:</span>
                <span className="capitalize">{lastReceipt.method}</span>
              </div>
              <div className="my-2 border-t border-dashed border-slate-200" />
              {lastReceipt.items.map((item, idx) => (
                <div key={idx} className="flex justify-between py-0.5">
                  <span className="truncate pr-2">
                    {item.qty}× {item.name}
                    {item.weighed ? " (weighed)" : ""}
                  </span>
                  <span className="shrink-0 font-medium">
                    {formatKES(item.price * item.qty - item.discount)}
                  </span>
                </div>
              ))}
              {lastReceipt.items.length === 0 && (
                <p className="text-center text-slate-400">(no items)</p>
              )}
              <div className="my-2 border-t border-dashed border-slate-200" />
              <div className="flex justify-between font-bold">
                <span>Total:</span>
                <span>{formatKES(lastReceipt.total)}</span>
              </div>
              {lastReceipt.change > 0 && (
                <div className="flex justify-between">
                  <span>Change:</span>
                  <span>{formatKES(lastReceipt.change)}</span>
                </div>
              )}
              <div className="my-2 border-t border-dashed border-slate-200" />
              <p className="text-center">Thank you for shopping with us!</p>
              <p className="text-center text-slate-400">
                Goods once sold are not returnable
              </p>
            </div>
            <div className="mt-4 flex gap-2">
              <button className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-blue-50 py-2 text-xs font-medium text-blue-600 hover:bg-blue-100">
                <Smartphone className="h-3.5 w-3.5" /> WhatsApp
              </button>
              <button className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-slate-100 py-2 text-xs font-medium text-slate-600 hover:bg-slate-200">
                <Printer className="h-3.5 w-3.5" /> Print
              </button>
              <button
                onClick={() => setShowReceipt(false)}
                className="flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-emerald-500 py-2 text-xs font-bold text-white hover:bg-emerald-600"
              >
                <CheckCircle className="h-3.5 w-3.5" /> Done
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Held Sales Modal */}
      {showHeld && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
          onClick={() => setShowHeld(false)}
        >
          <div
            className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-lg font-bold text-slate-800">
                Held Sales ({store.heldSales.length})
              </h2>
              <button
                onClick={() => setShowHeld(false)}
                className="text-slate-400 hover:text-slate-600"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            {store.heldSales.length === 0 ? (
              <p className="py-8 text-center text-sm text-slate-400">
                No held sales
              </p>
            ) : (
              <div className="space-y-2">
                {store.heldSales.map((held) => (
                  <div
                    key={held.id}
                    className="flex items-center justify-between rounded-lg border border-slate-100 p-3"
                  >
                    <div>
                      <p className="text-sm font-medium text-slate-700">
                        {held.items.length} items · {formatKES(held.total)}
                      </p>
                      <p className="text-xs text-slate-400">
                        Held at {held.time}
                      </p>
                    </div>
                    <div className="flex gap-2">
                      <button
                        onClick={() => {
                          store.resumeSale(held.id);
                          setShowHeld(false);
                        }}
                        className="flex items-center gap-1 rounded-md bg-emerald-100 px-3 py-1.5 text-xs font-medium text-emerald-600 hover:bg-emerald-200"
                      >
                        <Play className="h-3 w-3" /> Resume
                      </button>
                      <button
                        onClick={() => store.deleteHeldSale(held.id)}
                        className="flex items-center gap-1 rounded-md bg-red-100 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-200"
                      >
                        <Trash2 className="h-3 w-3" /> Delete
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}

      {/* Scale / Weigh Modal */}
      {showScale && scaleProduct && (
        <ScaleModal
          product={store.products.find((p) => p.id === scaleProduct)!}
          weight={scaleWeight}
          setWeight={setScaleWeight}
          onConfirm={handleScaleConfirm}
          onClose={() => {
            setShowScale(false);
            setScaleProduct(null);
            setScaleWeight("");
          }}
        />
      )}

      {/* Exchange Modal */}
      {showExchange && (
        <ExchangeModal store={store} onClose={() => setShowExchange(false)} />
      )}

      {/* Refund Modal */}
      {showRefund && (
        <RefundModal store={store} onClose={() => setShowRefund(false)} />
      )}
    </div>
  );
}

function ScaleModal({
  product,
  weight,
  setWeight,
  onConfirm,
  onClose,
}: {
  product: {
    id: string;
    name: string;
    price: number;
    imageColor: string;
    unit: string;
  };
  weight: string;
  setWeight: (v: string) => void;
  onConfirm: () => void;
  onClose: () => void;
}) {
  const weightNum = parseFloat(weight) || 0;
  const totalPrice = money(product.price * weightNum);
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Scale className="h-5 w-5 text-emerald-500" />
            <h2 className="text-lg font-bold text-slate-800">Weigh Item</h2>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
        <div className="mb-4 flex items-center gap-3 rounded-xl bg-slate-50 p-3">
          <div
            className={`flex h-12 w-12 items-center justify-center rounded-lg ${product.imageColor}`}
          >
            <Scale className="h-6 w-6 text-white/80" />
          </div>
          <div>
            <p className="text-sm font-semibold text-slate-700">
              {product.name}
            </p>
            <p className="text-xs text-slate-500">
              {formatKES(product.price)} per kg
            </p>
          </div>
        </div>
        <div className="mb-3">
          <label className="mb-1 block text-xs font-medium text-slate-500">
            Enter weight from scale (kg)
          </label>
          <input
            type="number"
            step="0.001"
            min="0"
            value={weight}
            onChange={(e) => setWeight(e.target.value)}
            placeholder="0.000 kg"
            autoFocus
            className="w-full rounded-lg border-2 border-emerald-300 bg-emerald-50/30 px-4 py-3 text-lg font-semibold text-slate-800 outline-none focus:border-emerald-400"
          />
        </div>
        <div className="mb-4 grid grid-cols-4 gap-2">
          {[0.5, 1, 1.5, 2].map((w) => (
            <button
              key={w}
              onClick={() => setWeight(String(w))}
              className="rounded-lg bg-slate-100 py-2 text-sm font-medium text-slate-700 hover:bg-slate-200"
            >
              {w}kg
            </button>
          ))}
        </div>
        {weightNum > 0 && (
          <div className="mb-4 flex items-center justify-between rounded-xl bg-emerald-50 p-4">
            <div>
              <p className="text-xs text-emerald-600">
                {weightNum} kg × {formatKES(product.price)}/kg
              </p>
              <p className="text-lg font-bold text-emerald-700">
                {formatKES(totalPrice)}
              </p>
            </div>
            <CheckCircle className="h-8 w-8 text-emerald-400" />
          </div>
        )}
        <div className="flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 rounded-xl border border-slate-200 py-3 text-sm font-medium text-slate-600 hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            disabled={weightNum <= 0}
            className="flex flex-[2] items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-bold text-white hover:bg-emerald-600 disabled:opacity-40"
          >
            <ShoppingCart className="h-4 w-4" /> Add to Cart
          </button>
        </div>
      </div>
    </div>
  );
}

function ExchangeModal({
  store,
  onClose,
}: {
  store: StoreType;
  onClose: () => void;
}) {
  const [step, setStep] = useState<"select" | "returned" | "result">("select");
  const [selectedTxn, setSelectedTxn] = useState("");
  const [reason, setReason] = useState("");
  const [exchangeItems, setExchangeItems] = useState<CartItem[]>([]);
  const [exchangeSearch, setExchangeSearch] = useState("");
  const [cashTendered, setCashTendered] = useState("");
  const [exchangeError, setExchangeError] = useState("");
  const [settledDifference, setSettledDifference] = useState(0);
  const [replacementReceipt, setReplacementReceipt] = useState("");
  const [returnCondition, setReturnCondition] = useState<
    "sellable" | "damaged"
  >("sellable");

  const completedTxns = store.transactions.filter((t) =>
    ["completed", "partial_refund"].includes(t.status),
  );
  const txn = store.transactions.find((t) => t.id === selectedTxn);

  const originalTotal = txn ? money(txn.total - (txn.refundAmount ?? 0)) : 0;

  const newItems = store.cart;
  const { total: newTotal } = cartTotals(newItems, store.products);
  const difference = newTotal - originalTotal;

  const handleStartExchange = () => {
    if (!selectedTxn || !reason) return;
    const res = store.processExchange(selectedTxn, reason);
    if (!res.approved)
      setExchangeError(
        "Choose an eligible receipt with item details. Credit exchanges require a credit refund first.",
      );
    if (res.approved && res.items.length > 0) {
      if (store.cart.length) store.holdSale();
      setExchangeItems(res.items);
      store.clearCart();
      setStep("returned");
    }
  };

  const filteredNewProducts = exchangeSearch
    ? store.products.filter(
        (p) =>
          !p.weighed &&
          (p.name.toLowerCase().includes(exchangeSearch.toLowerCase()) ||
            p.barcode.includes(exchangeSearch)),
      )
    : store.products.filter((p) => !p.weighed).slice(0, 12);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl max-h-[90vh] overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <ArrowLeftRight className="h-5 w-5 text-blue-500" />
            <h2 className="text-lg font-bold text-slate-800">Exchange</h2>
          </div>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {exchangeError && (
          <p className="mb-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-700">
            {exchangeError}
          </p>
        )}
        {step === "select" && (
          <div className="space-y-4">
            <p className="text-sm text-slate-500">
              Select the original sale to exchange items from. The customer can
              then choose replacement items, and only pay the difference.
            </p>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Select Transaction
              </label>
              <select
                value={selectedTxn}
                onChange={(e) => setSelectedTxn(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              >
                <option value="">Choose a completed sale...</option>
                {completedTxns.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.receiptNo} · {t.time} · {formatKES(t.total)} ·{" "}
                    {t.cashier}
                  </option>
                ))}
              </select>
            </div>
            {txn && (
              <div className="rounded-lg bg-slate-50 p-3 text-sm">
                <div className="flex justify-between">
                  <span className="text-slate-500">Receipt</span>
                  <span className="text-slate-700">{txn.receiptNo}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Original Total</span>
                  <span className="text-slate-700">{formatKES(txn.total)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Customer</span>
                  <span className="text-slate-700">
                    {txn.customer ?? "Walk-in"}
                  </span>
                </div>
                {txn.items.length > 0 && (
                  <div className="mt-2 border-t border-slate-200 pt-2">
                    <p className="text-xs font-medium text-slate-400 mb-1">
                      Items in this sale:
                    </p>
                    {txn.items.map((item, idx) => (
                      <div key={idx} className="flex justify-between text-xs">
                        <span>
                          {item.qty}× {item.name}
                        </span>
                        <span>{formatKES(item.price * item.qty)}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Reason (required)
              </label>
              <select
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              >
                <option value="">Select reason...</option>
                <option>Defective product</option>
                <option>Wrong item sold</option>
                <option>Customer changed mind</option>
                <option>Damaged goods</option>
                <option>Size/color exchange</option>
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Returned stock condition
              </label>
              <select
                value={returnCondition}
                onChange={(e) =>
                  setReturnCondition(e.target.value as "sellable" | "damaged")
                }
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"
              >
                <option value="sellable">Sellable — restore inventory</option>
                <option value="damaged">
                  Damaged — record without restoring sellable stock
                </option>
              </select>
            </div>
            <button
              onClick={handleStartExchange}
              disabled={!selectedTxn || !reason}
              className="w-full rounded-xl bg-blue-500 py-2.5 text-sm font-bold text-white hover:bg-blue-600 disabled:opacity-40"
            >
              <RefreshCw className="mr-1 inline h-4 w-4" /> Select Returns &
              Start New Sale
            </button>
          </div>
        )}

        {step === "returned" && (
          <div className="space-y-4">
            <div className="rounded-lg bg-blue-50 p-3 text-sm text-blue-600">
              <CheckCircle className="mr-1 inline h-4 w-4" />
              Draft exchange. Stock and payment will change only when the
              exchange is completed or approved.
            </div>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {/* Returned items summary */}
              <div className="rounded-xl border border-slate-200 p-3">
                <p className="mb-2 text-xs font-semibold text-slate-400 uppercase">
                  Returned Items
                </p>
                {exchangeItems.map((item, idx) => (
                  <div key={idx} className="flex justify-between text-sm py-1">
                    <span className="truncate pr-2">
                      {item.qty}× {item.name}
                    </span>
                    <span className="shrink-0">
                      {formatKES(item.price * item.qty)}
                    </span>
                  </div>
                ))}
                <div className="mt-2 border-t border-slate-100 pt-2 flex justify-between font-bold">
                  <span>Original Total</span>
                  <span>{formatKES(originalTotal)}</span>
                </div>
              </div>
              {/* New cart */}
              <div className="rounded-xl border border-slate-200 p-3">
                <p className="mb-2 text-xs font-semibold text-slate-400 uppercase">
                  New Items
                </p>
                {newItems.length === 0 ? (
                  <p className="py-4 text-center text-xs text-slate-400">
                    No replacement items yet
                  </p>
                ) : (
                  newItems.map((item, idx) => (
                    <div
                      key={idx}
                      className="flex justify-between text-sm py-1"
                    >
                      <span className="truncate pr-2">
                        {item.qty}× {item.name}
                      </span>
                      <span className="shrink-0">
                        {formatKES(item.price * item.qty - item.discount)}
                      </span>
                    </div>
                  ))
                )}
                <div className="mt-2 border-t border-slate-100 pt-2 flex justify-between font-bold">
                  <span>New Total</span>
                  <span>{formatKES(newTotal)}</span>
                </div>
              </div>
            </div>

            {/* Product picker for replacements */}
            <div>
              <div className="mb-2 flex items-center gap-2 rounded-lg bg-slate-100 px-3 py-2">
                <Search className="h-4 w-4 text-slate-400" />
                <input
                  value={exchangeSearch}
                  onChange={(e) => setExchangeSearch(e.target.value)}
                  placeholder="Search products to add as replacements..."
                  className="flex-1 bg-transparent text-sm outline-none"
                />
              </div>
              <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                {filteredNewProducts.slice(0, 8).map((p) => (
                  <button
                    key={p.id}
                    onClick={() => store.addToCart(p.id)}
                    disabled={p.stock <= 0}
                    className="flex flex-col items-center rounded-lg border border-slate-200 p-2 text-center hover:border-emerald-400 disabled:opacity-40"
                  >
                    <div
                      className={`mb-1 flex h-8 w-8 items-center justify-center rounded-md ${p.imageColor}`}
                    >
                      <ShoppingCart className="h-4 w-4 text-white/80" />
                    </div>
                    <span className="text-[10px] font-medium text-slate-600 line-clamp-1">
                      {p.name}
                    </span>
                    <span className="text-[10px] font-bold text-emerald-600">
                      {formatKES(p.price)}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Cash received for difference (KSh)
              </label>
              <input
                type="number"
                min="0"
                value={cashTendered}
                onChange={(e) => setCashTendered(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              />
              <p className="mt-1 text-xs text-slate-400">
                A negative difference is refunded in cash at completion.
                Approval requests do not settle payment.
              </p>
            </div>
            {/* Difference summary */}
            <div
              className={`flex items-center justify-between rounded-xl p-4 ${difference > 0 ? "bg-amber-50" : difference < 0 ? "bg-emerald-50" : "bg-slate-50"}`}
            >
              <div>
                <p className="text-xs text-slate-500">
                  {difference > 0
                    ? "Customer pays"
                    : difference < 0
                      ? "Customer receives"
                      : "Even exchange"}
                </p>
                <p
                  className={`text-2xl font-bold ${difference > 0 ? "text-amber-600" : difference < 0 ? "text-emerald-600" : "text-slate-600"}`}
                >
                  {formatKES(Math.abs(difference))}
                </p>
              </div>
              <button
                onClick={() => {
                  const res = store.completeExchange(
                    selectedTxn,
                    reason,
                    Number(cashTendered) || 0,
                    txn
                      ? fullReturnLines(txn).map((l) => ({
                          ...l,
                          condition: returnCondition,
                        }))
                      : [],
                  );
                  if (res.success) {
                    setSettledDifference(res.difference ?? 0);
                    setReplacementReceipt(res.receiptNo ?? "");
                    setStep("result");
                  } else
                    setExchangeError(
                      res.error ?? "Exchange could not be completed",
                    );
                }}
                disabled={newItems.length === 0}
                className="rounded-xl bg-blue-500 px-6 py-3 text-sm font-bold text-white hover:bg-blue-600 disabled:opacity-40"
              >
                Complete Exchange
              </button>
            </div>
          </div>
        )}

        {step === "result" && (
          <div className="text-center py-6">
            <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
              <CheckCircle className="h-7 w-7 text-emerald-600" />
            </div>
            <p className="text-sm font-semibold text-slate-700">
              Exchange completed successfully
            </p>
            <p className="mt-1 text-xs text-slate-400">
              Replacement receipt {replacementReceipt}.{" "}
              {settledDifference > 0
                ? `Customer paid ${formatKES(settledDifference)}.`
                : settledDifference < 0
                  ? `Customer refunded ${formatKES(Math.abs(settledDifference))}.`
                  : "No balance due."}
            </p>
            <button
              onClick={onClose}
              className="mt-4 rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"
            >
              Close
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function RefundModal({
  store,
  onClose,
}: {
  store: StoreType;
  onClose: () => void;
}) {
  const [selectedTxn, setSelectedTxn] = useState<string>("");
  const [refundAmount, setRefundAmount] = useState("");
  const [returnLines, setReturnLines] = useState<ReturnLine[]>([]);
  const [refundError, setRefundError] = useState("");
  const [reason, setReason] = useState("");
  const [result, setResult] = useState<{
    needsApproval: boolean;
    approved: boolean;
  } | null>(null);

  const completedTxns = store.transactions.filter((t) =>
    ["completed", "partial_refund"].includes(t.status),
  );
  const txn = store.transactions.find((t) => t.id === selectedTxn);

  const handleRefund = () => {
    if (!selectedTxn || !reason) return;
    const amt = Number(refundAmount);
    const res = store.processRefund(
      selectedTxn,
      amt,
      reason,
      true,
      returnLines,
    );
    if (!res.approved && !res.needsApproval)
      setRefundError(
        "Check the amount, selected quantities and available till cash.",
      );
    else setResult(res);
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold text-slate-800">
            Process Refund / Exchange
          </h2>
          <button
            onClick={onClose}
            className="text-slate-400 hover:text-slate-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>
        {result ? (
          <div className="text-center py-4">
            {result.needsApproval ? (
              <>
                <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                  <RotateCcw className="h-7 w-7 text-amber-600" />
                </div>
                <p className="text-sm font-semibold text-slate-700">
                  Refund submitted for supervisor approval
                </p>
                <p className="mt-1 text-xs text-slate-400">
                  The request is available in the supervisor approval list.
                </p>
              </>
            ) : result.approved ? (
              <>
                <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100">
                  <CheckCircle className="h-7 w-7 text-emerald-600" />
                </div>
                <p className="text-sm font-semibold text-slate-700">
                  Refund processed successfully
                </p>
                <p className="mt-1 text-xs text-slate-400">
                  Selected returns and the demo payment reversal have been
                  recorded.
                </p>
              </>
            ) : null}
            <button
              onClick={onClose}
              className="mt-4 rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"
            >
              Close
            </button>
          </div>
        ) : (
          <div className="space-y-4">
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Select Transaction
              </label>
              <select
                value={selectedTxn}
                onChange={(e) => {
                  setSelectedTxn(e.target.value);
                  const next = store.transactions.find(
                    (t) => t.id === e.target.value,
                  );
                  setRefundAmount(
                    next
                      ? String(money(next.total - (next.refundAmount ?? 0)))
                      : "",
                  );
                  setReturnLines(next ? fullReturnLines(next) : []);
                  setRefundError("");
                }}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              >
                <option value="">Choose a completed sale...</option>
                {completedTxns.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.receiptNo} · {t.time} · {formatKES(t.total)} ·{" "}
                    {t.cashier}
                  </option>
                ))}
              </select>
            </div>
            {txn && (
              <div className="rounded-lg bg-slate-50 p-3 text-sm">
                <div className="flex justify-between">
                  <span className="text-slate-500">Receipt</span>
                  <span className="text-slate-700">{txn.receiptNo}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Original Total</span>
                  <span className="text-slate-700">{formatKES(txn.total)}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Payment Method</span>
                  <span className="capitalize text-slate-700">
                    {txn.method}
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-slate-500">Customer</span>
                  <span className="text-slate-700">
                    {txn.customer ?? "Walk-in"}
                  </span>
                </div>
              </div>
            )}
            <div>
              {txn && txn.items.length > 0 && (
                <div className="mb-3 space-y-2">
                  {txn.items.map((item, index) => (
                    <div
                      key={index}
                      className="flex items-center gap-2 text-xs"
                    >
                      <span className="flex-1 text-slate-600">
                        {item.name} (
                        {quantity(item.qty - (item.returnedQty ?? 0))}{" "}
                        {item.unit} left)
                      </span>
                      <input
                        aria-label={`Return quantity ${item.name}`}
                        type="number"
                        min="0"
                        max={quantity(item.qty - (item.returnedQty ?? 0))}
                        step={item.weighed ? "0.001" : "1"}
                        value={
                          returnLines.find((l) => l.index === index)?.qty ?? 0
                        }
                        onChange={(e) => {
                          const lines = [
                            ...returnLines.filter((l) => l.index !== index),
                            {
                              index,
                              qty: Number(e.target.value),
                              condition:
                                returnLines.find((l) => l.index === index)
                                  ?.condition ?? "sellable",
                            },
                          ].filter((l) => l.qty > 0);
                          setReturnLines(lines);
                          try {
                            setRefundAmount(
                              lines.length
                                ? String(returnPlan(txn, lines).amount)
                                : "",
                            );
                            setRefundError("");
                          } catch {
                            setRefundError("Invalid return quantity");
                          }
                        }}
                        className="w-16 rounded-lg border border-slate-200 px-2 py-1"
                      />
                      <select
                        aria-label={`Return condition ${item.name}`}
                        value={
                          returnLines.find((l) => l.index === index)
                            ?.condition ?? "sellable"
                        }
                        onChange={(e) =>
                          setReturnLines((prev) =>
                            prev.map((l) =>
                              l.index === index
                                ? {
                                    ...l,
                                    condition: e.target.value as
                                      "sellable" | "damaged",
                                  }
                                : l,
                            ),
                          )
                        }
                        className="rounded-lg border border-slate-200 px-1 py-1"
                      >
                        <option value="sellable">Sellable</option>
                        <option value="damaged">Damaged</option>
                      </select>
                    </div>
                  ))}
                </div>
              )}
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Refund Amount (KSh)
              </label>
              <input
                type="number"
                value={refundAmount}
                readOnly={returnLines.length > 0}
                onChange={(e) => setRefundAmount(e.target.value)}
                placeholder="Full or partial amount"
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-medium text-slate-500">
                Reason (required)
              </label>
              <select
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                className="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400"
              >
                <option value="">Select reason...</option>
                <option>Defective product</option>
                <option>Wrong item sold</option>
                <option>Customer changed mind</option>
                <option>Damaged goods</option>
                <option>Overcharged</option>
                <option>Expired product</option>
              </select>
            </div>
            <div className="rounded-lg bg-amber-50 p-3 text-xs text-amber-600">
              Select quantities to return. Only sellable returns increase stock.
              Set all quantities to zero for an amount-only price correction.
            </div>
            {refundError && (
              <p className="text-xs text-red-600">{refundError}</p>
            )}
            <button
              onClick={handleRefund}
              disabled={!selectedTxn || !reason}
              className="w-full rounded-xl bg-amber-500 py-2.5 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-40"
            >
              <RotateCcw className="mr-1 inline h-4 w-4" /> Process Refund
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
