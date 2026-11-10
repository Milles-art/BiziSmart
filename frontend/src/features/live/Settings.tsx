import {useState,type ReactNode} from 'react';
import {SectionCard} from '../../components/ui/Primitives';
import {translate,useLanguage} from '../../i18n';
import {Devices} from './Devices';
import {errorMessage} from './api';
import {useBusinessSettingsQuery,useSaveBusinessSettingsMutation,type BusinessProfileInput} from './settingsApi';

export function Settings({shopName,navigation}:{shopName:string;navigation?:ReactNode}){
 useLanguage();
 const {data,error,isLoading}=useBusinessSettingsQuery();
 const [draft,setDraft]=useState<BusinessProfileInput|null>(null);
 const [save,{isLoading:saving}]=useSaveBusinessSettingsMutation();
 const [failure,setFailure]=useState(''); const [saved,setSaved]=useState(false);
 const profile=draft??data;
 async function submit(event:React.FormEvent){
  event.preventDefault(); if(!profile||saving)return;
  setFailure('');setSaved(false);
  const {name,address,phone,tin,vrn,header,footer,paper_width}=profile;
  const request=save({name,address,phone,tin,vrn,header,footer,paper_width});
  try{await request.unwrap();setDraft(null);setSaved(true);}catch(e){setFailure(errorMessage(e));}finally{request.reset();}
 }
 return <><div className="space-y-5 p-4 lg:p-6">{navigation}<div className="workflow-heading"><div><h2>{translate('Shop settings')}</h2><p>{translate('Business details and receipt preferences.')}</p></div></div>
 {isLoading&&<p role="status">{translate('Loading…')}</p>}{(error||failure)&&<p role="alert" className="text-sm text-red-600">{failure||errorMessage(error)}</p>}{saved&&<p role="status" className="text-sm text-emerald-700">{translate('Settings saved.')}</p>}
 {profile&&<form onSubmit={event=>void submit(event)} className="space-y-5"><div className="grid gap-5 lg:grid-cols-2"><SectionCard title={translate('Business Information')}><div className="space-y-4">{(['name','address','phone','tin','vrn'] as const).map(key=><label key={key} className="block text-sm font-medium text-slate-600">{translate(({name:'Business Name',address:'Address',phone:'Phone',tin:'TIN',vrn:'VRN'})[key])}<input required={key==='name'} maxLength={key==='address'?500:key==='name'?160:40} value={profile[key]??''} onChange={event=>{setSaved(false);setDraft({...profile,[key]:event.target.value});}} className="mt-1 w-full rounded-lg border border-slate-200 p-2.5 text-slate-800"/></label>)}<p className="text-xs text-slate-500">TSh · Africa/Dar_es_Salaam (EAT)</p></div></SectionCard>
 <SectionCard title={translate('Receipt Configuration')}><div className="space-y-4">{(['header','footer'] as const).map(key=><label key={key} className="block text-sm font-medium text-slate-600">{translate(key==='header'?'Header message':'Footer message')}<textarea maxLength={300} rows={3} value={profile[key]??''} onChange={event=>{setSaved(false);setDraft({...profile,[key]:event.target.value});}} className="mt-1 w-full rounded-lg border border-slate-200 p-2.5 text-slate-800"/></label>)}<label className="block text-sm font-medium text-slate-600">{translate('Receipt paper width')}<select value={profile.paper_width} onChange={event=>setDraft({...profile,paper_width:event.target.value as BusinessProfileInput['paper_width']})} className="mt-1 w-full rounded-lg border border-slate-200 p-2.5"><option value="58">58 mm</option><option value="80">80 mm</option><option value="A4">A4</option></select></label><p className="text-xs text-slate-500">{translate('Printouts use your current business details. Saved sale totals and tax amounts remain unchanged.')}</p></div></SectionCard></div><button disabled={saving} className="rounded-lg bg-emerald-500 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50">{translate(saving?'Saving…':'Save settings')}</button></form>}</div><Devices shopName={shopName} compact/></>;
}
