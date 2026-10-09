import type { NavigationStore } from './types';
import type { Screen } from '@/types';
import { Bell,Globe,LogOut,Menu } from 'lucide-react';

const screenTitles: Record<Screen, string> = {
  dashboard: 'Dashboard', pos: 'Point of Sale', products: 'Products & Pricing',
  inventory: 'Inventory Management', suppliers: 'Suppliers & Purchasing',
  tills: 'Tills & Loss Prevention', customers: 'Customers', staff: 'Staff & Roles',
  reports: 'Reports', settings: 'Settings & Admin',
};

interface Props {
  screen: Screen;
  onMenuClick: () => void;
  store: NavigationStore;
}

export function TopBar({ screen, onMenuClick, store }: Props) {
  const user = store.currentUser!;
  const pendingCount = store.pendingApprovals.length;
  const roleColors: Record<string, string> = {
    Owner: 'bg-violet-500', Manager: 'bg-blue-500', Stockkeeper: 'bg-amber-500', Cashier: 'bg-emerald-500',
  };

  return (
    <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:px-6">
      <div className="flex items-center gap-3">
        <button aria-label="Open navigation" onClick={onMenuClick} className="lg:hidden"><Menu className="h-6 w-6 text-slate-600" /></button>
        <h1 className="text-lg font-bold text-slate-800">{screenTitles[screen]}</h1>
        {screen === 'pos' && store.activeTill && (
          <span className="hidden rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-600 sm:inline">
            {store.activeTill.name} · {store.activeTill.cashier}
          </span>
        )}
      </div>
      <div className="flex items-center gap-2 lg:gap-3">
        <button disabled title="Language support is not available yet" className="flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-600">
          <Globe className="h-4 w-4" /><span className="hidden sm:inline">EN / SW</span>
        </button>
        <button disabled aria-label="Notifications unavailable" className="relative rounded-lg p-2 text-slate-600">
          <Bell className="h-5 w-5" />
          {pendingCount > 0 && <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white">{pendingCount}</span>}
        </button>
        <div className="flex items-center gap-2">
          <div className={`flex h-9 w-9 items-center justify-center rounded-full ${roleColors[user.role]} text-sm font-semibold text-white`}>
            {user.name.split(' ').map(n => n[0]).join('').slice(0, 2)}
          </div>
          <button aria-label="Sign out" onClick={store.logout} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-500">
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </div>
    </header>
  );
}
