import { Dialog } from '@mui/material';
import { OfflinePOS, OfflineRecoveryButton } from './features/live/OfflinePOS';
import { OfflineQuarantine } from './features/live/OfflineQuarantine';
import StaffOperations from './features/live/StaffOperations';
import {Messaging,MessagingRecovery} from './features/live/Messaging';
import { Incentives } from './features/live/Incentives';
import { translate,useLanguage } from './i18n';
import Suppliers from './features/live/Suppliers';
import { Reports } from './features/live/Reports';
import { Pricing } from './features/live/Pricing';
import { CashDrops } from './features/live/CashDrops';
import { OwnerPayments } from './features/live/OwnerPayments';
import { useEffect, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Lock } from 'lucide-react';
import { Sidebar } from './components/layout/Sidebar';
import { TopBar } from './components/layout/TopBar';
import { SectionCard } from './components/ui/Primitives';
import { Login } from './screens/Login';
import { Staff } from './features/live/Staff';
import { Products } from './features/live/Products';
import { Tills } from './features/live/Tills';
import { Inventory } from './features/live/Inventory';
import { Dashboard } from './features/live/Dashboard';
import { POS } from './features/live/POS';
import { Customers } from './features/live/Customers';
import { Devices } from './features/live/Devices';
import { errorMessage, liveApi, signedOut, useMeQuery, useLogoutMutation, type CatalogueFilters, type SessionState } from './features/live/api';
import { useSessionLifetime } from './features/live/useSessionLifetime';
import type { Screen } from './types';
import type { NavigationStore } from './components/layout/types';

const screens: Record<Screen, string[]> = { dashboard: [], pos: ['sell'], products: ['manage_products'],
  inventory: ['receive_stock', 'adjust_stock'], suppliers: ['manage_settings','receive_stock'], tills: ['manage_till'],
  customers: ['manage_customers'], staff: ['manage_users'], reports: ['view_reports'], settings: ['manage_settings'] };

export default function App() {
  const session = useSelector((s: { session: SessionState }) => s.session);
  useSessionLifetime();
  useEffect(() => { try { localStorage.removeItem('bizismart-demo-v2'); } catch { /* Authentication never depends on persisted shop data. */ } }, []);
  return session.token ? <Workspace /> : <Login />;
}

function Workspace() {
  useLanguage();
  const dispatch = useDispatch();
  const unsettled=useSelector((state:{messaging?:{pending:unknown};financial?:{pending:unknown};supplierOperations?:{pending:unknown;bankPending?:unknown};pricing?:{pending:unknown};incentives?:{pending:unknown};offlineReconciliation?:{pending:unknown}})=>Boolean(state.messaging?.pending||state.financial?.pending||state.supplierOperations?.pending||state.supplierOperations?.bankPending||state.pricing?.pending||state.incentives?.pending||state.offlineReconciliation?.pending));
  useEffect(()=>{const warn=(event:BeforeUnloadEvent)=>{if(unsettled){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[unsettled]);
  const { data, error, isLoading, refetch } = useMeQuery();
  const [logout] = useLogoutMutation();
  const [pricingOpen,setPricingOpen]=useState(false);
  const [personalOpen,setPersonalOpen]=useState(false);
  const [offlineOpen,setOfflineOpen]=useState(false);
  const [incentivesOpen,setIncentivesOpen]=useState(false);
  const [messagingOpen,setMessagingOpen]=useState(false);
  const [screen, setScreen] = useState<Screen>('dashboard');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [productFilters, setProductFilters] = useState<CatalogueFilters>({ page: 1 });
  function navigate(value: Screen, filters?: CatalogueFilters) { setProductFilters(filters ?? { page: 1 }); setScreen(value); setSidebarOpen(false); }
  async function leave() {
    const pending = logout();
    try { await pending.unwrap(); } catch { /* Clear local credentials even when the connection fails. */ }
    finally { pending.reset(); dispatch(signedOut()); dispatch(liveApi.util.resetApiState()); }
  }
  if (isLoading || !data) return <div className="flex min-h-screen items-center justify-center bg-slate-100 p-6"><div className="rounded-2xl bg-white p-7 shadow-sm">{error ? <><OfflineRecoveryButton/><p role="alert" className="text-sm text-red-600">{errorMessage(error)}</p><button onClick={refetch} className="mt-4 rounded-lg bg-emerald-500 px-4 py-2 text-sm text-white">Try again</button><button onClick={leave} className="ml-4 text-sm text-slate-500">Sign out</button></> : <p role="status" className="text-sm text-slate-500">Opening your workspace…</p>}</div></div>;
  const screenFeature:Partial<Record<Screen,string>>={inventory:'inventory',suppliers:'suppliers',reports:'reports'};
  const canAccessScreen = (value: Screen) => value === 'dashboard' || (data.features?.[screenFeature[value]??'']!==false&&screens[value].some((p) => data.user.permissions.includes(p)));
  const store: NavigationStore = { currentUser: { ...data.user, role: data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1) }, canAccessScreen,
    setScreen: navigate, logout: leave, pendingApprovals: [], activeTill: null };
  const content = !canAccessScreen(screen) ? <Unavailable title="Access denied" message="Your role does not have access to this screen." />
    : screen === 'staff' ? <Staff actor={data.user} /> : screen === 'products' ? <><div className="flex flex-wrap gap-2 px-4 pt-4 lg:px-6"><button className="rounded-lg border bg-white px-3 py-2 text-sm" onClick={()=>setPricingOpen(false)}>{translate('Catalogue')}</button>{data.user.role==='owner'&&<button className="rounded-lg border bg-white px-3 py-2 text-sm" onClick={()=>setPricingOpen(true)}>{translate('Promotions & scheduled prices')}</button>}</div>{pricingOpen&&data.user.role==='owner'?<Pricing actor={data.user}/>:<Products actor={data.user} initialFilters={productFilters}/>}</> : screen === 'settings' ? <Devices shopName={data.business.name} />
    : screen === 'inventory' ? <Inventory actor={data.user} onNavigate={navigate} />
    : screen === 'suppliers' ? <Suppliers actor={data.user}/>
    : screen === 'reports' ? <Reports/>
    : screen === 'tills' ? <Tills actor={data.user} />
    : screen === 'dashboard' ? <Dashboard shopName={data.business.name} actor={data.user} onNavigate={navigate} />
    : screen === 'pos' ? <POS actor={data.user} shopName={data.business.name} onNavigate={navigate} />
    : screen === 'customers' ? <Customers />
    : <Unavailable title="This tool is not available yet" message="Your shop account is ready. This part of the supermarket workflow is still being implemented." />;
  return <div className="flex h-screen overflow-hidden bg-slate-100">
    <Sidebar screen={screen} onNavigate={navigate} open={sidebarOpen} onClose={() => setSidebarOpen(false)} store={store}/>
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden"><TopBar screen={screen} onMenuClick={() => setSidebarOpen(true)} store={store}/>
      <main className="min-w-0 flex-1 overflow-y-auto">
        <Dialog fullScreen open={offlineOpen}><OfflinePOS actor={data.user} onClose={()=>setOfflineOpen(false)}/></Dialog>
        <div className="flex flex-wrap gap-2 px-4 pt-3 lg:px-6">
          {data.user.permissions.includes('sell')&&data.features?.offline!==false&&<button className="rounded-lg border bg-white px-3 py-2 text-sm" onClick={()=>setOfflineOpen(true)}>{translate('Offline cash checkout')}</button>}
          <button className="rounded-lg border bg-white px-3 py-2 text-sm" aria-pressed={personalOpen} onClick={()=>setPersonalOpen(!personalOpen)}>{translate('My attendance & notifications')}</button>
          {data.user.role==='owner'&&(data.features?.loyalty!==false||data.features?.commissions!==false||data.features?.scales!==false)&&<button className="rounded-lg border bg-white px-3 py-2 text-sm" aria-pressed={incentivesOpen} onClick={()=>setIncentivesOpen(!incentivesOpen)}>{translate('Loyalty, commissions & demand')}</button>}
          {data.user.role==='owner'&&data.features?.messaging!==false&&<button className="rounded-lg border bg-white px-3 py-2 text-sm" aria-pressed={messagingOpen} onClick={()=>setMessagingOpen(!messagingOpen)}>{translate('WhatsApp & SMS delivery')}</button>}
        </div>
        <MessagingRecovery/>
        {personalOpen&&<StaffOperations actor={data.user}/>}
        {incentivesOpen&&data.user.role==='owner'&&(data.features?.loyalty!==false||data.features?.commissions!==false||data.features?.scales!==false)&&<Incentives actor={data.user}/>}
        {messagingOpen&&data.user.role==='owner'&&data.features?.messaging!==false&&<Messaging/>}
        {screen==='dashboard'&&data.user.role==='owner'&&<><OwnerPayments/>{data.features?.offline!==false&&<OfflineQuarantine actor={data.user} onInventory={()=>navigate('inventory')}/>}</>}
        {screen==='tills'&&canAccessScreen('tills')&&<CashDrops actor={data.user}/>}{content}
      </main>
    </div>
  </div>;
}
function Unavailable({ title, message }: { title: string; message: string }) {
  return <div className="p-4 lg:p-6"><SectionCard title={title}><div className="flex flex-col items-center p-10 text-center"><div className="mb-4 rounded-2xl bg-slate-100 p-4"><Lock className="h-8 w-8 text-slate-400" /></div><p className="max-w-lg text-sm text-slate-500">{message}</p></div></SectionCard></div>;
}
