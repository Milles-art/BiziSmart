import {useEffect,useState} from 'react';
import {createPortal} from 'react-dom';
import type {SaleReview} from './api';
import type {CartEntry} from './checkoutState';
import {translate} from '../../i18n';
import {customerDisplaySnapshot} from './customerDisplaySnapshot';
export function CustomerDisplay({review,shopName,entries}:{review:SaleReview|null;shopName:string;entries:CartEntry[]}){
 const [display,setDisplay]=useState<Window|null>(null);const snapshot=customerDisplaySnapshot(review,entries);
 const [failure,setFailure]=useState('');
 useEffect(()=>()=>{display?.close();},[display]);
 function open(){setFailure('');const target=window.open('about:blank','_blank','popup,width=900,height=700');if(!target){setFailure('Allow popups for BiziSmart, then open the customer display again.');return;}target.document.title=shopName+' — Customer display';target.document.body.style.cssText='margin:0;font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a';target.opener=null;setDisplay(target);}
 return <><button className="rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-600" onClick={open}>{translate('Open customer display')}</button>{failure&&<p role="alert" className="text-sm text-amber-700">{translate(failure)}</p>}{display&&!display.closed&&createPortal(<main style={{padding:40}}><header style={{fontSize:28,fontWeight:700,color:'#047857'}}>{shopName}</header>{snapshot?<><ul style={{listStyle:'none',padding:0,marginTop:32}}>{snapshot.lines.map((line,i)=><li key={i} style={{display:'flex',justifyContent:'space-between',gap:24,padding:'20px 0',borderBottom:'1px solid #cbd5e1',fontSize:22}}><span>{line.name}<small style={{display:'block',fontSize:16,color:'#64748b'}}>{line.qty} {line.unit}</small></span><span>{line.total===null?translate('Awaiting review'):`TSh ${line.total.toLocaleString('en-TZ')}`}</span></li>)}</ul><footer style={{display:'flex',justifyContent:'space-between',marginTop:32,fontSize:36,fontWeight:700}}><span>{translate('Total')}</span><span>{snapshot.total===null?translate('Awaiting review'):`TSh ${snapshot.total.toLocaleString('en-TZ')}`}</span></footer><p style={{color:'#64748b'}}>{translate('Reviewed total. Payment confirmation appears on your receipt.')}</p></>:<p style={{marginTop:60,fontSize:30}}>{translate('Welcome. Your items will appear after checkout review.')}</p>}</main>,display.document.body)}</>;
}
