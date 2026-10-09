import type {
  Approval,
  AuditEntry,
  CartItem,
  Customer,
  HeldSale,
  MpesaPayment,
  Permission,
  PriceChange,
  Product,
  Promotion,
  PurchaseOrder,
  PurchaseOrderItem,
  Role,
  Screen,
  Staff,
  StockMovement,
  StockTake,
  Supplier,
  Till,
  Transaction,
} from "@/types";
import { ROLE_PERMISSIONS } from "@/types";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  businessDate,
  cartTotals,
  expectedCash,
  fullReturnLines,
  money,
  netTransaction,
  quantity,
  returnPlan,
  saleItems,
  type ReturnLine,
} from "../lib/business";
import { liveField, useBusinessState, usePersistenceError } from "./redux";

// --- seed data ---
const colors = [
  "bg-rose-500",
  "bg-blue-500",
  "bg-emerald-500",
  "bg-amber-500",
  "bg-cyan-500",
  "bg-violet-500",
  "bg-orange-500",
  "bg-teal-500",
  "bg-pink-500",
  "bg-indigo-500",
  "bg-lime-500",
  "bg-sky-500",
];

const seedProducts: Product[] = [
  {
    id: "p1",
    name: "Sifted Maize Flour 2kg",
    barcode: "6001234500011",
    category: "Cereals",
    department: "Grocery",
    price: 185,
    cost: 165,
    unit: "each",
    stock: 240,
    reorderLevel: 50,
    taxable: true,
    imageColor: colors[0],
    packSize: 10,
    packPrice: 1750,
    cartonSize: 20,
    cartonPrice: 3300,
  },
  {
    id: "p2",
    name: "Sugar 1kg",
    barcode: "6001234500028",
    category: "Baking",
    department: "Grocery",
    price: 175,
    cost: 158,
    unit: "each",
    stock: 180,
    reorderLevel: 60,
    taxable: true,
    imageColor: colors[1],
    packSize: 12,
    packPrice: 1980,
    cartonSize: 24,
    cartonPrice: 3900,
  },
  {
    id: "p3",
    name: "Cooking Oil 1L",
    barcode: "6001234500035",
    category: "Cooking",
    department: "Grocery",
    price: 320,
    cost: 290,
    unit: "each",
    stock: 95,
    reorderLevel: 40,
    taxable: true,
    imageColor: colors[2],
    packSize: 12,
    packPrice: 3600,
    cartonSize: 24,
    cartonPrice: 6900,
  },
  {
    id: "p4",
    name: "Long Grain Rice 2kg",
    barcode: "6001234500042",
    category: "Cereals",
    department: "Grocery",
    price: 340,
    cost: 300,
    unit: "each",
    stock: 120,
    reorderLevel: 45,
    taxable: true,
    imageColor: colors[3],
  },
  {
    id: "p5",
    name: "Wheat Flour 1kg",
    barcode: "6001234500059",
    category: "Baking",
    department: "Grocery",
    price: 145,
    cost: 128,
    unit: "each",
    stock: 85,
    reorderLevel: 40,
    taxable: true,
    imageColor: colors[4],
  },
  {
    id: "p6",
    name: "Tea Leaves 500g",
    barcode: "6001234500066",
    category: "Beverages",
    department: "Grocery",
    price: 210,
    cost: 185,
    unit: "each",
    stock: 160,
    reorderLevel: 50,
    taxable: true,
    imageColor: colors[5],
  },
  {
    id: "p7",
    name: "Fresh Milk 500ml",
    barcode: "6001234500073",
    category: "Dairy",
    department: "Dairy",
    price: 65,
    cost: 55,
    unit: "each",
    stock: 48,
    reorderLevel: 30,
    expiry: "2026-10-14",
    batch: "MILK-102",
    taxable: false,
    imageColor: colors[6],
  },
  {
    id: "p8",
    name: "Bread Loaf 400g",
    barcode: "6001234500080",
    category: "Bakery",
    department: "Bakery",
    price: 55,
    cost: 45,
    unit: "each",
    stock: 32,
    reorderLevel: 20,
    expiry: "2026-10-10",
    batch: "BRD-088",
    taxable: false,
    imageColor: colors[7],
  },
  {
    id: "p9",
    name: "Eggs Tray (30)",
    barcode: "6001234500097",
    category: "Dairy",
    department: "Dairy",
    price: 420,
    cost: 380,
    unit: "pack",
    stock: 25,
    reorderLevel: 15,
    taxable: false,
    imageColor: colors[8],
  },
  {
    id: "p10",
    name: "Tomatoes 1kg",
    barcode: "2000000000017",
    category: "Vegetables",
    department: "Produce",
    price: 120,
    cost: 90,
    unit: "kg",
    stock: 60,
    reorderLevel: 20,
    taxable: false,
    imageColor: colors[9],
    weighed: true,
    scaleBarcode: "20",
  },
  {
    id: "p11",
    name: "Onions 1kg",
    barcode: "2000000000024",
    category: "Vegetables",
    department: "Produce",
    price: 100,
    cost: 75,
    unit: "kg",
    stock: 45,
    reorderLevel: 20,
    taxable: false,
    imageColor: colors[10],
    weighed: true,
    scaleBarcode: "20",
  },
  {
    id: "p12",
    name: "Potatoes 1kg",
    barcode: "2000000000031",
    category: "Vegetables",
    department: "Produce",
    price: 80,
    cost: 55,
    unit: "kg",
    stock: 70,
    reorderLevel: 25,
    taxable: false,
    imageColor: colors[11],
    weighed: true,
    scaleBarcode: "20",
  },
  {
    id: "p13",
    name: "Bananas 1kg",
    barcode: "2000000000048",
    category: "Fruits",
    department: "Produce",
    price: 110,
    cost: 85,
    unit: "kg",
    stock: 38,
    reorderLevel: 15,
    taxable: false,
    imageColor: colors[0],
    weighed: true,
    scaleBarcode: "20",
  },
  {
    id: "p14",
    name: "Mangoes 1kg",
    barcode: "2000000000055",
    category: "Fruits",
    department: "Produce",
    price: 150,
    cost: 115,
    unit: "kg",
    stock: 22,
    reorderLevel: 15,
    taxable: false,
    imageColor: colors[1],
    weighed: true,
    scaleBarcode: "20",
  },
  {
    id: "p15",
    name: "Coca Cola 500ml",
    barcode: "5449000000996",
    category: "Sodas",
    department: "Beverages",
    price: 70,
    cost: 55,
    unit: "each",
    stock: 200,
    reorderLevel: 60,
    taxable: true,
    imageColor: colors[2],
    packSize: 12,
    packPrice: 780,
    cartonSize: 24,
    cartonPrice: 1500,
  },
  {
    id: "p16",
    name: "Bottled Water 500ml",
    barcode: "5449000001009",
    category: "Water",
    department: "Beverages",
    price: 30,
    cost: 20,
    unit: "each",
    stock: 300,
    reorderLevel: 80,
    taxable: true,
    imageColor: colors[3],
  },
  {
    id: "p17",
    name: "Bar Soap 800g",
    barcode: "6001234500103",
    category: "Cleaning",
    department: "Household",
    price: 180,
    cost: 155,
    unit: "each",
    stock: 90,
    reorderLevel: 30,
    taxable: true,
    imageColor: colors[4],
  },
  {
    id: "p18",
    name: "Detergent 2kg",
    barcode: "6001234500110",
    category: "Cleaning",
    department: "Household",
    price: 350,
    cost: 300,
    unit: "each",
    stock: 55,
    reorderLevel: 25,
    taxable: true,
    imageColor: colors[5],
  },
  {
    id: "p19",
    name: "Salt 500g",
    barcode: "6001234500127",
    category: "Baking",
    department: "Grocery",
    price: 45,
    cost: 35,
    unit: "each",
    stock: 140,
    reorderLevel: 40,
    taxable: true,
    imageColor: colors[6],
  },
  {
    id: "p20",
    name: "Pasta 500g",
    barcode: "8076809513758",
    category: "Cereals",
    department: "Grocery",
    price: 95,
    cost: 78,
    unit: "each",
    stock: 110,
    reorderLevel: 35,
    taxable: true,
    imageColor: colors[7],
  },
  {
    id: "p21",
    name: "Peanut Butter 350g",
    barcode: "6001234500134",
    category: "Spreads",
    department: "Grocery",
    price: 280,
    cost: 240,
    unit: "each",
    stock: 42,
    reorderLevel: 20,
    taxable: true,
    imageColor: colors[8],
  },
  {
    id: "p22",
    name: "Tomato Sauce 500g",
    barcode: "6001234500141",
    category: "Sauces",
    department: "Grocery",
    price: 165,
    cost: 140,
    unit: "each",
    stock: 68,
    reorderLevel: 25,
    taxable: true,
    imageColor: colors[9],
  },
  {
    id: "p23",
    name: "Biscuits Pack",
    barcode: "6001234500158",
    category: "Snacks",
    department: "Bakery",
    price: 60,
    cost: 48,
    unit: "pack",
    stock: 130,
    reorderLevel: 40,
    expiry: "2026-12-01",
    batch: "BSC-205",
    taxable: true,
    imageColor: colors[10],
  },
  {
    id: "p24",
    name: "Toothpaste 100g",
    barcode: "6001234500165",
    category: "Personal Care",
    department: "Household",
    price: 190,
    cost: 165,
    unit: "each",
    stock: 75,
    reorderLevel: 25,
    taxable: true,
    imageColor: colors[11],
  },
  {
    id: "p25",
    name: "Toilet Paper 4-roll",
    barcode: "6001234500172",
    category: "Cleaning",
    department: "Household",
    price: 220,
    cost: 190,
    unit: "pack",
    stock: 85,
    reorderLevel: 30,
    taxable: true,
    imageColor: colors[0],
  },
  {
    id: "p26",
    name: "Matchbox Pack",
    barcode: "6001234500189",
    category: "Household",
    department: "Household",
    price: 25,
    cost: 18,
    unit: "pack",
    stock: 200,
    reorderLevel: 50,
    taxable: true,
    imageColor: colors[1],
  },
  {
    id: "p27",
    name: "Sardines Tin 155g",
    barcode: "6001234500196",
    category: "Canned",
    department: "Grocery",
    price: 85,
    cost: 70,
    unit: "each",
    stock: 95,
    reorderLevel: 30,
    expiry: "2027-06-01",
    batch: "SRD-310",
    taxable: true,
    imageColor: colors[2],
  },
  {
    id: "p28",
    name: "Baked Beans 400g",
    barcode: "6001234500202",
    category: "Canned",
    department: "Grocery",
    price: 120,
    cost: 100,
    unit: "each",
    stock: 60,
    reorderLevel: 25,
    expiry: "2027-03-01",
    batch: "BNZ-118",
    taxable: true,
    imageColor: colors[3],
  },
  {
    id: "p29",
    name: "Spices Mix 50g",
    barcode: "6001234500219",
    category: "Spices",
    department: "Grocery",
    price: 75,
    cost: 58,
    unit: "each",
    stock: 48,
    reorderLevel: 20,
    taxable: true,
    imageColor: colors[4],
  },
  {
    id: "p30",
    name: "Honey 250g",
    barcode: "6001234500226",
    category: "Spreads",
    department: "Grocery",
    price: 350,
    cost: 300,
    unit: "each",
    stock: 28,
    reorderLevel: 15,
    taxable: true,
    imageColor: colors[5],
  },
  {
    id: "p31",
    name: "Groundnuts 500g",
    barcode: "6001234500233",
    category: "Snacks",
    department: "Grocery",
    price: 200,
    cost: 170,
    unit: "pack",
    stock: 52,
    reorderLevel: 20,
    taxable: true,
    imageColor: colors[6],
  },
  {
    id: "p32",
    name: "Orange Juice 1L",
    barcode: "6001234500240",
    category: "Juices",
    department: "Beverages",
    price: 180,
    cost: 150,
    unit: "each",
    stock: 65,
    reorderLevel: 25,
    expiry: "2026-10-20",
    batch: "OJ-045",
    taxable: true,
    imageColor: colors[7],
  },
  {
    id: "p33",
    name: "Yoghurt 500ml",
    barcode: "6001234500257",
    category: "Dairy",
    department: "Dairy",
    price: 120,
    cost: 100,
    unit: "each",
    stock: 40,
    reorderLevel: 20,
    expiry: "2026-10-12",
    batch: "YOG-077",
    taxable: false,
    imageColor: colors[8],
  },
  {
    id: "p34",
    name: "Cheese 200g",
    barcode: "6001234500264",
    category: "Dairy",
    department: "Dairy",
    price: 380,
    cost: 330,
    unit: "each",
    stock: 18,
    reorderLevel: 10,
    expiry: "2026-11-01",
    batch: "CHS-012",
    taxable: false,
    imageColor: colors[9],
  },
  {
    id: "p35",
    name: "Sausages 500g",
    barcode: "6001234500271",
    category: "Meat",
    department: "Butchery",
    price: 350,
    cost: 300,
    unit: "pack",
    stock: 30,
    reorderLevel: 15,
    expiry: "2026-10-11",
    batch: "SAU-066",
    taxable: false,
    imageColor: colors[10],
  },
  {
    id: "p36",
    name: "Chicken Whole 1kg",
    barcode: "2000000000062",
    category: "Meat",
    department: "Butchery",
    price: 480,
    cost: 420,
    unit: "kg",
    stock: 25,
    reorderLevel: 10,
    taxable: false,
    imageColor: colors[11],
    weighed: true,
    scaleBarcode: "20",
  },
];

const seedCustomers: Customer[] = [
  {
    id: "c1",
    name: "Wanjiru Kamau",
    phone: "0712345678",
    email: "wanjiru@gmail.com",
    creditLimit: 5000,
    debt: 1200,
    totalSpent: 28500,
    joinedDate: "2025-03-15",
    loyaltyPoints: 285,
  },
  {
    id: "c2",
    name: "Otieno Ochieng",
    phone: "0723456789",
    creditLimit: 10000,
    debt: 0,
    totalSpent: 52000,
    joinedDate: "2024-11-20",
    loyaltyPoints: 520,
  },
  {
    id: "c3",
    name: "Amina Hassan",
    phone: "0734567890",
    creditLimit: 3000,
    debt: 2800,
    totalSpent: 15800,
    joinedDate: "2025-06-01",
    loyaltyPoints: 158,
  },
  {
    id: "c4",
    name: "Mutua Mutua",
    phone: "0745678901",
    creditLimit: 8000,
    debt: 500,
    totalSpent: 41200,
    joinedDate: "2025-01-10",
    loyaltyPoints: 412,
  },
  {
    id: "c5",
    name: "Faith Cherono",
    phone: "0756789012",
    creditLimit: 2000,
    debt: 0,
    totalSpent: 9800,
    joinedDate: "2025-08-05",
    loyaltyPoints: 98,
  },
  {
    id: "c6",
    name: "Brian Kiplagat",
    phone: "0767890123",
    creditLimit: 15000,
    debt: 3500,
    totalSpent: 67500,
    joinedDate: "2024-08-12",
    loyaltyPoints: 675,
  },
  {
    id: "c7",
    name: "Grace Auma",
    phone: "0778901234",
    creditLimit: 5000,
    debt: 0,
    totalSpent: 22300,
    joinedDate: "2025-04-22",
    loyaltyPoints: 223,
  },
  {
    id: "c8",
    name: "Samuel Mwangi",
    phone: "0789012345",
    creditLimit: 10000,
    debt: 1800,
    totalSpent: 38900,
    joinedDate: "2025-02-18",
    loyaltyPoints: 389,
  },
];

const seedSuppliers: Supplier[] = [
  {
    id: "s1",
    name: "Bidco Africa Ltd",
    contact: "James Mwangi",
    phone: "0722001100",
    email: "sales@bidco.africa",
    balance: 45000,
    lastOrder: "2026-09-28",
    products: 12,
    priceHistory: [
      { productId: "p3", date: "2026-09-15", price: 285 },
      { productId: "p3", date: "2026-09-28", price: 290 },
    ],
  },
  {
    id: "s2",
    name: "Unilever Kenya",
    contact: "Sarah Njoki",
    phone: "0733002200",
    email: "orders@unilever.co.ke",
    balance: 0,
    lastOrder: "2026-09-25",
    products: 8,
    priceHistory: [],
  },
  {
    id: "s3",
    name: "Eastleigh Wholesalers",
    contact: "Abdi Noor",
    phone: "0744003300",
    email: "eastleigh@gmail.com",
    balance: 12500,
    lastOrder: "2026-10-02",
    products: 25,
    priceHistory: [
      { productId: "p2", date: "2026-09-20", price: 155 },
      { productId: "p2", date: "2026-10-02", price: 158 },
    ],
  },
  {
    id: "s4",
    name: "Nairobi Drinks Ltd",
    contact: "Peter Otieno",
    phone: "0755004400",
    email: "info@nairobidrinks.co.ke",
    balance: 8200,
    lastOrder: "2026-09-30",
    products: 15,
    priceHistory: [],
  },
  {
    id: "s5",
    name: "Fresh Farm Produce",
    contact: "Mary Wambui",
    phone: "0766005500",
    email: "fresh@farm.co.ke",
    balance: 0,
    lastOrder: "2026-10-05",
    products: 18,
    priceHistory: [],
  },
  {
    id: "s6",
    name: "Tuskys Distributors",
    contact: "David Kiprop",
    phone: "0777006600",
    email: "dist@tuskys.co.ke",
    balance: 23000,
    lastOrder: "2026-09-20",
    products: 30,
    priceHistory: [],
  },
];

const seedStaff: Staff[] = [
  {
    id: "st1",
    name: "John Mwangi",
    role: "Owner",
    phone: "0711001100",
    pin: "1234",
    active: true,
    salesToday: 0,
    attendance: "present",
    twoFactor: true,
    clockInTime: "08:00",
  },
  {
    id: "st2",
    name: "Lucy Wambui",
    role: "Manager",
    phone: "0711002200",
    pin: "2345",
    active: true,
    salesToday: 0,
    attendance: "present",
    twoFactor: true,
    clockInTime: "08:00",
  },
  {
    id: "st3",
    name: "Peter Kamau",
    role: "Supervisor",
    phone: "0711003300",
    pin: "3456",
    active: true,
    salesToday: 0,
    attendance: "present",
    twoFactor: false,
    clockInTime: "08:00",
  },
  {
    id: "st4",
    name: "Mary Atieno",
    role: "Cashier",
    phone: "0711004400",
    pin: "4567",
    active: true,
    salesToday: 18500,
    attendance: "present",
    twoFactor: false,
    clockInTime: "08:00",
  },
  {
    id: "st5",
    name: "James Omondi",
    role: "Cashier",
    phone: "0711005500",
    pin: "5678",
    active: true,
    salesToday: 12300,
    attendance: "present",
    twoFactor: false,
    clockInTime: "08:00",
  },
  {
    id: "st6",
    name: "Faith Njeri",
    role: "Cashier",
    phone: "0711006600",
    pin: "6789",
    active: true,
    salesToday: 22000,
    attendance: "present",
    twoFactor: false,
    clockInTime: "08:00",
  },
  {
    id: "st7",
    name: "Brian Kiptoo",
    role: "Cashier",
    phone: "0711007700",
    pin: "7890",
    active: false,
    salesToday: 0,
    attendance: "absent",
    twoFactor: false,
  },
  {
    id: "st8",
    name: "Sarah Wanjiru",
    role: "Cashier",
    phone: "0711008800",
    pin: "8901",
    active: true,
    salesToday: 9500,
    attendance: "present",
    twoFactor: false,
    clockInTime: "08:00",
  },
];

const seedTills: Till[] = [
  {
    id: "t1",
    name: "Till 1",
    cashierId: "st4",
    cashier: "Mary Atieno",
    status: "open",
    openingFloat: 5000,
    closingFloat: null,
    cashSales: 8500,
    mpesaSales: 10000,
    creditSales: 0,
    transactions: 34,
    voids: 1,
    variance: 0,
    cashDrops: [],
    paidOuts: [],
    openedAt: "08:00",
  },
  {
    id: "t2",
    name: "Till 2",
    cashierId: "st5",
    cashier: "James Omondi",
    status: "open",
    openingFloat: 5000,
    closingFloat: null,
    cashSales: 5300,
    mpesaSales: 7000,
    creditSales: 0,
    transactions: 22,
    voids: 0,
    variance: 0,
    cashDrops: [],
    paidOuts: [],
    openedAt: "08:00",
  },
  {
    id: "t3",
    name: "Till 3",
    cashierId: "st6",
    cashier: "Faith Njeri",
    status: "open",
    openingFloat: 5000,
    closingFloat: null,
    cashSales: 12000,
    mpesaSales: 10000,
    creditSales: 1850,
    transactions: 45,
    voids: 2,
    variance: -150,
    cashDrops: [],
    paidOuts: [],
    openedAt: "08:00",
  },
  {
    id: "t4",
    name: "Till 4",
    cashierId: "st8",
    cashier: "Sarah Wanjiru",
    status: "open",
    openingFloat: 5000,
    closingFloat: null,
    cashSales: 4500,
    mpesaSales: 5000,
    creditSales: 0,
    transactions: 18,
    voids: 0,
    variance: 0,
    cashDrops: [],
    paidOuts: [],
    openedAt: "08:00",
  },
  {
    id: "t5",
    name: "Till 5",
    cashierId: null,
    cashier: "—",
    status: "closed",
    openingFloat: 0,
    closingFloat: null,
    cashSales: 0,
    mpesaSales: 0,
    creditSales: 0,
    transactions: 0,
    voids: 0,
    variance: 0,
    cashDrops: [],
    paidOuts: [],
    openedAt: null,
  },
];

const now = () =>
  new Date().toLocaleTimeString("en-US", {
    timeZone: "Africa/Nairobi",
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  });
const today = businessDate;

const seedTxns: Transaction[] = [
  {
    id: "tx1",
    receiptNo: "RCP-001234",
    time: "10:42 AM",
    date: "2026-10-08",
    cashierId: "st4",
    cashier: "Mary Atieno",
    tillId: "t1",
    tillName: "Till 1",
    items: [],
    total: 1250,
    subtotal: 1078,
    tax: 172,
    discount: 0,
    method: "mpesa",
    mpesaAmount: 1250,
    mpesaRef: "QGH8X9P2",
    customer: "Wanjiru Kamau",
    customerId: "c1",
    status: "completed",
    cost: 950,
  },
  {
    id: "tx2",
    receiptNo: "RCP-001235",
    time: "10:38 AM",
    date: "2026-10-08",
    cashierId: "st5",
    cashier: "James Omondi",
    tillId: "t2",
    tillName: "Till 2",
    items: [],
    total: 680,
    subtotal: 586,
    tax: 94,
    discount: 0,
    method: "cash",
    cashAmount: 680,
    status: "completed",
    cost: 510,
  },
  {
    id: "tx3",
    receiptNo: "RCP-001236",
    time: "10:35 AM",
    date: "2026-10-08",
    cashierId: "st6",
    cashier: "Faith Njeri",
    tillId: "t3",
    tillName: "Till 3",
    items: [],
    total: 3200,
    subtotal: 2759,
    tax: 441,
    discount: 0,
    method: "split",
    cashAmount: 1200,
    mpesaAmount: 2000,
    customer: "Otieno Ochieng",
    customerId: "c2",
    status: "completed",
    cost: 2450,
  },
  {
    id: "tx4",
    receiptNo: "RCP-001237",
    time: "10:28 AM",
    date: "2026-10-08",
    cashierId: "st4",
    cashier: "Mary Atieno",
    tillId: "t1",
    tillName: "Till 1",
    items: [],
    total: 0,
    subtotal: 0,
    tax: 0,
    discount: 0,
    method: "cash",
    status: "voided",
    cost: 0,
  },
  {
    id: "tx5",
    receiptNo: "RCP-001238",
    time: "10:22 AM",
    date: "2026-10-08",
    cashierId: "st8",
    cashier: "Sarah Wanjiru",
    tillId: "t4",
    tillName: "Till 4",
    items: [],
    total: 450,
    subtotal: 388,
    tax: 62,
    discount: 0,
    method: "cash",
    cashAmount: 450,
    status: "completed",
    cost: 340,
  },
  {
    id: "tx6",
    receiptNo: "RCP-001239",
    time: "10:15 AM",
    date: "2026-10-08",
    cashierId: "st6",
    cashier: "Faith Njeri",
    tillId: "t3",
    tillName: "Till 3",
    items: [],
    total: 1850,
    subtotal: 1850,
    tax: 0,
    discount: 0,
    method: "credit",
    customer: "Amina Hassan",
    customerId: "c3",
    status: "completed",
    cost: 1520,
  },
  {
    id: "tx11",
    receiptNo: "RCP-001244",
    time: "09:22 AM",
    date: "2026-10-08",
    cashierId: "st4",
    cashier: "Mary Atieno",
    tillId: "t1",
    tillName: "Till 1",
    items: [],
    total: 980,
    subtotal: 845,
    tax: 135,
    discount: 0,
    method: "cash",
    cashAmount: 980,
    customer: "Mutua Mutua",
    customerId: "c4",
    status: "refunded",
    refundAmount: 980,
    cost: 720,
  },
];

const seedAudit: AuditEntry[] = [
  {
    id: "al1",
    action: "Approved refund RCP-001244",
    user: "Peter Kamau",
    time: "10:15 AM",
    type: "approval",
  },
  {
    id: "al2",
    action: "Voided transaction RCP-001237",
    user: "Peter Kamau",
    time: "10:28 AM",
    type: "void",
  },
  {
    id: "al3",
    action: "Applied 15% discount on order",
    user: "Peter Kamau",
    time: "09:45 AM",
    type: "discount",
  },
  {
    id: "al4",
    action: "Stock adjustment: Bread Loaf (-2)",
    user: "Lucy Wambui",
    time: "08:30 AM",
    type: "adjustment",
  },
  {
    id: "al5",
    action: "Opened Till 3 with float KSh 5,000",
    user: "Faith Njeri",
    time: "07:30 AM",
    type: "till",
  },
];

const seedMovements: StockMovement[] = [
  {
    id: "m1",
    productId: "p2",
    productName: "Sugar 1kg",
    type: "Sale",
    qty: -5,
    user: "Mary Atieno",
    time: "10:42 AM",
    reason: "",
    approved: true,
  },
  {
    id: "m2",
    productId: "p3",
    productName: "Cooking Oil 1L",
    type: "Received",
    qty: 48,
    user: "Lucy Wambui",
    time: "09:15 AM",
    reason: "PO-001 delivery",
    approved: true,
  },
  {
    id: "m3",
    productId: "p7",
    productName: "Fresh Milk 500ml",
    type: "Wastage",
    qty: -3,
    user: "Peter Kamau",
    time: "08:50 AM",
    reason: "Expired stock",
    approved: true,
  },
  {
    id: "m4",
    productId: "p8",
    productName: "Bread Loaf 400g",
    type: "Damage",
    qty: -2,
    user: "Mary Atieno",
    time: "08:30 AM",
    reason: "Crushed during delivery",
    approved: true,
  },
  {
    id: "m5",
    productId: "p6",
    productName: "Tea Leaves 500g",
    type: "Adjustment",
    qty: 12,
    user: "Lucy Wambui",
    time: "08:00 AM",
    reason: "Stock take correction",
    approved: true,
  },
];

const seedApprovals: Approval[] = [
  {
    id: "ap1",
    type: "refund",
    description: "Refund RCP-001244 · 5 items",
    amount: 980,
    requestedBy: "Mary Atieno",
    requestedAt: "10:10 AM",
    status: "pending",
    transactionId: "tx11",
  },
];

const seedPromotions: Promotion[] = [
  {
    id: "promo1",
    name: "Weekend Sodas 10% Off",
    type: "percentage",
    value: 10,
    category: "Sodas",
    active: true,
    startDate: "2026-10-07",
    endDate: "2026-10-09",
  },
  {
    id: "promo2",
    name: "Buy 2 Bread Get 1 Free",
    type: "bxgy",
    value: 0,
    buyQty: 2,
    getQty: 1,
    productId: "p8",
    active: true,
    startDate: "2026-10-08",
    endDate: "2026-10-10",
  },
  {
    id: "promo3",
    name: "Bulk Rice 5+ 5% Off",
    type: "bulk",
    value: 5,
    buyQty: 5,
    productId: "p4",
    active: true,
    startDate: "2026-10-01",
    endDate: "2026-10-31",
  },
];

const seedPriceChanges: PriceChange[] = [
  {
    id: "pc1",
    productId: "p3",
    productName: "Cooking Oil 1L",
    oldPrice: 310,
    newPrice: 320,
    date: "2026-10-01",
    user: "Lucy Wambui",
    applied: true,
  },
  {
    id: "pc2",
    productId: "p2",
    productName: "Sugar 1kg",
    oldPrice: 170,
    newPrice: 175,
    date: "2026-10-03",
    user: "Lucy Wambui",
    applied: true,
  },
];

const seedMpesa: MpesaPayment[] = [
  {
    id: "mp1",
    phone: "0712345678",
    amount: 1250,
    ref: "QGH8X9P2",
    time: "10:42 AM",
    matched: true,
    transactionId: "tx1",
  },
  {
    id: "mp2",
    phone: "0722001100",
    amount: 500,
    ref: "SDK4M1N8",
    time: "10:50 AM",
    matched: false,
  },
];

const seedStockTake: StockTake = {
  id: "st-1",
  department: "Grocery",
  countedBy: "Lucy Wambui",
  startedAt: "Oct 8, 08:00 AM",
  status: "in_progress",
  items: seedProducts
    .filter((p) => p.department === "Grocery")
    .slice(0, 8)
    .map((p) => ({
      productId: p.id,
      productName: p.name,
      systemQty: p.stock,
      countedQty: null,
      variance: 0,
    })),
};

// --- store hook ---
export function useStore() {
  const persistenceError = usePersistenceError();
  const [currentUser, setCurrentUser] = useState<Staff | null>(null);
  const [screen, setScreen] = useState<Screen>("dashboard");
  const [products, setProducts] = useBusinessState<Product[]>(
    "products",
    seedProducts,
  );
  const [customers, setCustomers] = useBusinessState<Customer[]>(
    "customers",
    seedCustomers,
  );
  const [suppliers, setSuppliers] = useBusinessState<Supplier[]>(
    "suppliers",
    seedSuppliers,
  );
  const [purchaseOrders, setPurchaseOrders] = useBusinessState<PurchaseOrder[]>(
    "purchaseOrders",
    [
      {
        id: "PO-001",
        supplierId: "s1",
        supplierName: "Bidco Africa Ltd",
        date: "2026-10-01",
        expected: "2026-10-05",
        status: "received",
        total: 89000,
        items: [
          {
            productId: "p3",
            name: "Cooking Oil 1L",
            qty: 48,
            cost: 290,
            total: 13920,
          },
        ],
        paymentTerms: "30 Days",
        paymentStatus: "partial",
      },
      {
        id: "PO-002",
        supplierId: "s3",
        supplierName: "Eastleigh Wholesalers",
        date: "2026-10-03",
        expected: "2026-10-07",
        status: "sent",
        total: 45000,
        items: [
          {
            productId: "p2",
            name: "Sugar 1kg",
            qty: 100,
            cost: 158,
            total: 15800,
          },
        ],
        paymentTerms: "7 Days",
        paymentStatus: "unpaid",
      },
      {
        id: "PO-003",
        supplierId: "s5",
        supplierName: "Fresh Farm Produce",
        date: "2026-10-05",
        expected: "2026-10-06",
        status: "received",
        total: 18000,
        items: [],
        paymentTerms: "COD",
        paymentStatus: "paid",
      },
      {
        id: "PO-004",
        supplierId: "s4",
        supplierName: "Nairobi Drinks Ltd",
        date: "2026-10-06",
        expected: "2026-10-09",
        status: "partial",
        total: 32000,
        items: [],
        paymentTerms: "7 Days",
        paymentStatus: "unpaid",
      },
      {
        id: "PO-005",
        supplierId: "s2",
        supplierName: "Unilever Kenya",
        date: "2026-10-07",
        expected: "2026-10-11",
        status: "draft",
        total: 25000,
        items: [],
        paymentTerms: "30 Days",
        paymentStatus: "unpaid",
      },
    ],
  );
  const [staffList, setStaffList] = useBusinessState<Staff[]>(
    "staffList",
    seedStaff,
  );
  const [tills, setTills] = useBusinessState<Till[]>("tills", seedTills);
  const [transactions, setTransactions] = useBusinessState<Transaction[]>(
    "transactions",
    seedTxns,
  );
  const [approvals, setApprovals] = useBusinessState<Approval[]>(
    "approvals",
    seedApprovals,
  );
  const [stockMovements, setStockMovements] = useBusinessState<StockMovement[]>(
    "stockMovements",
    seedMovements,
  );
  const [priceChanges, setPriceChanges] = useBusinessState<PriceChange[]>(
    "priceChanges",
    seedPriceChanges,
  );
  const [promotions, setPromotions] = useBusinessState<Promotion[]>(
    "promotions",
    seedPromotions,
  );
  const [auditLog, setAuditLog] = useBusinessState<AuditEntry[]>(
    "auditLog",
    seedAudit,
  );
  const [mpesaPayments, setMpesaPayments] = useBusinessState<MpesaPayment[]>(
    "mpesaPayments",
    seedMpesa,
  );
  const [heldSales, setHeldSales] = useBusinessState<HeldSale[]>(
    "heldSales",
    [],
  );
  const [stockTake, setStockTake] = useBusinessState<StockTake>(
    "stockTake",
    seedStockTake,
  );
  const [cart, setCart] = useBusinessState<CartItem[]>("cart", []);
  const [selectedCustomerId, setSelectedCustomerId] = useBusinessState<
    string | null
  >("selectedCustomerId", null);
  const [activeTillId, setActiveTillId] = useBusinessState<string>(
    "activeTillId",
    "t1",
  );

  // --- auth ---
  const login = useCallback(
    (phone: string, pin: string): boolean => {
      const s = staffList.find(
        (st) => st.phone === phone && st.pin === pin && st.active,
      );
      if (s) {
        setCurrentUser(s);
        setScreen(s.role === "Cashier" ? "pos" : "dashboard");
        setAuditLog((prev) => [
          {
            id: `al-${Date.now()}`,
            action: `${s.name} logged in`,
            user: s.name,
            time: now(),
            type: "login",
          },
          ...prev,
        ]);
        return true;
      }
      return false;
    },
    [setAuditLog, staffList],
  );

  const logout = useCallback(() => {
    setCurrentUser(null);
    setScreen("dashboard");
  }, []);

  const hasPermission = useCallback(
    (perm: Permission): boolean => {
      if (!currentUser) return false;
      return ROLE_PERMISSIONS[currentUser.role].includes(perm);
    },
    [currentUser],
  );

  const canAccessScreen = useCallback(
    (s: Screen): boolean => {
      return s === "dashboard"
        ? hasPermission("reports")
        : hasPermission(s as Permission);
    },
    [hasPermission],
  );

  // --- audit ---
  const addAudit = useCallback(
    (action: string, type: AuditEntry["type"]) => {
      const user = currentUser?.name ?? "System";
      setAuditLog((prev) => [
        { id: `al-${Date.now()}`, action, user, time: now(), type },
        ...prev,
      ]);
    },
    [currentUser?.name, setAuditLog],
  );

  // --- POS / cart ---
  const activeTill = useMemo(
    () => tills.find((t) => t.id === activeTillId) ?? tills[0],
    [tills, activeTillId],
  );

  const scanBarcode = useCallback(
    (code: string): Product | null => {
      // scale barcodes start with 20 and encode weight
      if (code.startsWith("20") && code.length >= 8) {
        const productId = code.slice(2, 7);

        const product = products.find(
          (p) =>
            p.barcode.startsWith("20") && p.id === `p${parseInt(productId)}`,
        );
        if (product) return product;
      }
      return products.find((p) => p.barcode === code) ?? null;
    },
    [products],
  );

  const addToCart = useCallback(
    (productId: string, qty?: number, _weighedPrice?: number) => {
      const product = products.find((p) => p.id === productId);
      if (!product) return;
      const price = product.price;
      const quantity = qty ?? 1;
      if (!Number.isFinite(quantity) || quantity <= 0) return;
      setCart((prev) => {
        const existing = prev.find((i) => i.productId === productId);
        if (existing) {
          return prev.map((i) =>
            i.productId === productId
              ? { ...i, qty: Math.round((i.qty + quantity) * 1000) / 1000 }
              : i,
          );
        }
        return [
          ...prev,
          {
            productId,
            name: product.name,
            price,
            qty: quantity,
            unit: product.unit,
            discount: 0,
            weighed: product.weighed ?? false,
            unitCost: product.cost,
          },
        ];
      });
    },
    [products, setCart],
  );

  const updateCartQty = useCallback(
    (productId: string, delta: number) => {
      setCart((prev) =>
        prev
          .map((i) => {
            if (i.productId !== productId || i.weighed) return i;
            return { ...i, qty: Math.max(0, i.qty + delta) };
          })
          .filter((i) => i.weighed || i.qty > 0),
      );
    },
    [setCart],
  );

  const removeFromCart = useCallback(
    (productId: string) => {
      setCart((prev) => prev.filter((i) => i.productId !== productId));
    },
    [setCart],
  );

  const clearCart = useCallback(() => setCart([]), [setCart]);

  const applyCartDiscount = useCallback(
    (pct: number): { needsApproval: boolean } => {
      if (
        !currentUser ||
        !cart.length ||
        !Number.isFinite(pct) ||
        pct <= 0 ||
        pct > 100
      )
        return { needsApproval: false };
      if (pct > 10) {
        setApprovals((prev) => [
          ...prev,
          {
            id: crypto.randomUUID(),
            type: "discount",
            description: `${pct}% cart discount`,
            requestedBy: currentUser?.name ?? "Unknown",
            requestedAt: now(),
            status: "pending",
            data: { pct, cart: JSON.stringify(cart) },
          },
        ]);
        addAudit(`Requested ${pct}% discount`, "discount");
        return { needsApproval: true };
      }
      setCart((prev) =>
        prev.map((i) => ({
          ...i,
          discount: money((i.price * i.qty * pct) / 100),
        })),
      );
      addAudit(`Applied ${pct}% discount`, "discount");
      return { needsApproval: false };
    },
    [addAudit, cart, currentUser, setApprovals, setCart],
  );

  const holdSale = useCallback(() => {
    if (cart.length === 0) return;
    const total = cart.reduce((s, i) => s + i.price * i.qty - i.discount, 0);
    setHeldSales((prev) => [
      ...prev,
      { id: `held-${Date.now()}`, items: cart, time: now(), total },
    ]);
    setCart([]);
  }, [cart, setCart, setHeldSales]);

  const resumeSale = useCallback(
    (id: string) => {
      const held = heldSales.find((h) => h.id === id);
      if (!held) return;
      setCart(held.items);
      setHeldSales((prev) => prev.filter((h) => h.id !== id));
    },
    [heldSales, setCart, setHeldSales],
  );

  const deleteHeldSale = useCallback(
    (id: string) => {
      setHeldSales((prev) => prev.filter((h) => h.id !== id));
    },
    [setHeldSales],
  );

  // --- payment validation & checkout ---
  const validatePayment = useCallback(
    (
      method: string,
      details: {
        cashTendered?: number;
        mpesaPhone?: string;
        customerId?: string;
        cashAmount?: number;
        mpesaAmount?: number;
      },
    ): { valid: boolean; error?: string } => {
      if (
        !currentUser ||
        !cart.length ||
        liveField("tills", tills).find((t) => t.id === activeTillId)?.status !==
          "open"
      )
        return {
          valid: false,
          error: "An open till and a non-empty cart are required",
        };
      if (JSON.stringify(liveField("cart", cart)) !== JSON.stringify(cart))
        return { valid: false, error: "Cart changed. Review it before paying" };
      if (!["cash", "mpesa", "split", "credit"].includes(method))
        return { valid: false, error: "Invalid payment method" };
      for (const product of liveField("products", products)) {
        const qty = cart
          .filter((i) => i.productId === product.id)
          .reduce((s, i) => s + i.qty, 0);
        if (qty > product.stock)
          return {
            valid: false,
            error: `Insufficient stock for ${product.name}`,
          };
      }
      if (
        cart.some(
          (i) =>
            !Number.isFinite(i.qty) ||
            i.qty <= 0 ||
            !Number.isFinite(i.price) ||
            i.price < 0 ||
            i.discount < 0 ||
            i.discount > i.price * i.qty,
        )
      )
        return { valid: false, error: "Invalid cart values" };
      if (
        [details.cashTendered, details.cashAmount, details.mpesaAmount].some(
          (n) => n !== undefined && (!Number.isFinite(n) || n < 0),
        )
      )
        return { valid: false, error: "Invalid payment amount" };
      const { total } = cartTotals(cart, products);

      if (method === "cash") {
        if (details.cashTendered === undefined || details.cashTendered < total)
          return {
            valid: false,
            error: "Cash tendered is less than total due",
          };
      }
      if (method === "mpesa") {
        if (
          !details.mpesaPhone ||
          !/^(?:0[17]\d{8}|254[17]\d{8})$/.test(details.mpesaPhone)
        )
          return { valid: false, error: "Valid M-Pesa phone number required" };
      }
      if (method === "split") {
        const sum = (details.cashAmount ?? 0) + (details.mpesaAmount ?? 0);
        if (Math.abs(sum - total) > 0.01)
          return {
            valid: false,
            error: `Split amounts must equal ${total}. Currently: ${sum}`,
          };
      }
      if (method === "credit") {
        if (!details.customerId)
          return { valid: false, error: "Select a customer for credit sale" };
        const cust = customers.find((c) => c.id === details.customerId);
        if (!cust) return { valid: false, error: "Invalid customer" };
        if (cust.debt + total > cust.creditLimit)
          return {
            valid: false,
            error: `Credit limit exceeded. Available: ${cust.creditLimit - cust.debt}`,
          };
      }
      return { valid: true };
    },
    [currentUser, cart, tills, products, activeTillId, customers],
  );

  const completeSale = useCallback(
    (
      method: "cash" | "mpesa" | "credit" | "split",
      details: {
        cashTendered?: number;
        mpesaPhone?: string;
        mpesaRef?: string;
        customerId?: string;
        cashAmount?: number;
        mpesaAmount?: number;
      },
    ): {
      success: boolean;
      receiptNo?: string;
      change?: number;
      error?: string;
    } => {
      const validation = validatePayment(method, details);
      if (!validation.valid) return { success: false, error: validation.error };

      const { subtotal, tax, total } = cartTotals(cart, products);
      const cost = money(
        cart.reduce((s, i) => {
          const p = products.find((pr) => pr.id === i.productId);
          return s + (p?.cost ?? 0) * i.qty;
        }, 0),
      );
      const discountTotal = cart.reduce((s, i) => s + i.discount, 0);
      const receiptNo = `RCP-${crypto.randomUUID().slice(0, 8).toUpperCase()}`;
      const cust = details.customerId
        ? customers.find((c) => c.id === details.customerId)
        : null;

      const txn: Transaction = {
        id: crypto.randomUUID(),
        receiptNo,
        time: now(),
        date: today(),
        cashierId: currentUser?.id ?? "st1",
        cashier: currentUser?.name ?? "System",
        tillId: activeTillId,
        tillName: activeTill.name,
        items: saleItems(cart, products),
        total,
        subtotal,
        tax,
        discount: discountTotal,
        method,
        cashAmount:
          method === "cash"
            ? total
            : method === "split"
              ? details.cashAmount
              : 0,
        mpesaAmount: method === "mpesa" ? total : details.mpesaAmount,
        mpesaRef: details.mpesaRef,
        demoPayment: method === "mpesa" || method === "split",
        customer: cust?.name,
        customerId: cust?.id,
        status: "completed",
        cost,
      };
      setTransactions((prev) => [txn, ...prev]);

      // update till
      setTills((prev) =>
        prev.map((t) =>
          t.id === activeTillId
            ? {
                ...t,
                cashSales:
                  t.cashSales +
                  (method === "cash"
                    ? total
                    : method === "split"
                      ? (details.cashAmount ?? 0)
                      : 0),
                mpesaSales:
                  t.mpesaSales +
                  (method === "mpesa"
                    ? total
                    : method === "split"
                      ? (details.mpesaAmount ?? 0)
                      : 0),
                creditSales: t.creditSales + (method === "credit" ? total : 0),
                transactions: t.transactions + 1,
              }
            : t,
        ),
      );

      // reduce stock
      setProducts((prev) =>
        prev.map((p) => {
          const qty = cart
            .filter((i) => i.productId === p.id)
            .reduce((sum, i) => sum + i.qty, 0);
          return qty ? { ...p, stock: quantity(p.stock - qty) } : p;
        }),
      );

      // stock movements
      cart.forEach((item) => {
        setStockMovements((prev) => [
          {
            id: `m-${Date.now()}-${item.productId}`,
            productId: item.productId,
            productName: item.name,
            type: "Sale",
            qty: -item.qty,
            user: currentUser?.name ?? "System",
            time: now(),
            reason: "",
            approved: true,
          },
          ...prev,
        ]);
      });

      // customer debt & loyalty
      if (cust && method === "credit") {
        setCustomers((prev) =>
          prev.map((c) =>
            c.id === cust.id
              ? {
                  ...c,
                  debt: c.debt + total,
                  totalSpent: c.totalSpent + total,
                  loyaltyPoints: c.loyaltyPoints + Math.floor(total / 100),
                }
              : c,
          ),
        );
      } else if (cust) {
        setCustomers((prev) =>
          prev.map((c) =>
            c.id === cust.id
              ? {
                  ...c,
                  totalSpent: c.totalSpent + total,
                  loyaltyPoints: c.loyaltyPoints + Math.floor(total / 100),
                }
              : c,
          ),
        );
      }

      // mpesa reconciliation
      if (method === "mpesa" || (method === "split" && details.mpesaAmount)) {
        const ref = details.mpesaRef ?? `AUTO-${Date.now()}`;
        setMpesaPayments((prev) => [
          {
            id: `mp-${Date.now()}`,
            phone: details.mpesaPhone ?? "",
            amount: method === "mpesa" ? total : (details.mpesaAmount ?? 0),
            ref,
            time: now(),
            matched: true,
            transactionId: txn.id,
          },
          ...prev,
        ]);
      }

      // staff sales
      if (currentUser) {
        setStaffList((prev) =>
          prev.map((s) =>
            s.id === currentUser.id
              ? { ...s, salesToday: s.salesToday + total }
              : s,
          ),
        );
      }

      addAudit(
        `Completed sale ${receiptNo} (${method}) — KSh ${total}`,
        "approval",
      );
      setCart([]);
      setSelectedCustomerId(null);

      const change =
        method === "cash" ? (details.cashTendered ?? 0) - total : 0;
      return { success: true, receiptNo, change };
    },
    [
      validatePayment,
      cart,
      products,
      customers,
      currentUser,
      activeTillId,
      activeTill.name,
      setTransactions,
      setTills,
      setProducts,
      addAudit,
      setCart,
      setSelectedCustomerId,
      setStockMovements,
      setCustomers,
      setMpesaPayments,
      setStaffList,
    ],
  );

  // Receipt-linked returns. The complete payload is validated before any stock or payment changes.
  const executeReturn = (
    txnId: string,
    amount: number,
    reason: string,
    lines: ReturnLine[],
    voided = false,
    exchange = false,
  ): boolean => {
    const txn = liveField("transactions", transactions).find(
      (t) => t.id === txnId,
    );
    if (
      !txn ||
      !["completed", "partial_refund"].includes(txn.status) ||
      !reason.trim() ||
      !Number.isFinite(amount) ||
      amount <= 0 ||
      amount > money(txn.total - (txn.refundAmount ?? 0))
    )
      return false;
    let plan;
    try {
      plan = lines.length ? returnPlan(txn, lines) : null;
    } catch {
      return false;
    }
    if (plan && Math.abs(plan.amount - amount) > 0.01) return false;
    if (!activeTill || activeTill.status !== "open") return false;
    const cash = money(
      (amount * (txn.cashAmount ?? (txn.method === "cash" ? txn.total : 0))) /
        txn.total,
    );
    const mpesa = money(
      (amount * (txn.mpesaAmount ?? (txn.method === "mpesa" ? txn.total : 0))) /
        txn.total,
    );
    if (
      !exchange &&
      cash >
        expectedCash(
          liveField("tills", tills).find((t) => t.id === activeTillId)!,
        )
    )
      return false;
    setTransactions((prev) =>
      prev.map((t) =>
        t.id === txnId
          ? {
              ...t,
              status: voided
                ? "voided"
                : money((t.refundAmount ?? 0) + amount) >= t.total
                  ? "refunded"
                  : "partial_refund",
              refundAmount: money((t.refundAmount ?? 0) + amount),
              refundSubtotal: money(
                (t.refundSubtotal ?? 0) +
                  (plan ? amount - plan.tax : (amount * t.subtotal) / t.total),
              ),
              refundTax: money(
                (t.refundTax ?? 0) + (plan?.tax ?? (amount * t.tax) / t.total),
              ),
              refundCost: money((t.refundCost ?? 0) + (plan?.cost ?? 0)),
              refundCash: money((t.refundCash ?? 0) + cash),
              refundMpesa: money((t.refundMpesa ?? 0) + mpesa),
              items: t.items.map((item, index) => ({
                ...item,
                returnedQty: quantity(
                  (item.returnedQty ?? 0) +
                    (lines.find((l) => l.index === index)?.qty ?? 0),
                ),
              })),
            }
          : t,
      ),
    );
    for (const line of plan?.selected ?? []) {
      if (line.condition === "sellable")
        setProducts((prev) =>
          prev.map((p) =>
            p.id === line.item.productId
              ? { ...p, stock: money(p.stock + line.qty) }
              : p,
          ),
        );
      setStockMovements((prev) => [
        {
          id: crypto.randomUUID(),
          productId: line.item.productId,
          productName: line.item.name,
          type: line.condition === "sellable" ? "Return" : "Damage",
          qty: line.condition === "sellable" ? line.qty : 0,
          user: currentUser?.name ?? "System",
          time: now(),
          reason: `${reason} · ${line.qty} ${line.item.unit} ${line.condition} · ${txn.receiptNo}`,
          approved: true,
        },
        ...prev,
      ]);
    }
    if (!exchange)
      setTills((prev) =>
        prev.map((t) =>
          t.id === activeTillId
            ? {
                ...t,
                cashSales: money(t.cashSales - cash),
                mpesaSales: money(t.mpesaSales - mpesa),
                creditSales: money(
                  t.creditSales - (txn.method === "credit" ? amount : 0),
                ),
                voids: t.voids + (voided ? 1 : 0),
              }
            : t,
        ),
      );
    setCustomers((prev) =>
      prev.map((c) =>
        c.id === txn.customerId
          ? {
              ...c,
              debt: Math.max(
                0,
                money(c.debt - (txn.method === "credit" ? amount : 0)),
              ),
              totalSpent: Math.max(0, money(c.totalSpent - amount)),
            }
          : c,
      ),
    );
    addAudit(
      `${voided ? "Voided" : "Refund recorded"} ${txn.receiptNo} — KSh ${amount} — ${reason}${mpesa ? " (demo M-Pesa reversal)" : ""}`,
      voided ? "void" : "refund",
    );
    return true;
  };

  const processRefund = (
    txnId: string,
    amount: number,
    reason: string,
    _needsApproval: boolean,
    selected?: ReturnLine[],
  ): { needsApproval: boolean; approved: boolean } => {
    const txn = liveField("transactions", transactions).find(
      (t) => t.id === txnId,
    );
    if (
      !txn ||
      !reason ||
      !Number.isFinite(amount) ||
      amount <= 0 ||
      amount > money(txn.total - (txn.refundAmount ?? 0)) ||
      !["completed", "partial_refund"].includes(txn.status)
    )
      return { needsApproval: false, approved: false };
    // An amount-only partial refund is a price correction and never implies stock returned.
    const lines =
      selected ??
      (Math.abs(amount - (txn.total - (txn.refundAmount ?? 0))) < 0.01
        ? fullReturnLines(txn)
        : []);
    try {
      if (
        lines.length &&
        Math.abs(returnPlan(txn, lines).amount - amount) > 0.01
      )
        return { needsApproval: false, approved: false };
    } catch {
      return { needsApproval: false, approved: false };
    }
    if (!hasPermission("approve")) {
      setApprovals((prev) => [
        ...prev,
        {
          id: crypto.randomUUID(),
          type: "refund",
          description: `Refund ${txn.receiptNo} — ${reason}`,
          amount,
          requestedBy: currentUser?.name ?? "Unknown",
          requestedAt: now(),
          status: "pending",
          transactionId: txnId,
          data: {
            lines,
            reason,
            tillId: activeTillId,
            expectedRefundAmount: txn.refundAmount ?? 0,
          },
        },
      ]);
      return { needsApproval: true, approved: false };
    }
    return {
      needsApproval: false,
      approved: executeReturn(txnId, amount, reason, lines),
    };
  };

  const voidTransaction = (
    txnId: string,
  ): { needsApproval: boolean; approved: boolean } => {
    const txn = liveField("transactions", transactions).find(
      (t) => t.id === txnId,
    );
    if (!txn || txn.status !== "completed")
      return { needsApproval: false, approved: false };
    if (!hasPermission("approve")) {
      setApprovals((prev) => [
        ...prev,
        {
          id: crypto.randomUUID(),
          type: "void",
          description: `Void ${txn.receiptNo}`,
          requestedBy: currentUser?.name ?? "Unknown",
          requestedAt: now(),
          status: "pending",
          transactionId: txnId,
        },
      ]);
      return { needsApproval: true, approved: false };
    }
    return {
      needsApproval: false,
      approved: executeReturn(
        txnId,
        txn.total,
        "Transaction void",
        fullReturnLines(txn),
        true,
      ),
    };
  };

  const approveRequest = (approvalId: string, approved: boolean): boolean => {
    if (!hasPermission("approve")) return false;
    const ap = liveField("approvals", approvals).find(
      (a) => a.id === approvalId,
    );
    if (!ap || ap.status !== "pending") return false;
    let executed = !approved;
    if (approved) {
      if (ap.type === "discount" && ap.data) {
        if (ap.data.cart !== JSON.stringify(liveField("cart", cart))) {
          addAudit("Discount approval rejected: cart changed", "approval");
          return false;
        }
        const pct = Number(ap.data.pct);
        setCart((prev) =>
          prev.map((i) => ({
            ...i,
            discount: money((i.price * i.qty * pct) / 100),
          })),
        );
        executed = true;
      } else if (ap.type === "exchange" && ap.transactionId && ap.data) {
        executed = executeExchange(
          ap.transactionId,
          String(ap.data.reason),
          ap.data.items as CartItem[],
          Number(ap.data.cashTendered),
          ap.data.lines as ReturnLine[],
        ).success;
      } else if (
        ap.transactionId &&
        (ap.type === "refund" || ap.type === "void")
      ) {
        const tx = liveField("transactions", transactions).find(
          (t) => t.id === ap.transactionId,
        );
        if (
          tx &&
          (ap.data?.expectedRefundAmount === undefined ||
            ap.data.expectedRefundAmount === (tx.refundAmount ?? 0))
        )
          executed = executeReturn(
            tx.id,
            ap.amount ?? tx.total,
            String(ap.data?.reason ?? ap.description),
            (ap.data?.lines as ReturnLine[] | undefined) ?? fullReturnLines(tx),
            ap.type === "void",
          );
      }
    }
    if (!executed) {
      addAudit(
        `Approval could not execute: ${ap.description}. Check remaining quantities, till and available cash.`,
        "approval",
      );
      return false;
    }
    setApprovals((prev) =>
      prev.map((a) =>
        a.id === approvalId
          ? {
              ...a,
              status: approved ? "approved" : "denied",
              approvedBy: currentUser?.name,
            }
          : a,
      ),
    );
    addAudit(
      `${approved ? "Approved" : "Denied"}: ${ap.description}`,
      "approval",
    );
    return true;
  };

  // --- till operations ---
  const openTill = useCallback(
    (tillId: string, float: number): boolean => {
      if (!hasPermission("manage_till")) return false;
      if (
        !currentUser ||
        !Number.isFinite(float) ||
        float < 0 ||
        tills.find((t) => t.id === tillId)?.status !== "closed"
      )
        return false;
      setTills((prev) =>
        prev.map((t) =>
          t.id === tillId
            ? {
                ...t,
                status: "open",
                openingFloat: float,
                cashierId: currentUser.id,
                cashier: currentUser.name,
                cashSales: 0,
                mpesaSales: 0,
                creditSales: 0,
                transactions: 0,
                voids: 0,
                variance: 0,
                cashDrops: [],
                paidOuts: [],
                openedAt: now(),
                closingFloat: null,
              }
            : t,
        ),
      );
      setActiveTillId(tillId);
      addAudit(
        `Opened ${tills.find((t) => t.id === tillId)?.name} with float KSh ${float}`,
        "till",
      );
      return true;
    },
    [hasPermission, currentUser, tills, setTills, setActiveTillId, addAudit],
  );

  const closeTill = useCallback(
    (
      tillId: string,
      countedCash: number,
    ): { variance: number; expected: number } | null => {
      if (!hasPermission("manage_till")) return null;
      const till = liveField("tills", tills).find((t) => t.id === tillId);
      if (!till) return null;
      if (
        till.status !== "open" ||
        !Number.isFinite(countedCash) ||
        countedCash < 0
      )
        return null;
      const expectedCashAmount = expectedCash(till);
      const variance = money(countedCash - expectedCashAmount);
      setTills((prev) =>
        prev.map((t) =>
          t.id === tillId
            ? { ...t, status: "closed", closingFloat: countedCash, variance }
            : t,
        ),
      );
      addAudit(
        `Closed ${till.name}. Expected: ${expectedCashAmount}, Counted: ${countedCash}, Variance: ${variance}`,
        "cash",
      );
      return { variance, expected: expectedCashAmount };
    },
    [hasPermission, tills, setTills, addAudit],
  );

  const cashDrop = useCallback(
    (tillId: string, amount: number): boolean => {
      if (!Number.isFinite(amount) || amount <= 0) return false;
      const till = liveField("tills", tills).find((t) => t.id === tillId);
      if (!till || till.status !== "open" || amount > expectedCash(till))
        return false;
      setTills((prev) =>
        prev.map((t) =>
          t.id === tillId
            ? {
                ...t,
                cashDrops: [
                  ...t.cashDrops,
                  {
                    id: `cd-${Date.now()}`,
                    tillId,
                    amount,
                    time: now(),
                    acknowledged: false,
                  },
                ],
              }
            : t,
        ),
      );
      addAudit(`Cash drop KSh ${amount} from ${till.name}`, "cash");
      return true;
    },
    [tills, setTills, addAudit],
  );

  const acknowledgeDrop = useCallback(
    (dropId: string) => {
      if (!hasPermission("approve")) return;
      setTills((prev) =>
        prev.map((t) => ({
          ...t,
          cashDrops: t.cashDrops.map((d) =>
            d.id === dropId
              ? { ...d, acknowledged: true, acknowledgedBy: currentUser?.name }
              : d,
          ),
        })),
      );
    },
    [currentUser, hasPermission, setTills],
  );

  const paidOut = useCallback(
    (tillId: string, amount: number, reason: string): boolean => {
      if (!Number.isFinite(amount) || amount <= 0 || !reason.trim())
        return false;
      const till = liveField("tills", tills).find((t) => t.id === tillId);
      if (!till || till.status !== "open" || amount > expectedCash(till))
        return false;
      setTills((prev) =>
        prev.map((t) =>
          t.id === tillId
            ? {
                ...t,
                paidOuts: [
                  ...t.paidOuts,
                  {
                    id: `po-${Date.now()}`,
                    tillId,
                    amount,
                    reason,
                    time: now(),
                    user: currentUser?.name ?? "Unknown",
                  },
                ],
              }
            : t,
        ),
      );
      addAudit(`Paid out KSh ${amount} from ${till.name} — ${reason}`, "cash");
      return true;
    },
    [tills, setTills, addAudit, currentUser?.name],
  );

  // --- products ---
  const addProduct = useCallback(
    (p: Omit<Product, "id" | "imageColor">): void => {
      const id = `p${Date.now()}`;
      setProducts((prev) => [
        ...prev,
        {
          ...p,
          id,
          imageColor: colors[Math.floor(Math.random() * colors.length)],
        },
      ]);
      addAudit(`Added product: ${p.name}`, "price_change");
    },
    [addAudit, setProducts],
  );

  const updateProductPrice = useCallback(
    (productId: string, newPrice: number, scheduled?: string): void => {
      const product = products.find((p) => p.id === productId);
      if (!product) return;
      if (newPrice < product.cost && !hasPermission("price_change")) {
        // below-cost alert — requires manager
        setApprovals((prev) => [
          ...prev,
          {
            id: `ap-${Date.now()}`,
            type: "price_change",
            description: `Below-cost price for ${product.name}: KSh ${newPrice} (cost: ${product.cost})`,
            requestedBy: currentUser?.name ?? "Unknown",
            requestedAt: now(),
            status: "pending",
          },
        ]);
        return;
      }
      if (scheduled) {
        setPriceChanges((prev) => [
          ...prev,
          {
            id: `pc-${Date.now()}`,
            productId,
            productName: product.name,
            oldPrice: product.price,
            newPrice,
            date: scheduled,
            user: currentUser?.name ?? "System",
            scheduled,
            applied: false,
          },
        ]);
      } else {
        setProducts((prev) =>
          prev.map((p) => (p.id === productId ? { ...p, price: newPrice } : p)),
        );
        setPriceChanges((prev) => [
          ...prev,
          {
            id: `pc-${Date.now()}`,
            productId,
            productName: product.name,
            oldPrice: product.price,
            newPrice,
            date: today(),
            user: currentUser?.name ?? "System",
            applied: true,
          },
        ]);
      }
      addAudit(
        `Price change: ${product.name} ${product.price} → ${newPrice}`,
        "price_change",
      );
    },
    [
      products,
      hasPermission,
      addAudit,
      setApprovals,
      currentUser?.name,
      setPriceChanges,
      setProducts,
    ],
  );

  const applyMarkdown = useCallback(
    (productId: string, markdownPct: number): void => {
      if (!hasPermission("markdown")) {
        const product = products.find((p) => p.id === productId);
        setApprovals((prev) => [
          ...prev,
          {
            id: `ap-${Date.now()}`,
            type: "markdown",
            description: `Near-expiry markdown ${markdownPct}% for ${product?.name}`,
            requestedBy: currentUser?.name ?? "Unknown",
            requestedAt: now(),
            status: "pending",
          },
        ]);
        return;
      }
      setProducts((prev) =>
        prev.map((p) =>
          p.id === productId
            ? { ...p, price: Math.round(p.price * (1 - markdownPct / 100)) }
            : p,
        ),
      );
      const product = products.find((p) => p.id === productId);
      addAudit(
        `Markdown ${markdownPct}% applied to ${product?.name}`,
        "price_change",
      );
    },
    [
      hasPermission,
      setProducts,
      products,
      addAudit,
      setApprovals,
      currentUser?.name,
    ],
  );

  // --- promotions ---
  const addPromotion = useCallback(
    (promo: Omit<Promotion, "id">): void => {
      setPromotions((prev) => [
        ...prev,
        { ...promo, id: `promo-${Date.now()}` },
      ]);
      addAudit(`Created promotion: ${promo.name}`, "price_change");
    },
    [addAudit, setPromotions],
  );

  const togglePromotion = useCallback(
    (promoId: string): void => {
      setPromotions((prev) =>
        prev.map((p) => (p.id === promoId ? { ...p, active: !p.active } : p)),
      );
    },
    [setPromotions],
  );

  // --- inventory ---
  const submitStockAdjustment = useCallback(
    (
      productId: string,
      qty: number,
      type: StockMovement["type"],
      reason: string,
    ): { needsApproval: boolean } => {
      const product = products.find((p) => p.id === productId);
      if (!product || !reason) return { needsApproval: false };

      const needsApproval = Math.abs(qty) > 5 && !hasPermission("stock_adjust");
      if (needsApproval) {
        setApprovals((prev) => [
          ...prev,
          {
            id: `ap-${Date.now()}`,
            type: "stock_adjust",
            description: `Stock ${type}: ${product.name} (${qty > 0 ? "+" : ""}${qty}) — ${reason}`,
            requestedBy: currentUser?.name ?? "Unknown",
            requestedAt: now(),
            status: "pending",
          },
        ]);
        addAudit(
          `Stock adjustment request: ${product.name} (${qty})`,
          "adjustment",
        );
        return { needsApproval: true };
      }

      setProducts((prev) =>
        prev.map((p) =>
          p.id === productId ? { ...p, stock: Math.max(0, p.stock + qty) } : p,
        ),
      );
      setStockMovements((prev) => [
        {
          id: `m-${Date.now()}`,
          productId,
          productName: product.name,
          type,
          qty,
          user: currentUser?.name ?? "System",
          time: now(),
          reason,
          approved: true,
        },
        ...prev,
      ]);
      addAudit(
        `Stock ${type}: ${product.name} (${qty > 0 ? "+" : ""}${qty})`,
        "adjustment",
      );
      return { needsApproval: false };
    },
    [
      products,
      hasPermission,
      setProducts,
      setStockMovements,
      addAudit,
      setApprovals,
      currentUser?.name,
    ],
  );

  const updateStockTakeCount = useCallback(
    (productId: string, countedQty: number): void => {
      setStockTake((prev) => ({
        ...prev,
        items: prev.items.map((it) =>
          it.productId === productId
            ? { ...it, countedQty, variance: countedQty - it.systemQty }
            : it,
        ),
      }));
    },
    [setStockTake],
  );

  const completeStockTake = useCallback((): {
    variances: number;
    adjusted: number;
  } => {
    let adjusted = 0;
    const variances = stockTake.items.filter(
      (it) => it.countedQty !== null && it.countedQty !== it.systemQty,
    ).length;
    stockTake.items.forEach((it) => {
      if (it.countedQty !== null && it.countedQty !== it.systemQty) {
        const diff = it.countedQty - it.systemQty;
        setProducts((prev) =>
          prev.map((p) =>
            p.id === it.productId ? { ...p, stock: it.countedQty! } : p,
          ),
        );
        setStockMovements((prev) => [
          {
            id: `m-${Date.now()}-${it.productId}`,
            productId: it.productId,
            productName: it.productName,
            type: "Adjustment",
            qty: diff,
            user: currentUser?.name ?? "System",
            time: now(),
            reason: "Stock take correction",
            approved: true,
          },
          ...prev,
        ]);
        adjusted++;
      }
    });
    setStockTake((prev) => ({ ...prev, status: "completed" }));
    addAudit(
      `Completed stock take — ${variances} variances, ${adjusted} adjusted`,
      "adjustment",
    );
    return { variances, adjusted };
  }, [
    stockTake.items,
    setStockTake,
    addAudit,
    setProducts,
    setStockMovements,
    currentUser?.name,
  ]);

  // --- suppliers / purchasing ---
  const addSupplier = useCallback(
    (
      s: Omit<
        Supplier,
        "id" | "priceHistory" | "balance" | "lastOrder" | "products"
      >,
    ): void => {
      setSuppliers((prev) => [
        ...prev,
        {
          ...s,
          id: `s-${Date.now()}`,
          balance: 0,
          lastOrder: "—",
          products: 0,
          priceHistory: [],
        },
      ]);
    },
    [setSuppliers],
  );

  const createPO = useCallback(
    (
      supplierId: string,
      items: PurchaseOrderItem[],
      expected: string,
      paymentTerms: string,
    ): void => {
      const supplier = suppliers.find((s) => s.id === supplierId);
      if (!supplier) return;
      const total = items.reduce((s, i) => s + i.total, 0);
      const poId = `PO-${String(purchaseOrders.length + 1).padStart(3, "0")}`;
      setPurchaseOrders((prev) => [
        ...prev,
        {
          id: poId,
          supplierId,
          supplierName: supplier.name,
          date: today(),
          expected,
          status: "draft",
          total,
          items,
          paymentTerms,
          paymentStatus: "unpaid",
        },
      ]);
      addAudit(`Created ${poId} for ${supplier.name}`, "approval");
    },
    [suppliers, purchaseOrders.length, setPurchaseOrders, addAudit],
  );

  const sendPO = useCallback(
    (poId: string): void => {
      setPurchaseOrders((prev) =>
        prev.map((p) => (p.id === poId ? { ...p, status: "sent" } : p)),
      );
      addAudit(`Sent ${poId} to supplier`, "approval");
    },
    [addAudit, setPurchaseOrders],
  );

  const receivePO = useCallback(
    (poId: string, partial: boolean): void => {
      const po = purchaseOrders.find((p) => p.id === poId);
      if (!po) return;
      setPurchaseOrders((prev) =>
        prev.map((p) =>
          p.id === poId
            ? { ...p, status: partial ? "partial" : "received" }
            : p,
        ),
      );
      // update stock & supplier balance
      po.items.forEach((item) => {
        setProducts((prev) =>
          prev.map((p) =>
            p.id === item.productId ? { ...p, stock: p.stock + item.qty } : p,
          ),
        );
        setStockMovements((prev) => [
          {
            id: `m-${Date.now()}-${item.productId}`,
            productId: item.productId,
            productName: item.name,
            type: "Received",
            qty: item.qty,
            user: currentUser?.name ?? "System",
            time: now(),
            reason: `${poId} delivery`,
            approved: true,
          },
          ...prev,
        ]);
      });
      setSuppliers((prev) =>
        prev.map((s) =>
          s.id === po.supplierId
            ? { ...s, balance: s.balance + po.total, lastOrder: today() }
            : s,
        ),
      );
      addAudit(
        `Received ${poId} (${partial ? "partial" : "complete"})`,
        "approval",
      );
    },
    [
      purchaseOrders,
      setPurchaseOrders,
      setSuppliers,
      addAudit,
      setProducts,
      setStockMovements,
      currentUser?.name,
    ],
  );

  const scheduleSupplierPayment = useCallback(
    (poId: string, amount: number, dueDate: string): void => {
      const po = purchaseOrders.find((p) => p.id === poId);
      if (!po) return;
      setPurchaseOrders((prev) =>
        prev.map((p) =>
          p.id === poId
            ? {
                ...p,
                paymentDue: dueDate,
                paymentStatus: amount >= po.total ? "paid" : "partial",
              }
            : p,
        ),
      );
      if (amount > 0) {
        setSuppliers((prev) =>
          prev.map((s) =>
            s.id === po.supplierId
              ? { ...s, balance: Math.max(0, s.balance - amount) }
              : s,
          ),
        );
      }
      addAudit(
        `Scheduled payment for ${poId}: KSh ${amount} due ${dueDate}`,
        "cash",
      );
    },
    [purchaseOrders, setPurchaseOrders, addAudit, setSuppliers],
  );

  const supplierReturn = useCallback(
    (
      supplierId: string,
      productId: string,
      qty: number,
      reason: string,
    ): void => {
      const product = products.find((p) => p.id === productId);
      if (!product) return;
      setProducts((prev) =>
        prev.map((p) =>
          p.id === productId ? { ...p, stock: Math.max(0, p.stock - qty) } : p,
        ),
      );
      setStockMovements((prev) => [
        {
          id: `m-${Date.now()}`,
          productId,
          productName: product.name,
          type: "Transfer",
          qty: -qty,
          user: currentUser?.name ?? "System",
          time: now(),
          reason: `Return to supplier: ${reason}`,
          approved: true,
        },
        ...prev,
      ]);
      const refund = qty * product.cost;
      setSuppliers((prev) =>
        prev.map((s) =>
          s.id === supplierId
            ? { ...s, balance: Math.max(0, s.balance - refund) }
            : s,
        ),
      );
      addAudit(
        `Supplier return: ${qty}x ${product.name} — ${reason}`,
        "approval",
      );
    },
    [
      products,
      setProducts,
      setStockMovements,
      setSuppliers,
      addAudit,
      currentUser?.name,
    ],
  );

  // --- customers ---
  const addCustomer = useCallback(
    (
      c: Omit<
        Customer,
        "id" | "totalSpent" | "joinedDate" | "loyaltyPoints" | "debt"
      >,
    ): void => {
      setCustomers((prev) => [
        ...prev,
        {
          ...c,
          id: `c-${Date.now()}`,
          debt: 0,
          totalSpent: 0,
          joinedDate: today(),
          loyaltyPoints: 0,
        },
      ]);
    },
    [setCustomers],
  );

  const payDebt = useCallback(
    (customerId: string, amount: number): void => {
      setCustomers((prev) =>
        prev.map((c) =>
          c.id === customerId
            ? { ...c, debt: Math.max(0, c.debt - amount) }
            : c,
        ),
      );
      const cust = customers.find((c) => c.id === customerId);
      addAudit(`Debt payment: ${cust?.name} — KSh ${amount}`, "cash");
    },
    [setCustomers, customers, addAudit],
  );

  // --- staff ---
  const saveStaffProfile = useCallback(
    (input: { name: string; role: Role; phone: string; pin?: string }, staffId?: string): { success: boolean; error?: string } => {
      if (currentUser?.role !== 'Owner') return { success: false, error: 'Only an owner can manage staff.' };
      const members = liveField('staffList', staffList);
      const target = staffId ? members.find((s) => s.id === staffId) : undefined;
      if (staffId && !target) return { success: false, error: 'Staff member not found.' };
      const name = input.name.trim();
      const phone = input.phone.replace(/[\s+-]/g, '');
      const canonical = (value: string) => value.startsWith('0') ? `255${value.slice(1)}` : value;
      if (!name || name.length > 100 || !/^(?:0\d{9}|255\d{9})$/.test(phone)
        || !(input.role in ROLE_PERMISSIONS) || (!target && !/^[0-9]{4,6}$/.test(input.pin ?? '')))
        return { success: false, error: 'Enter a name, Tanzania phone number, role and a 4–6 digit PIN for new staff.' };
      if (members.some((s) => s.id !== staffId && canonical(s.phone) === canonical(phone)))
        return { success: false, error: 'This phone number is already assigned.' };
      if (target?.active && target.role === 'Owner' && input.role !== 'Owner'
        && members.filter((s) => s.active && s.role === 'Owner').length <= 1)
        return { success: false, error: 'Keep at least one active owner.' };
      if (target) {
        const updated = { ...target, name, phone, role: input.role };
        setStaffList(members.map((s) => s.id === staffId ? updated : s));
        if (currentUser.id === staffId) setCurrentUser(updated);
      } else {
        setStaffList([...members, { name, phone, role: input.role, pin: input.pin!, id: `st-${crypto.randomUUID()}`,
          active: true, salesToday: 0, attendance: 'absent', twoFactor: false }]);
      }
      addAudit(target ? 'Demo staff profile updated' : 'Demo staff created', 'approval');
      return { success: true };
    }, [currentUser, staffList, setStaffList, addAudit],
  );
  const setStaffActive = useCallback((staffId: string, active: boolean): { success: boolean; error?: string } => {
    if (currentUser?.role !== 'Owner') return { success: false, error: 'Only an owner can manage staff.' };
    const members = liveField('staffList', staffList);
    const target = members.find((s) => s.id === staffId);
    if (!target) return { success: false, error: 'Staff member not found.' };
    if (!active && target.active && target.role === 'Owner' && members.filter((s) => s.active && s.role === 'Owner').length <= 1)
      return { success: false, error: 'Keep at least one active owner.' };
    setStaffList(members.map((s) => s.id === staffId ? { ...s, active, attendance: active ? s.attendance : 'absent', clockInTime: active ? s.clockInTime : undefined } : s));
    if (!active && currentUser.id === staffId) logout();
    addAudit(active ? 'Demo staff reactivated' : 'Demo staff deactivated', 'approval');
    return { success: true };
  }, [currentUser, staffList, setStaffList, logout, addAudit]);
  const addStaff = useCallback(
    (s: { name: string; role: Role; phone: string; pin: string }) => saveStaffProfile(s),
    [saveStaffProfile],
  );

  const toggleStaff2FA = useCallback(
    (staffId: string): void => {
      setStaffList((prev) =>
        prev.map((s) =>
          s.id === staffId ? { ...s, twoFactor: !s.twoFactor } : s,
        ),
      );
    },
    [setStaffList],
  );

  const clockIn = useCallback(
    (staffId: string): void => {
      setStaffList((prev) =>
        prev.map((s) =>
          s.id === staffId
            ? { ...s, attendance: "present", clockInTime: now() }
            : s,
        ),
      );
    },
    [setStaffList],
  );

  const clockOut = useCallback(
    (staffId: string): void => {
      setStaffList((prev) =>
        prev.map((s) =>
          s.id === staffId
            ? { ...s, attendance: "absent", clockInTime: undefined }
            : s,
        ),
      );
    },
    [setStaffList],
  );

  // Exchanges remain drafts until a complete, approved settlement is recorded.
  const processExchange = (txnId: string, reason: string) => {
    const txn = transactions.find((t) => t.id === txnId);
    if (
      !txn ||
      !reason ||
      !txn.items.length ||
      !["completed", "partial_refund"].includes(txn.status) ||
      txn.method === "credit"
    )
      return { needsApproval: false, approved: false, items: [] as CartItem[] };
    return {
      needsApproval: false,
      approved: true,
      items: txn.items
        .map((i) => ({ ...i, qty: quantity(i.qty - (i.returnedQty ?? 0)) }))
        .filter((i) => i.qty > 0),
    };
  };
  const executeExchange = (
    txnId: string,
    reason: string,
    items: CartItem[],
    cashTendered: number,
    lines?: ReturnLine[],
  ): {
    success: boolean;
    error?: string;
    receiptNo?: string;
    difference?: number;
    change?: number;
  } => {
    const txn = liveField("transactions", transactions).find(
      (t) => t.id === txnId,
    );
    if (
      !txn ||
      txn.method === "credit" ||
      !items.length ||
      !reason.trim() ||
      liveField("tills", tills).find((t) => t.id === activeTillId)?.status !==
        "open"
    )
      return {
        success: false,
        error:
          "An eligible receipt, replacements and an open till are required",
      };
    let plan;
    try {
      plan = returnPlan(txn, lines?.length ? lines : fullReturnLines(txn));
    } catch {
      return {
        success: false,
        error: "Original items have already been returned",
      };
    }
    const totals = cartTotals(items, products),
      difference = money(totals.total - plan.amount);
    if (
      !Number.isFinite(cashTendered) ||
      cashTendered < Math.max(0, difference)
    )
      return {
        success: false,
        error: "Enter enough cash to settle the difference",
      };
    const liveProducts = liveField("products", products);
    for (const p of liveProducts) {
      const needed = items
        .filter((i) => i.productId === p.id)
        .reduce((s, i) => s + i.qty, 0);
      const returned = plan.selected
        .filter((l) => l.item.productId === p.id && l.condition === "sellable")
        .reduce((s, l) => s + l.qty, 0);
      if (needed > p.stock + returned)
        return { success: false, error: `Insufficient stock for ${p.name}` };
    }
    if (
      items.some(
        (i) =>
          !Number.isFinite(i.qty) ||
          i.qty <= 0 ||
          !Number.isFinite(i.price) ||
          i.price < 0 ||
          i.discount < 0 ||
          i.discount > i.qty * i.price ||
          !liveProducts.some((p) => p.id === i.productId),
      )
    )
      return { success: false, error: "Invalid replacement items" };
    if (
      difference < 0 &&
      -difference >
        expectedCash(
          liveField("tills", tills).find((t) => t.id === activeTillId)!,
        )
    )
      return {
        success: false,
        error: "Not enough cash in the till for this refund",
      };
    if (
      !executeReturn(
        txnId,
        plan.amount,
        reason,
        lines?.length ? lines : fullReturnLines(txn),
        false,
        true,
      )
    )
      return { success: false, error: "Return could not be recorded" };
    const receiptNo = `RCP-${crypto.randomUUID().slice(0, 8).toUpperCase()}`;
    const inheritedCash = money(
      (plan.amount *
        (txn.cashAmount ?? (txn.method === "cash" ? txn.total : 0))) /
        txn.total,
    );
    const inheritedMpesa = money(
      (plan.amount *
        (txn.mpesaAmount ?? (txn.method === "mpesa" ? txn.total : 0))) /
        txn.total,
    );
    const extraCashRefund = Math.max(0, -difference - inheritedCash);
    if (extraCashRefund > 0)
      setTransactions((prev) =>
        prev.map((t) =>
          t.id === txnId
            ? {
                ...t,
                refundCash: money((t.refundCash ?? 0) + extraCashRefund),
                refundMpesa: money((t.refundMpesa ?? 0) - extraCashRefund),
              }
            : t,
        ),
      );
    const cost = money(
      items.reduce(
        (s, i) =>
          s +
          (liveProducts.find((p) => p.id === i.productId)?.cost ?? 0) * i.qty,
        0,
      ),
    );
    setTransactions((prev) => [
      {
        ...txn,
        id: crypto.randomUUID(),
        receiptNo,
        time: now(),
        date: today(),
        cashierId: currentUser?.id ?? "",
        cashier: currentUser?.name ?? "",
        tillId: activeTillId,
        tillName: activeTill.name,
        items: saleItems(items, liveProducts),
        ...totals,
        discount: items.reduce((s, i) => s + i.discount, 0),
        cost,
        status: "completed",
        method: inheritedMpesa ? "split" : "cash",
        cashAmount: Math.max(0, money(inheritedCash + difference)),
        mpesaAmount: money(inheritedMpesa - extraCashRefund),
        refundAmount: 0,
        refundSubtotal: 0,
        refundTax: 0,
        refundCost: 0,
        refundCash: 0,
        refundMpesa: 0,
        exchangeReceiptId: txnId,
      },
      ...prev,
    ]);
    setProducts((prev) =>
      prev.map((p) => ({
        ...p,
        stock: money(
          p.stock -
            items
              .filter((i) => i.productId === p.id)
              .reduce((s, i) => s + i.qty, 0),
        ),
      })),
    );
    setTills((prev) =>
      prev.map((t) =>
        t.id === activeTillId
          ? {
              ...t,
              cashSales: money(t.cashSales + difference),
              transactions: t.transactions + 1,
            }
          : t,
      ),
    );
    setStockMovements((prev) => [
      ...items.map((i) => ({
        id: crypto.randomUUID(),
        productId: i.productId,
        productName: i.name,
        type: "Sale" as const,
        qty: -i.qty,
        user: currentUser?.name ?? "System",
        time: now(),
        reason: `Exchange ${txn.receiptNo} → ${receiptNo}`,
        approved: true,
      })),
      ...prev,
    ]);
    setCustomers((prev) =>
      prev.map((c) =>
        c.id === txn.customerId
          ? { ...c, totalSpent: money(c.totalSpent + totals.total) }
          : c,
      ),
    );
    addAudit(
      `Exchange ${txn.receiptNo} → ${receiptNo}; cash difference ${difference}`,
      "refund",
    );
    return {
      success: true,
      receiptNo,
      difference,
      change: money(cashTendered - Math.max(0, difference)),
    };
  };
  const completeExchange = (
    txnId: string,
    reason: string,
    cashTendered: number,
    lines?: ReturnLine[],
  ) => {
    if (!hasPermission("approve")) {
      setApprovals((prev) => [
        ...prev,
        {
          id: crypto.randomUUID(),
          type: "exchange",
          description: `Exchange ${txnId} — ${reason}`,
          requestedBy: currentUser?.name ?? "Unknown",
          requestedAt: now(),
          status: "pending",
          transactionId: txnId,
          data: { reason, items: cart, cashTendered, lines: lines ?? [] },
        },
      ]);
      setCart([]);
      return {
        success: false,
        needsApproval: true,
        error:
          "Exchange queued for supervisor approval. No stock or payment has changed.",
      };
    }
    const result = executeExchange(txnId, reason, cart, cashTendered, lines);
    if (result.success) setCart([]);
    return { ...result, needsApproval: false };
  };

  const [dateTick, setDateTick] = useState(today());
  useEffect(() => {
    const timer = setInterval(() => setDateTick(today()), 30000);
    return () => clearInterval(timer);
  }, []);
  // --- computed ---
  const lowStockProducts = useMemo(
    () => products.filter((p) => p.stock <= p.reorderLevel),
    [products],
  );
  const expiringProducts = useMemo(
    () =>
      products.filter((p) => {
        if (!p.expiry) return false;
        const days =
          (new Date(p.expiry).getTime() - new Date(dateTick).getTime()) /
          (1000 * 60 * 60 * 24);
        return days <= 7;
      }),
    [products, dateTick],
  );

  const categories = useMemo(
    () => [...new Set(products.map((p) => p.category))].sort(),
    [products],
  );
  const departments = useMemo(
    () => [...new Set(products.map((p) => p.department))].sort(),
    [products],
  );

  const todaySales = useMemo(() => {
    const todayTxns = transactions
      .filter(
        (t) =>
          t.date === dateTick &&
          ["completed", "partial_refund", "refunded"].includes(t.status),
      )
      .map(netTransaction);
    const total = todayTxns.reduce((s, t) => s + t.total, 0);
    const cashTotal = todayTxns.reduce(
      (s, t) => s + (t.cashAmount ?? (t.method === "cash" ? t.total : 0)),
      0,
    );
    const mpesaTotal = todayTxns.reduce(
      (s, t) => s + (t.mpesaAmount ?? (t.method === "mpesa" ? t.total : 0)),
      0,
    );
    const creditTotal = todayTxns.reduce(
      (s, t) => s + (t.method === "credit" ? t.total : 0),
      0,
    );
    const totalCost = todayTxns.reduce((s, t) => s + t.cost, 0);
    return {
      total,
      count: todayTxns.length,
      cashTotal,
      mpesaTotal,
      creditTotal,
      grossProfit: todayTxns.reduce((s, t) => s + t.subtotal, 0) - totalCost,
    };
  }, [transactions, dateTick]);

  const topProducts = useMemo(() => {
    const counts: Record<string, number> = {};
    transactions
      .filter((t) =>
        ["completed", "partial_refund", "refunded"].includes(t.status),
      )
      .map(netTransaction)
      .forEach((txn) => {
        txn.items.forEach((item) => {
          counts[item.productId] = (counts[item.productId] ?? 0) + item.qty;
        });
      });
    return Object.entries(counts)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 10)
      .map(([id, qty]) => ({ product: products.find((p) => p.id === id), qty }))
      .filter((e) => e.product);
  }, [transactions, products]);

  const weeklySalesData = useMemo(() => {
    const days: {
      date: string;
      label: string;
      totalSales: number;
      transactions: number;
    }[] = [];
    for (let i = 6; i >= 0; i--) {
      const d = new Date(dateTick + "T12:00:00Z");
      d.setDate(d.getDate() - i);
      const dateStr = d.toISOString().split("T")[0];
      const dayTxns = transactions.filter(
        (t) =>
          t.date === dateStr &&
          ["completed", "partial_refund", "refunded"].includes(t.status),
      );
      const totalSales = dayTxns
        .map(netTransaction)
        .reduce((s, t) => s + t.total, 0);
      const label = d.toLocaleDateString("en-US", { weekday: "short" });
      days.push({
        date: dateStr,
        label,
        totalSales,
        transactions: dayTxns.length,
      });
    }
    return days;
  }, [transactions, dateTick]);

  const pendingApprovals = useMemo(
    () => approvals.filter((a) => a.status === "pending"),
    [approvals],
  );

  return {
    // state
    persistenceError,
    currentUser,
    screen,
    products,
    customers,
    suppliers,
    purchaseOrders,
    staffList,
    tills,
    transactions,
    approvals,
    stockMovements,
    priceChanges,
    promotions,
    auditLog,
    mpesaPayments,
    heldSales,
    stockTake,
    cart,
    selectedCustomerId,
    activeTillId,
    activeTill,
    lowStockProducts,
    expiringProducts,
    categories,
    departments,
    todaySales,
    pendingApprovals,
    topProducts,
    weeklySalesData,
    // actions
    setScreen,
    login,
    logout,
    hasPermission,
    canAccessScreen,
    scanBarcode,
    addToCart,
    updateCartQty,
    removeFromCart,
    clearCart,
    applyCartDiscount,
    holdSale,
    resumeSale,
    deleteHeldSale,
    validatePayment,
    completeSale,
    processRefund,
    processExchange,
    completeExchange,
    voidTransaction,
    approveRequest,
    openTill,
    closeTill,
    cashDrop,
    acknowledgeDrop,
    paidOut,
    addProduct,
    updateProductPrice,
    applyMarkdown,
    addPromotion,
    togglePromotion,
    submitStockAdjustment,
    updateStockTakeCount,
    completeStockTake,
    addSupplier,
    createPO,
    sendPO,
    receivePO,
    scheduleSupplierPayment,
    supplierReturn,
    addCustomer,
    payDebt,
    addStaff,
    saveStaffProfile,
    setStaffActive,
    toggleStaff2FA,
    clockIn,
    clockOut,
    setSelectedCustomerId,
    setActiveTillId,
  };
}

export type Store = ReturnType<typeof useStore>;

export const formatKES = (amount: number): string =>
  "KSh " +
  amount.toLocaleString("en-KE", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  });
