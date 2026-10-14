import type {SaleReview} from './api';
import type {CartEntry} from './checkoutState';
export function customerDisplaySnapshot(review:SaleReview|null,entries:CartEntry[]=[]){return review?{lines:review.lines.map(line=>({name:line.name,qty:line.qty,unit:line.packaging_name??line.unit,total:line.line_total as number|null})),total:review.total as number|null}:entries.length?{lines:entries.map(line=>({name:line.product.name,qty:line.qty,unit:line.product.packagings?.find(pack=>pack.id===line.packaging_id)?.name??line.product.unit,total:null})),total:null}:null;}
