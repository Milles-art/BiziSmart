import { useEffect, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import { ShoppingCart, TrendingUp, Wallet, Package, Lock } from 'lucide-react';
import { Sidebar } from './components/layout/Sidebar';
import { TopBar } from './components/layout/TopBar';
import { SectionCard, StatCard } from './components/ui/Primitives';
import { Login } from './screens/Login';
import { Staff } from './features/live/Staff';
import { Products } from './features/live/Products';
import { Devices } from './features/live/Devices';
import { errorMessage, liveApi, signedOut, useMeQuery, useLogoutMutation, type SessionState } from './features/live/api';
import { useSessionLifetime } from './features/live/useSessionLifetime';
import type { Screen } from './types';
import type { NavigationStore } from './components/layout/types';

const screens: Record<Screen, string[]> = { dashboard: [], pos: ['sell'], products: ['manage_products'],
  inventory: ['receive_stock', 'adjust_stock'], suppliers: ['manage_settings'], tills: ['manage_till'],
  customers: ['manage_customers'], staff: ['manage_users'], reports: ['view_reports'], settings: ['manage_settings'] };

export default function App() {
  const session = useSelector((s: { session: SessionState }) => s.session);
  useSessionLifetime();
  useEffect(() => { try { localStorage.removeItem('bizismart-demo-v2'); } catch { /* Authentication never depends on persisted shop data. */ } }, []);
  return session.token ? <Workspace /> : <Login />;
}

function Workspace() {
  const dispatch = useDispatch();
  const { data, error, isLoading, refetch } = useMeQuery();
  const [logout] = useLogoutMutation();
  const [screen, setScreen] = useState<Screen>('dashboard');
  const [sidebarOpen, setSidebarOpen] = useState(false);
  async function leave() {
    const pending = logout();
    try { await pending.unwrap(); } catch { /* Clear local credentials even when the connection fails. */ }
    finally { pending.reset(); dispatch(signedOut()); dispatch(liveApi.util.resetApiState()); }
  }
  if (isLoading || !data) return <div className="flex min-h-screen items-center justify-center bg-slate-100 p-6"><div className="rounded-2xl bg-white p-7 shadow-sm">{error ? <><p role="alert" className="text-sm text-red-600">{errorMessage(error)}</p><button onClick={refetch} className="mt-4 rounded-lg bg-emerald-500 px-4 py-2 text-sm text-white">Try again</button><button onClick={leave} className="ml-4 text-sm text-slate-500">Sign out</button></> : <p role="status" className="text-sm text-slate-500">Opening your workspace…</p>}</div></div>;
  const canAccessScreen = (value: Screen) => value === 'dashboard' || screens[value].some((p) => data.user.permissions.includes(p));
  const store: NavigationStore = { currentUser: { ...data.user, role: data.user.role.charAt(0).toUpperCase() + data.user.role.slice(1) }, canAccessScreen,
    setScreen, logout: leave, pendingApprovals: [], activeTill: null };
  const content = !canAccessScreen(screen) ? <Unavailable title="Access denied" message="Your role does not have access to this screen." />
    : screen === 'staff' ? <Staff actor={data.user} /> : screen === 'products' ? <Products actor={data.user} /> : screen === 'settings' ? <Devices shopName={data.business.name} />
    : screen === 'dashboard' ? <Dashboard shopName={data.business.name} />
    : screen === 'pos' ? <Unavailable title="Checkout is not available yet" message="Stock and till setup must be completed before recording sales. M-Pesa is unavailable until the payment provider is connected." />
    : <Unavailable title="This tool is not available yet" message="Your shop account is ready. This part of the supermarket workflow is still being implemented." />;
  return <div className="flex h-screen overflow-hidden bg-slate-100"><Sidebar screen={screen} onNavigate={(value) => { setScreen(value); setSidebarOpen(false); }} open={sidebarOpen} onClose={() => setSidebarOpen(false)} store={store} /><div className="flex min-w-0 flex-1 flex-col overflow-hidden"><TopBar screen={screen} onMenuClick={() => setSidebarOpen(true)} store={store} /><main className="flex-1 overflow-y-auto">{content}</main></div></div>;
}
function Dashboard({ shopName }: { shopName: string }) {
  return <div className="space-y-5 p-4 lg:p-6"><h2 className="text-lg font-bold text-slate-800">{shopName}</h2><div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <StatCard title="Today's Sales" value="—" icon={<Wallet className="h-5 w-5 text-white" />} color="bg-emerald-500" />
    <StatCard title="Gross Profit Today" value="—" icon={<TrendingUp className="h-5 w-5 text-white" />} color="bg-blue-500" />
    <StatCard title="Cash + M-Pesa" value="—" icon={<ShoppingCart className="h-5 w-5 text-white" />} color="bg-violet-500" />
    <StatCard title="Low Stock Items" value="—" icon={<Package className="h-5 w-5 text-white" />} color="bg-amber-500" />
  </div><div className="grid grid-cols-1 gap-5 xl:grid-cols-3"><div className="xl:col-span-2"><SectionCard title="Weekly Sales Trend"><div className="flex min-h-60 items-center justify-center p-6 text-center text-sm text-slate-400">Sales reporting will be available after checkout is enabled.</div></SectionCard></div><SectionCard title="Shop access"><div className="space-y-3 p-5 text-sm text-slate-600"><p>Staff and device access are managed through your shop account.</p><p>Financial and stock figures will appear once those workflows are available.</p></div></SectionCard></div></div>;
}
function Unavailable({ title, message }: { title: string; message: string }) {
  return <div className="p-4 lg:p-6"><SectionCard title={title}><div className="flex flex-col items-center p-10 text-center"><div className="mb-4 rounded-2xl bg-slate-100 p-4"><Lock className="h-8 w-8 text-slate-400" /></div><p className="max-w-lg text-sm text-slate-500">{message}</p></div></SectionCard></div>;
}
