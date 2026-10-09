import { Badge,SectionCard } from '@/components/ui/Primitives';
import type { Store as StoreType } from '@/store/useStore';
import { Bell,Building2,CreditCard,Globe,Monitor,Printer,Shield,Smartphone,Store,Tablet,Trash2,Users } from 'lucide-react';
import { useState } from 'react';

interface Props { store: StoreType; }

export function Settings(_props: Props) {
  const [language, setLanguage] = useState('en');
  const [whatsappReceipts, setWhatsappReceipts] = useState(true);
  const [autoLogout, setAutoLogout] = useState(true);
  const [twoFactor, setTwoFactor] = useState(true);
  const [notice, setNotice] = useState('');

  const showNotice = (msg: string) => { setNotice(msg); setTimeout(() => setNotice(''), 3000); };

  const devices = [
    { name: 'Front Counter Tablet', device: 'iPad Pro 11"', lastActive: 'Active now', status: 'Active', icon: Tablet },
    { name: 'Till 2 Desktop', device: 'HP All-in-One', lastActive: '10 min ago', status: 'Active', icon: Monitor },
    { name: 'Manager Office', device: 'Dell Latitude', lastActive: '1 hour ago', status: 'Active', icon: Monitor },
    { name: 'Warehouse Scanner', device: 'Zebra TC52', lastActive: 'Yesterday', status: 'Offline', icon: Smartphone },
  ];

  return (
    <div className="space-y-5 p-4 lg:p-6">
      {notice && <div className="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-600">{notice}</div>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <SectionCard title="Business Information">
          <div className="space-y-3">
            {[{ label: 'Business Name', value: 'BiziSmart Supermarket' },{ label: 'Subdomain', value: 'bizismart.biziapps.co.ke' },{ label: 'Currency', value: 'KES (Kenyan Shilling)' },{ label: 'Time Zone', value: 'Africa/Nairobi (EAT)' }].map(f => (
              <div key={f.label} className="flex items-center justify-between border-b border-slate-50 pb-3 last:border-0"><div className="flex items-center gap-3"><Store className="h-4 w-4 text-slate-400" /><span className="text-sm text-slate-600">{f.label}</span></div><span className="text-sm font-medium text-slate-700">{f.value}</span></div>
            ))}
          </div>
        </SectionCard>

        <SectionCard title="Receipt Configuration">
          <div className="space-y-3">
            {[{ label: 'Receipt Type', value: 'Thermal 80mm + PDF' },{ label: 'Header Message', value: 'Thank you for shopping with us!' },{ label: 'Show Logo', value: 'Yes' },{ label: 'Tax Number', value: 'P051234567A' }].map(f => (
              <div key={f.label} className="flex items-center justify-between border-b border-slate-50 pb-3 last:border-0"><div className="flex items-center gap-3"><Printer className="h-4 w-4 text-slate-400" /><span className="text-sm text-slate-600">{f.label}</span></div><span className="text-sm font-medium text-slate-700">{f.value}</span></div>
            ))}
          </div>
        </SectionCard>
      </div>

      <SectionCard title="Language & Localization">
        <div className="flex items-center gap-4">
          <Globe className="h-5 w-5 text-slate-400" />
          <div className="flex gap-2">
            <button onClick={() => { setLanguage('en'); showNotice('Language set to English'); }} className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${language === 'en' ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>English</button>
            <button onClick={() => { setLanguage('sw'); showNotice('Lugha imewekwa: Kiswahili'); }} className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${language === 'sw' ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>Kiswahili</button>
          </div>
          <p className="text-xs text-slate-400">Interface and receipt language</p>
        </div>
      </SectionCard>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <SectionCard title="Payment Methods">
          <div className="space-y-3">
            {[{ name: 'Cash', icon: CreditCard },{ name: 'M-Pesa (STK Push)', icon: Smartphone },{ name: 'Customer Credit', icon: Users },{ name: 'Split Payment', icon: CreditCard }].map(m => {
              const Icon = m.icon;
              return (<div key={m.name} className="flex items-center justify-between rounded-lg border border-slate-100 p-3"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100"><Icon className="h-4 w-4 text-slate-600" /></div><span className="text-sm font-medium text-slate-700">{m.name}</span></div><Badge status="Active" variant="success" /></div>);
            })}
          </div>
        </SectionCard>

        <SectionCard title="Security & Access">
          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg border border-slate-100 p-3"><div className="flex items-center gap-3"><Shield className="h-5 w-5 text-slate-400" /><div><p className="text-sm font-medium text-slate-700">Two-Factor Auth</p><p className="text-xs text-slate-400">Require 2FA for owner/manager accounts</p></div></div><button onClick={() => { setTwoFactor(!twoFactor); showNotice(`2FA ${!twoFactor ? 'enabled' : 'disabled'}`); }} className={`relative h-6 w-11 rounded-full transition-colors ${twoFactor ? 'bg-emerald-500' : 'bg-slate-300'}`}><span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${twoFactor ? 'left-[22px]' : 'left-0.5'}`} /></button></div>
            <div className="flex items-center justify-between rounded-lg border border-slate-100 p-3"><div className="flex items-center gap-3"><Bell className="h-5 w-5 text-slate-400" /><div><p className="text-sm font-medium text-slate-700">Auto Logout</p><p className="text-xs text-slate-400">Log out idle cashiers after 5 min</p></div></div><button onClick={() => setAutoLogout(!autoLogout)} className={`relative h-6 w-11 rounded-full transition-colors ${autoLogout ? 'bg-emerald-500' : 'bg-slate-300'}`}><span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${autoLogout ? 'left-[22px]' : 'left-0.5'}`} /></button></div>
            <div className="flex items-center justify-between rounded-lg border border-slate-100 p-3"><div className="flex items-center gap-3"><Smartphone className="h-5 w-5 text-slate-400" /><div><p className="text-sm font-medium text-slate-700">WhatsApp Receipts</p><p className="text-xs text-slate-400">Send receipts via WhatsApp (opt-in)</p></div></div><button onClick={() => setWhatsappReceipts(!whatsappReceipts)} className={`relative h-6 w-11 rounded-full transition-colors ${whatsappReceipts ? 'bg-emerald-500' : 'bg-slate-300'}`}><span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all ${whatsappReceipts ? 'left-[22px]' : 'left-0.5'}`} /></button></div>
          </div>
        </SectionCard>
      </div>

      <SectionCard title="Subscription & Plan">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="flex items-center gap-4"><div className="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500"><Building2 className="h-6 w-6 text-white" /></div><div><p className="text-sm font-bold text-slate-800">Business Plan</p><p className="text-xs text-slate-400">5 tills · Unlimited products · Multi-tenant</p></div></div>
          <div className="flex items-center gap-3"><Badge status="Active" variant="success" /><span className="text-sm text-slate-600">Renews Nov 8, 2026 · KSh 4,500/mo</span><button className="rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200">Manage Plan</button></div>
        </div>
      </SectionCard>

      <SectionCard title="Approved Devices">
        <div className="space-y-2">
          {devices.map(d => {
            const Icon = d.icon;
            return (<div key={d.name} className="flex items-center justify-between rounded-lg border border-slate-100 p-3"><div className="flex items-center gap-3"><div className="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100"><Icon className="h-4 w-4 text-slate-600" /></div><div><p className="text-sm font-medium text-slate-700">{d.name}</p><p className="text-xs text-slate-400">{d.device} · {d.lastActive}</p></div></div><div className="flex items-center gap-2"><Badge status={d.status} variant={d.status === 'Active' ? 'success' : 'neutral'} /><button onClick={() => showNotice(`${d.name} revoked`)} className="rounded-md p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-500"><Trash2 className="h-4 w-4" /></button></div></div>);
          })}
        </div>
      </SectionCard>
    </div>
  );
}
