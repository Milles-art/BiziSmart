import type { CashSale } from './api';

export interface OfflineSnapshot {
  snapshot_id: string; product_id: string; name: string; barcode: string|null;
  quantity_type:'counted'|'weight'; unit:string; packaging_id:string|null;
  packaging_name:string|null; packaging_version:number|null; conversion_atoms:string;
  unit_price:number; stock:string; proof:string;
}
export interface OfflineLease {
  lease_id:string; device_uid:string; cashier_id:string; issued_at:string; expires_at:string;
  secret:string; shop_name:string; cashier_name:string; branch_name:string; vat_rate:number;
  truncated:boolean; automatic_offers_enabled:false; catalogue:OfflineSnapshot[];
}
export interface OfflineSaleBody {
  lease_id:string; device_uid:string; operation_id:string; captured_at:string; cash_tendered:number;
  items:{snapshot_id:string; proof:string; qty:string}[];
}
export interface OfflineResult {
  id:string; operation_id:string; status:'quarantined'|'committed'|'committed_with_conflict';
  conflicts:string[]; sale:CashSale|null; replayed:boolean;
}
export interface OfflineQueuedSale {
  body:OfflineSaleBody; secret:string; cashier_id:string; shop_name:string;
  display_lines:{name:string; qty:string; unit:string}[]; estimated_total:number;
  result?:OfflineResult;
}
export interface OfflineQuarantine {
  id:string; operation_id:string; captured_at:string|null; conflicts:string[];
  cashier_name:string|null; created_at:string;
}
export interface OfflineReconcileInput {operation_id:string; till_id:string; reason:string}
export interface OfflineReconcileReview extends OfflineReconcileInput {
  offline_id:string; request_fingerprint:string; branch_id:string; till_revision:number;
  total:number; captured_at:string; conflicts:string[]; review_hash:string;
}
export interface OfflineReconcileCommit extends OfflineReconcileInput {review_hash:string; approval_token:string}

export interface OfflineCommittedConflict {id:string;sale_id:string;receipt_number:string;total:number;captured_at:string;conflicts:string[];cashier_name:string|null;owner_reconciled:boolean}
