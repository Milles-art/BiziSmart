export type Currency = "KES";

export type Role = "Cashier" | "Supervisor" | "Manager" | "Owner";

export type Permission =
  | "pos"
  | "products"
  | "inventory"
  | "suppliers"
  | "tills"
  | "customers"
  | "staff"
  | "reports"
  | "settings"
  | "refund"
  | "void"
  | "discount"
  | "approve"
  | "manage_till"
  | "price_change"
  | "stock_adjust"
  | "markdown";

export interface Product {
  id: string;
  name: string;
  barcode: string;
  category: string;
  department: string;
  price: number;
  cost: number;
  unit: "each" | "kg" | "pack" | "carton";
  stock: number;
  reorderLevel: number;
  expiry?: string;
  batch?: string;
  taxable: boolean;
  imageColor: string;
  packSize?: number;
  packPrice?: number;
  cartonSize?: number;
  cartonPrice?: number;
  weighed?: boolean;
  scaleBarcode?: string;
}

export interface CartItem {
  productId: string;
  name: string;
  price: number;
  qty: number;
  unit: string;
  discount: number;
  weighed: boolean;
  unitCost?: number;
  taxAmount?: number;
  returnedQty?: number;
}

export interface Customer {
  id: string;
  name: string;
  phone: string;
  email?: string;
  creditLimit: number;
  debt: number;
  totalSpent: number;
  joinedDate: string;
  loyaltyPoints: number;
}

export interface Supplier {
  id: string;
  name: string;
  contact: string;
  phone: string;
  email: string;
  balance: number;
  lastOrder: string;
  products: number;
  priceHistory: { productId: string; date: string; price: number }[];
}

export interface PurchaseOrderItem {
  productId: string;
  name: string;
  qty: number;
  cost: number;
  total: number;
}

export interface PurchaseOrder {
  id: string;
  supplierId: string;
  supplierName: string;
  date: string;
  expected: string;
  status: "draft" | "sent" | "received" | "partial";
  total: number;
  items: PurchaseOrderItem[];
  paymentTerms: string;
  paymentStatus: "unpaid" | "partial" | "paid";
  paymentDue?: string;
}

export interface Staff {
  id: string;
  name: string;
  role: Role;
  phone: string;
  pin: string;
  active: boolean;
  salesToday: number;
  attendance: "present" | "absent" | "leave";
  twoFactor: boolean;
  clockInTime?: string;
}

export interface CashDrop {
  id: string;
  tillId: string;
  amount: number;
  time: string;
  acknowledged: boolean;
  acknowledgedBy?: string;
}

export interface PaidOut {
  id: string;
  tillId: string;
  amount: number;
  reason: string;
  time: string;
  user: string;
}

export interface Till {
  id: string;
  name: string;
  cashierId: string | null;
  cashier: string;
  status: "open" | "closed" | "suspended";
  openingFloat: number;
  closingFloat: number | null;
  cashSales: number;
  mpesaSales: number;
  creditSales: number;
  transactions: number;
  voids: number;
  variance: number;
  cashDrops: CashDrop[];
  paidOuts: PaidOut[];
  openedAt: string | null;
}

export interface Transaction {
  id: string;
  receiptNo: string;
  time: string;
  date: string;
  cashierId: string;
  cashier: string;
  tillId: string;
  tillName: string;
  items: CartItem[];
  total: number;
  subtotal: number;
  tax: number;
  discount: number;
  method: "cash" | "mpesa" | "credit" | "split";
  cashAmount?: number;
  mpesaAmount?: number;
  mpesaRef?: string;
  customer?: string;
  customerId?: string;
  status: "completed" | "held" | "refunded" | "voided" | "partial_refund";
  refundAmount?: number;
  refundSubtotal?: number;
  refundTax?: number;
  refundCost?: number;
  refundCash?: number;
  refundMpesa?: number;
  exchangeReceiptId?: string;
  demoPayment?: boolean;
  cost: number;
}

export interface Approval {
  id: string;
  type:
    | "exchange"
    | "refund"
    | "void"
    | "discount"
    | "stock_adjust"
    | "price_change"
    | "markdown";
  description: string;
  amount?: number;
  requestedBy: string;
  requestedAt: string;
  status: "pending" | "approved" | "denied";
  approvedBy?: string;
  transactionId?: string;
  data?: Record<string, unknown>;
}

export interface StockMovement {
  id: string;
  productId: string;
  productName: string;
  type:
    | "Sale"
    | "Received"
    | "Wastage"
    | "Damage"
    | "Adjustment"
    | "Return"
    | "Transfer";
  qty: number;
  user: string;
  time: string;
  reason: string;
  approved: boolean;
}

export interface PriceChange {
  id: string;
  productId: string;
  productName: string;
  oldPrice: number;
  newPrice: number;
  date: string;
  user: string;
  scheduled?: string;
  applied: boolean;
}

export interface Promotion {
  id: string;
  name: string;
  type: "percentage" | "fixed" | "bxgy" | "bulk" | "time";
  value: number;
  buyQty?: number;
  getQty?: number;
  productId?: string;
  category?: string;
  active: boolean;
  startDate: string;
  endDate: string;
}

export interface AuditEntry {
  id: string;
  action: string;
  user: string;
  time: string;
  type:
    | "approval"
    | "void"
    | "discount"
    | "adjustment"
    | "cash"
    | "till"
    | "login"
    | "refund"
    | "price_change";
}

export interface MpesaPayment {
  id: string;
  phone: string;
  amount: number;
  ref: string;
  time: string;
  matched: boolean;
  transactionId?: string;
}

export interface HeldSale {
  id: string;
  items: CartItem[];
  time: string;
  total: number;
}

export interface StockTakeItem {
  productId: string;
  productName: string;
  systemQty: number;
  countedQty: number | null;
  variance: number;
}

export interface StockTake {
  id: string;
  department: string;
  countedBy: string;
  startedAt: string;
  status: "in_progress" | "completed";
  items: StockTakeItem[];
}

export interface SaleSummary {
  date: string;
  totalSales: number;
  transactions: number;
  avgBasket: number;
  cashSales: number;
  mpesaSales: number;
  creditSales: number;
  topProducts: { name: string; qty: number; revenue: number }[];
}

export type Screen =
  | "dashboard"
  | "pos"
  | "products"
  | "inventory"
  | "suppliers"
  | "tills"
  | "customers"
  | "staff"
  | "reports"
  | "settings";

export const ROLE_PERMISSIONS: Record<Role, Permission[]> = {
  Owner: [
    "pos",
    "products",
    "inventory",
    "suppliers",
    "tills",
    "customers",
    "staff",
    "reports",
    "settings",
    "refund",
    "void",
    "discount",
    "approve",
    "manage_till",
    "price_change",
    "stock_adjust",
    "markdown",
  ],
  Manager: [
    "pos",
    "products",
    "inventory",
    "suppliers",
    "tills",
    "customers",
    "staff",
    "reports",
    "refund",
    "void",
    "discount",
    "approve",
    "manage_till",
    "price_change",
    "stock_adjust",
    "markdown",
  ],
  Supervisor: [
    "pos",
    "products",
    "inventory",
    "suppliers",
    "tills",
    "customers",
    "reports",
    "void",
    "discount",
    "approve",
    "manage_till",
    "stock_adjust",
  ],
  Cashier: ["pos", "customers"],
};
