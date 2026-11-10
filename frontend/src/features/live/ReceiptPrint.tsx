import { translate, useLanguage } from '../../i18n';
import { useRef, useState, type ReactNode } from 'react';
import { Dialog } from '@mui/material';
import type { CashSale } from './api';
import {ReceiptMessaging} from './Messaging';

import {receiptHtml,downloadReceiptPdf} from './receiptOutput';
import type {ReceiptProfile} from './receiptOutput';
import {useBusinessSettingsQuery} from './settingsApi';

export function BusinessReceiptPrint({sale,shopName,compact,trigger}:{sale:CashSale;shopName:string;compact?:boolean;trigger?:ReactNode}){
 const {data,isLoading,error,refetch}=useBusinessSettingsQuery();
 if(isLoading)return <p role="status">{translate('Loading receipt settings…')}</p>;
 if(error)return <p role="alert">{translate('Receipt settings could not be loaded. Try again before printing.')} <button type="button" onClick={()=>void refetch()} className="font-semibold text-emerald-700">{translate('Try again')}</button></p>;
 return <ReceiptPrint sale={sale} shopName={shopName} profile={data} compact={compact} trigger={trigger}/>;
}

export default function ReceiptPrint({ sale, shopName, profile, compact = false, trigger }: { sale: CashSale; shopName: string; profile?:ReceiptProfile; compact?:boolean; trigger?:React.ReactNode }) {
  useLanguage();
  const [open, setOpen] = useState(false);
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const frame = useRef<HTMLIFrameElement>(null);
  const [paper,setPaper]=useState<ReceiptProfile['paper_width']|null>(null);
  const configured=profile?{...profile,paper_width:paper??profile.paper_width}:paper?{name:shopName,address:null,phone:null,tin:null,vrn:null,header:null,footer:null,paper_width:paper}:undefined;
  const print = () => {
    try {
      const target = frame.current?.contentWindow;
      if (!target || !ready) return;
      target.focus(); target.print();
    } catch { setError('Printing is unavailable. Download the PDF and print it from your PDF viewer.'); }
  };
  const download = async () => {
    setBusy(true); setError('');
    try { await downloadReceiptPdf(sale, shopName, undefined, configured); }
    catch { setError('PDF download failed. Try again or use Print receipt.'); }
    finally { setBusy(false); }
  };
  return <>
    {trigger ? <button type="button" onClick={() => { setReady(false); setError(''); setOpen(true); }} className={compact ? 'rounded-lg border border-emerald-200 px-3 py-1.5 text-sm font-semibold text-emerald-700' : undefined}>{trigger}</button> : <button type="button" onClick={() => { setReady(false); setError(''); setOpen(true); }} className="mt-4 w-full rounded-lg border border-emerald-200 px-4 py-3 text-sm font-semibold text-emerald-700">{translate("Print or download receipt")}</button>}
    {sale.customer_id&&<ReceiptMessaging sale={sale}/>}
    <Dialog open={open} onClose={() => setOpen(false)} maxWidth="sm" fullWidth aria-labelledby="receipt-print-title">
      <div className="p-5"><h2 id="receipt-print-title" className="text-lg font-bold text-slate-800">{translate("Receipt preview")}</h2>
        <p className="mt-1 text-xs text-slate-500">{translate("Print this saved receipt or download a PDF. Select your paper size in the printer settings.")}</p>
        <label className="mt-3 block text-sm text-slate-600">{translate('Receipt paper width')}<select value={paper??profile?.paper_width??'80'} onChange={event=>{setReady(false);setPaper(event.target.value as ReceiptProfile['paper_width']);}} className="ml-3 rounded-lg border border-slate-200 p-2"><option value="58">58 mm</option><option value="80">80 mm</option><option value="A4">A4</option></select></label>
        <iframe ref={frame} title={translate("Saved receipt print preview")} srcDoc={receiptHtml(sale, shopName,undefined,configured)} sandbox="allow-same-origin allow-modals" onLoad={() => setReady(true)} className="mt-4 h-96 w-full rounded-lg border border-slate-200 bg-white" />
        {error && <p role="alert" className="mt-3 text-sm text-red-600">{translate(error)}</p>}
        <div className="mt-4 flex flex-wrap gap-3"><button type="button" disabled={!ready} onClick={print} className="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-bold text-white disabled:opacity-40">{translate("Print receipt")}</button><button type="button" disabled={busy} onClick={() => void download()} className="rounded-lg border border-emerald-200 px-4 py-2 text-sm font-semibold text-emerald-700 disabled:opacity-40">{busy ? translate("Preparing PDF…") : translate("Download PDF")}</button><button type="button" onClick={() => setOpen(false)} className="rounded-lg px-4 py-2 text-sm text-slate-600">{translate("Close preview")}</button></div>
      </div>
    </Dialog>
  </>;
}




