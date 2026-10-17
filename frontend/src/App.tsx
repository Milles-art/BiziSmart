import { OfflinePOS, OfflineRecoveryButton } from './features/live/OfflinePOS';
import { OfflineQuarantine } from './features/live/OfflineQuarantine';
import StaffOperations from './features/live/StaffOperations';
import {Messaging,MessagingRecovery} from './features/live/Messaging';
import { translate,useLanguage } from './i18n';
import Suppliers from './features/live/Suppliers';
import { Reports } from './features/live/Reports';
import { Banking } from './features/live/Banking';
import { canAccessShopScreen } from './features/live/shopNavigation';
import { Pricing } from './features/live/Pricing';
import { CashDrops } from './features/live/CashDrops';
import { OwnerPayments } from './features/live/OwnerPayments';
import { useEffect, useRef, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { Lock } from 'lucide-react';
import { Sidebar } from './components/layout/Sidebar';
import { TopBar } from './components/layout/TopBar';
import { SectionCard, ScreenTabs } from './components/ui/Primitives';
import { Login } from './screens/Login';
import { StaffWorkspace, SettingsWorkspace } from './features/live/WorkspaceSections';
import { Products } from './features/live/Products';
import { Tills } from './features/live/Tills';
import { Inventory } from './features/live/Inventory';
import { Dashboard } from './features/live/Dashboard';
import { POS } from './features/live/POS';
import { Customers } from './features/live/Customers';
import { errorMessage, liveApi, signedOut, useMeQuery, useLogoutMutation, type CatalogueFilters, type SessionState } from './features/live/api';
import { useSessionLifetime } from './features/live/useSessionLifetime';
import type { Screen } from './types';
import type { NavigationStore } from './components/layout/types';

export default function App() {
  const session = useSelector((s: { session: SessionState }) => s.session);
  const hasPrivateAuthLink = () => ['setup','reset','activate'].some(key => new URLSearchParams(location.hash.slice(1)).has(key));
  const [privateAuthFlow, setPrivateAuthFlow] = useState(hasPrivateAuthLink);
  const [authLinkVersion, setAuthLinkVersion] = useState(0);
  useSessionLifetime();
  useEffect(() => {
    const openAuth = () => { if (['setup','reset','activate'].some(key => new URLSearchParams(location.hash.slice(1)).has(key))) { setPrivateAuthFlow(true);setAuthLinkVersion(value=>value+1); } };
    window.addEventListener('hashchange',openAuth);
    return()=>window.removeEventListener('hashchange',openAuth);
  },[]);
  useEffect(() => { try { localStorage.removeItem('bizismart-demo-v2'); } catch { /* Authentication never depends on persisted shop data. */ } }, []);
  return !privateAuthFlow && session.token ? <Workspace /> : <Login key={authLinkVersion} onAuthenticated={()=>setPrivateAuthFlow(false)} />;
}

function Workspace() {
  useLanguage();
  const dispatch = useDispatch();
  const [offlineLocked,setOfflineLocked]=useState(false);
  const [navigationNotice,setNavigationNotice]=useState('');
  const unsettled=useSelector((state:{messaging?:{pending:unknown};financial?:{pending:unknown};supplierOperations?:{pending:unknown;bankPending?:unknown};pricing?:{pending:unknown};incentives?:{pending:unknown};offlineReconciliation?:{pending:unknown};staffOperations?:{pending:unknown}})=>Boolean(state.messaging?.pending||state.financial?.pending||state.supplierOperations?.pending||state.supplierOperations?.bankPending||state.pricing?.pending||state.incentives?.pending||state.offlineReconciliation?.pending||state.staffOperations?.pending));
  useEffect(()=>{const warn=(event:BeforeUnloadEvent)=>{if(unsettled){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[unsettled]);
  const { data, error, isLoading, refetch } = useMeQuery();
  const [logout] = useLogoutMutation();
  const [pricingOpen,setPricingOpen]=useState(false);
  const [screen, setScreen] = useState<Screen>('dashboard');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const mainRef = useRef<HTMLElement>(null);
  const [productFilters, setProductFilters] = useState<CatalogueFilters>({ page: 1 });
  function navigate(value: Screen, filters?: CatalogueFilters) { if(offlineLocked){setNavigationNotice('Finish the pending offline operation before leaving this screen.');setSidebarOpen(false);return;}setNavigationNotice('');setProductFilters(filters ?? { page: 1 }); setScreen(value); setSidebarOpen(false); if(mainRef.current)mainRef.current.scrollTop=0; }
  async function leave() {
    if(offlineLocked){setNavigationNotice('Finish the pending offline operation before leaving this screen.');return;}
    const pending = logout();
    try { await pending.unwrap(); } catch { /* Clear local credentials even when the connection fails. */ }
    finally { pending.reset(); dispatch(signedOut()); dispatch(liveApi.util.resetApiState()); }
  }
  if (isLoading || !data) return <div className="flex min-h-screen items-center justify-center bg-slate-100 p-6"><div className="rounded-2xl bg-white p-7 shadow-sm">{error ? <><OfflineRecoveryButton/><p role="alert" className="text-sm text-red-600">{errorMessage(error)}</p><button onClick={refetch} className="mt-4 rounded-lg bg-emerald-500 px-4 py-2 text-sm text-white">Try again</button><button onClick={leave} className="ml-4 text-sm text-slate-500">Sign out</button></> : <p role="status" className="text-sm text-slate-500">Opening your workspace…</p>}</div></div>;
  const canAccessScreen = (value: Screen) => canAccessShopScreen(value, data.user, data.features);
  const store: NavigationStore = { currentUser: { ...data.user, role: data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1) }, canAccessScreen,
    setScreen: navigate, logout: leave, pendingApprovals: [], activeTill: null };
  const content = !canAccessScreen(screen) ? <Unavailable title="Access denied" message="Your role does not have access to this screen." />
    : screen === 'staff' ? <StaffWorkspace actor={data.user} features={data.features}/>
    : screen === 'account' ? <StaffOperations actor={data.user}/>
    : screen === 'products' ? pricingOpen&&data.user.role==='owner'&&data.features?.promotions!==false?<><div className="px-4 pt-4 lg:px-6"><ScreenTabs value="promotions" onChange={value=>{if(value==='catalogue')setPricingOpen(false);}} tabs={[{id:'catalogue',label:'Catalogue'},{id:'promotions',label:'Promotions & Pricing'}]}/></div><Pricing actor={data.user}/></>:<Products actor={data.user} initialFilters={productFilters} onPricing={data.user.role==='owner'&&data.features?.promotions!==false?()=>{setPricingOpen(true);if(mainRef.current)mainRef.current.scrollTop=0;}:undefined}/>
    : screen === 'settings' ? <SettingsWorkspace actor={data.user} shopName={data.business.name} features={data.features}/>
    : screen === 'inventory' ? <Inventory actor={data.user} onNavigate={navigate} />
    : screen === 'suppliers' ? <Suppliers actor={data.user}/>
    : screen === 'reports' ? <Reports/>
    : screen === 'banking' ? <div className="space-y-5 p-4 lg:p-6"><Banking/></div>
    : screen === 'messaging' ? <Messaging/>
    : screen === 'offline' ? <><OfflinePOS actor={data.user} onClose={()=>navigate('dashboard')} onLockChange={setOfflineLocked}/>{data.user.role==='owner'&&<OfflineQuarantine actor={data.user} onInventory={()=>navigate('inventory')}/>}</>
    : screen === 'tills' ? <><Tills actor={data.user} onReports={canAccessScreen('reports')?()=>navigate('reports'):undefined}/><div className="px-4 pb-4 lg:px-6"><CashDrops actor={data.user}/></div></>
    : screen === 'dashboard' ? <Dashboard shopName={data.business.name} actor={data.user} onNavigate={navigate} />
    : screen === 'pos' ? <POS actor={data.user} shopName={data.business.name} onNavigate={navigate} />
    : screen === 'customers' ? <Customers />
    : <Unavailable title="This tool is not available yet" message="Your shop account is ready. This part of the supermarket workflow is still being implemented." />;
  return <div className="biz-workspace flex h-screen overflow-hidden bg-slate-100">
    <Sidebar screen={screen} onNavigate={navigate} open={sidebarOpen} onClose={() => setSidebarOpen(false)} store={store}/>
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden"><TopBar screen={screen} onMenuClick={() => setSidebarOpen(true)} store={store}/>
      <main ref={mainRef} className="workspace-content min-w-0 flex-1 overflow-y-auto">
        {offlineLocked&&navigationNotice&&<p role="alert" className="m-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{translate(navigationNotice)}</p>}
        <MessagingRecovery/>
        {screen==='dashboard'&&data.user.role==='owner'&&<><OwnerPayments/>{data.features?.offline!==false&&<OfflineQuarantine actor={data.user} onInventory={()=>navigate('inventory')}/>}</>}
        {content}
      </main>
    </div>
  </div>;
}
function Unavailable({ title, message }: { title: string; message: string }) {
  return <div className="p-4 lg:p-6"><SectionCard title={title}><div className="flex flex-col items-center p-10 text-center"><div className="mb-4 rounded-2xl bg-slate-100 p-4"><Lock className="h-8 w-8 text-slate-400" /></div><p className="max-w-lg text-sm text-slate-500">{message}</p></div></SectionCard></div>;
}
