import {useState} from 'react';
import {useLanguage} from '../../i18n';
import {errorMessage} from './api';
import {usePurchaseSuggestionsQuery,type PurchaseSuggestion} from './purchaseSuggestionApi';

export interface SuggestedOrderLine {product_id:string;name:string;packaging_id:null;qty:string;cost_price:string;batch_number:string;expiry_date:string}
interface Props {suppliers:{id:string;name:string;is_active?:boolean}[];disabled?:boolean;onDraft:(supplier:string,lines:SuggestedOrderLine[])=>void}
const input='mt-1 min-h-11 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm';

/** Collects explicit choices; existing supplier preview/commit owns draft validation and totals. */
export default function PurchaseSuggestions({suppliers,disabled=false,onDraft}:Props){
 const {language}=useLanguage();const text=(en:string,sw:string)=>language==='sw'?sw:en;
 const [supplier,setSupplier]=useState('');const [page,setPage]=useState(1);
 const [selected,setSelected]=useState<Record<string,{line:PurchaseSuggestion;qty:string}>>({});
 const query=usePurchaseSuggestionsQuery({supplier,page},{skip:!supplier});
 const ready=!!query.currentData&&!query.error&&!query.isFetching;
 const count=Object.keys(selected).length;
 return <section aria-labelledby="purchase-suggestions-title" className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5">
  <div><h2 id="purchase-suggestions-title" className="font-semibold text-slate-800">{text('Prepare a low-stock order','Andaa agizo la bidhaa chache')}</h2>
   <p className="mt-1 text-sm text-slate-500">{text('Choose a supplier to see low-stock products previously received from them. Quantities only fill the reorder threshold; adjust them for your shop. Costs are historical, so confirm them with the supplier.','Chagua msambazaji kuona bidhaa chache ulizowahi kupokea kwake. Kiasi kinafikisha kiwango cha kuagiza tu; rekebisha kulingana na duka lako. Bei ni za zamani, zithibitishe kwa msambazaji.')}</p></div>
  <label className="block text-sm text-slate-600">{text('Supplier for low-stock order','Msambazaji wa agizo la bidhaa chache')}<select className={input} value={supplier} disabled={disabled} onChange={e=>{setSupplier(e.target.value);setPage(1);setSelected({});}}><option value="">{text('Choose supplier','Chagua msambazaji')}</option>{suppliers.filter(s=>s.is_active!==false).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
  {query.isFetching&&<p role="status" className="text-sm text-slate-500">{text('Loading supplier stock evidence…','Inapakia taarifa za bidhaa za msambazaji…')}</p>}
  {query.error&&<div role="alert" className="text-sm text-red-700">{errorMessage(query.error)} <button onClick={()=>void query.refetch()} className="underline">{text('Retry low-stock suggestions','Jaribu tena mapendekezo')}</button></div>}
  {ready&&query.currentData?.lines.length===0&&<p className="text-sm text-slate-500">{text('No low-stock products with recorded deliveries from this supplier. You can still prepare an order manually.','Hakuna bidhaa chache zilizowahi kupokelewa kutoka kwa msambazaji huyu. Unaweza kuandaa agizo mwenyewe.')}</p>}
  {ready&&query.currentData?.lines.map(line=><article key={line.product_id} className="rounded-lg border border-slate-200 p-3">
   <label className="flex items-center gap-3 font-semibold text-slate-800"><input type="checkbox" disabled={disabled} checked={!!selected[line.product_id]} onChange={e=>{if(e.target.checked)setSelected(v=>({...v,[line.product_id]:{line,qty:line.suggested_qty??''}}));else setSelected(v=>{const next={...v};delete next[line.product_id];return next;});}}/>{line.name}</label>
   <p className="mt-2 text-sm text-slate-500">{text('Available:','Iliyopo:')} {line.stock} {line.unit} · {text('Reorder threshold:','Kiwango cha kuagiza:')} {line.reorder_level} {line.unit}</p>
   <p className="mt-1 text-sm text-slate-500">{text('Last received cost:','Bei ya mwisho kupokea:')} TSh {line.historical_cost.cost_price.toLocaleString('en-TZ')} / {line.unit} · {line.historical_cost.invoice_date}</p>
   {selected[line.product_id]&&<label className="mt-3 block text-sm text-slate-600">{text('Order quantity for','Kiasi cha kuagiza cha')} {line.name} ({line.unit})<input className={input} disabled={disabled} inputMode="decimal" value={selected[line.product_id].qty} onChange={e=>setSelected(v=>({...v,[line.product_id]:{...v[line.product_id],qty:e.target.value}}))}/></label>}
   {line.suggested_qty===null&&<p className="mt-2 text-xs text-amber-700">{text('Stock is at the threshold. Enter the quantity you actually want to order.','Bidhaa ziko kwenye kiwango cha kuagiza. Weka kiasi unachohitaji kuagiza.')}</p>}
  </article>)}
  {ready&&(query.currentData?.meta.last_page??1)>1&&<nav aria-label="Low-stock supplier pages" className="flex items-center gap-3 text-sm"><button disabled={disabled||page===1} onClick={()=>setPage(p=>p-1)} className="min-h-11 rounded-lg border px-3 disabled:opacity-40">Previous suggestions</button><span>Page {page} of {query.currentData?.meta.last_page}</span><button disabled={disabled||page===(query.currentData?.meta.last_page??1)} onClick={()=>setPage(p=>p+1)} className="min-h-11 rounded-lg border px-3 disabled:opacity-40">Next suggestions</button></nav>}
  {!!count&&<button disabled={disabled||!ready||Object.values(selected).some(row=>!row.qty)} onClick={()=>{onDraft(supplier,Object.values(selected).map(({line,qty})=>({product_id:line.product_id,name:line.name,packaging_id:null,qty,cost_price:String(line.historical_cost.cost_price),batch_number:'',expiry_date:''})));setSelected({});}} className="min-h-11 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">{text('Review selected order lines','Kagua bidhaa za agizo zilizochaguliwa')} ({count})</button>}
  <p className="text-xs text-slate-500">{text('This prepares editable base-unit draft lines. Nothing is ordered, received or paid until you explicitly complete the existing supplier workflow.','Hii inaandaa rasimu inayorekebishika kwa vipimo vya msingi. Hakuna bidhaa zinazoagizwa, kupokelewa au kulipwa mpaka ukamilishe hatua za msambazaji.')}</p>
 </section>;
}
