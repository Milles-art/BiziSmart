import {useState} from 'react';
import {Dialog} from '@mui/material';
import {translate} from '../../i18n';
import {errorMessage} from './api';
import {useSupplierStatementQuery,useExportSupplierStatementMutation} from './supplierStatementApi';

const money=(amount:number)=>`TSh ${amount.toLocaleString('en-TZ')}`;
const date=(value:Date)=>new Intl.DateTimeFormat('en-CA',{timeZone:'Africa/Dar_es_Salaam',year:'numeric',month:'2-digit',day:'2-digit'}).format(value);
const button='min-h-11 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-40';

export default function SupplierStatement({supplier,onClose}:{supplier:string;onClose:()=>void}) {
 const [from,setFrom]=useState(()=>date(new Date(Date.now()-29*86400000)));
 const [to,setTo]=useState(()=>date(new Date()));
 const [page,setPage]=useState(1);
 const statement=useSupplierStatementQuery({supplier,from,to,page});
 const [download,downloadState]=useExportSupplierStatementMutation();
 const data=statement.currentData;
 async function exportCsv(){
  const request=download({supplier,from,to});
  try {
   const csv=await request.unwrap();
   const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
   const link=document.createElement('a');link.href=url;link.download=`supplier-statement-${supplier}-${from}-${to}.csv`;link.click();URL.revokeObjectURL(url);
  } catch { /* The mutation error remains visible below. */ }
 }
 return <Dialog open onClose={onClose} maxWidth="lg" fullWidth aria-labelledby="supplier-statement-title"><div className="space-y-4 p-5 sm:p-6">
  <div className="flex items-start justify-between gap-3"><div><h2 id="supplier-statement-title" className="text-lg font-bold text-slate-800">{translate('Supplier statement')}</h2>{data&&<p className="text-sm text-slate-500">{data.supplier.name}</p>}</div><button className={button} onClick={onClose}>{translate('Close')}</button></div>
  <p className="text-xs text-slate-500">{translate('Ordered by recorded date in Tanzania time. Physical returns await issued credits; applying a credit is not counted twice.')}</p>
  <div className="flex flex-wrap items-end gap-3"><label className="text-xs text-slate-600">{translate('From')}<input aria-label={translate('Statement from')} type="date" value={from} onChange={e=>{setFrom(e.target.value);setPage(1);}} className="mt-1 block rounded-lg border border-slate-200 px-3 py-2"/></label><label className="text-xs text-slate-600">{translate('To')}<input aria-label={translate('Statement to')} type="date" value={to} onChange={e=>{setTo(e.target.value);setPage(1);}} className="mt-1 block rounded-lg border border-slate-200 px-3 py-2"/></label><button className={button} disabled={!data||statement.isFetching||downloadState.isLoading} onClick={()=>void exportCsv()}>{translate('Export full statement CSV')}</button></div>
  {statement.isFetching&&<p role="status">{translate('Loading statement…')}</p>}
  {(statement.error||downloadState.error)&&<p role="alert" className="text-sm text-red-600">{errorMessage(statement.error??downloadState.error)}</p>}
  {data&&!statement.error&&<><dl className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">{[['Opening balance',data.opening_balance],['Invoice charges',data.charges],['Payments',data.payments],['Issued credits',data.credits],['Closing net balance',data.closing_balance],['Current unpaid invoices',data.outstanding_payable],['Current unapplied credit',data.unapplied_credit]].map(([label,amount])=><div key={String(label)}><dt className="text-xs text-slate-500">{translate(String(label))}</dt><dd className="mt-1 font-semibold tabular-nums text-slate-800">{money(Number(amount))}</dd></div>)}</dl>
  <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Recorded date','Document date','Type','Reference','Charge','Payment','Credit','Balance'].map(label=><th key={label} className="whitespace-nowrap px-3 py-3">{translate(label)}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{data.entries.map(entry=><tr key={entry.id}><td className="whitespace-nowrap px-3 py-3">{new Intl.DateTimeFormat('en-TZ',{timeZone:'Africa/Dar_es_Salaam',dateStyle:'medium',timeStyle:'short'}).format(new Date(entry.at))}</td><td className="px-3 py-3">{entry.document_date??'—'}</td><td className="px-3 py-3">{translate(entry.kind)}</td><td className="break-words px-3 py-3">{entry.reference}</td>{[entry.charge,entry.payment,entry.credit,entry.balance].map((amount,index)=><td key={index} className="whitespace-nowrap px-3 py-3 tabular-nums">{money(amount)}</td>)}</tr>)}</tbody></table>{!data.entries.length&&<p className="p-5 text-sm text-slate-500">{translate('No supplier statement entries in this period.')}</p>}</div>
  <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-xs text-slate-500">{data.meta.total} {translate('entries')} · {translate('Page')} {data.meta.page} / {data.meta.last_page}</p><div className="flex gap-2"><button className={button} disabled={page<=1||statement.isFetching} onClick={()=>setPage(page-1)}>{translate('Previous')}</button><button className={button} disabled={page>=data.meta.last_page||statement.isFetching} onClick={()=>setPage(page+1)}>{translate('Next')}</button></div></div></>}
 </div></Dialog>;
}
