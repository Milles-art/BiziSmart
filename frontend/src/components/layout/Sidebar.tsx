import { translate, useLanguage } from '../../i18n';
import type { NavigationStore } from './types';
import type { Screen } from '@/types';
import {
BarChart3,
Boxes,
Calculator,
LayoutDashboard,
Landmark,
MessageSquare,
CloudOff,
Package,
Settings as SettingsIcon,
ShoppingCart,
Store,
Truck,
UserCog,
Users,
X,
} from 'lucide-react';

const allNavItems: { id: Screen; label: string; icon: typeof LayoutDashboard }[] = [
  { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { id: 'pos', label: 'Point of Sale', icon: ShoppingCart },
  { id: 'products', label: 'Products', icon: Package },
  { id: 'inventory', label: 'Inventory', icon: Boxes },
  { id: 'suppliers', label: 'Suppliers', icon: Truck },
  { id: 'tills', label: 'Tills & Cash', icon: Calculator },
  { id: 'customers', label: 'Customers', icon: Users },
  { id: 'staff', label: 'Staff', icon: UserCog },
  { id: 'reports', label: 'Reports', icon: BarChart3 },
  { id: 'banking', label: 'Banking', icon: Landmark },
  { id: 'messaging', label: 'Messaging', icon: MessageSquare },
  { id: 'offline', label: 'Offline Sync', icon: CloudOff },
  { id: 'settings', label: 'Settings', icon: SettingsIcon },
];

const roleColors: Record<string, string> = {
  Owner: 'bg-violet-500', Manager: 'bg-blue-500', Stockkeeper: 'bg-amber-500', Cashier: 'bg-emerald-500',
};

interface Props {
  screen: Screen;
  onNavigate: (s: Screen) => void;
  open: boolean;
  onClose: () => void;
  store: NavigationStore;
}

export function Sidebar({ screen, onNavigate, open, onClose, store }: Props) {
  useLanguage();
  const visibleItems = allNavItems.filter(item => store.canAccessScreen(item.id));
  const user = store.currentUser!;

  return (
    <>
      {open && <div className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={onClose} />}
      <aside className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-slate-900 text-slate-300 transition-transform lg:static lg:translate-x-0 ${open ? "translate-x-0" : "-translate-x-full"}`}>
        <div className="flex items-center justify-between border-b border-slate-800 px-5 py-4">
          <div className="flex items-center gap-2.5">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-500">
              <Store className="h-5 w-5 text-white" />
            </div>
            <div>
              <span className="text-base font-bold text-white">BiziSmart</span>
              <span className="block text-[10px] uppercase tracking-wider text-slate-500">{translate("Supermarket POS")}</span>
            </div>
          </div>
          <button onClick={onClose} className="lg:hidden"><X className="h-5 w-5" /></button>
        </div>
        <nav className="flex-1 space-y-1 overflow-y-auto px-3 py-4">
          {visibleItems.map((item) => {
            const Icon = item.icon;
            const active = screen === item.id;
            return (
              <button
                key={item.id}
                aria-current={active ? 'page' : undefined}
                onClick={() => onNavigate(item.id)}
                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${active ? 'bg-emerald-500 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-white'}`}
              >
                <Icon className="h-5 w-5 shrink-0" />
                {translate(item.label)}
              </button>
            );
          })}
        </nav>
        <div className="border-t border-slate-800 p-4">
          <div className="flex items-center gap-3">
            <div className={`flex h-9 w-9 items-center justify-center rounded-full ${roleColors[user.role]} text-sm font-semibold text-white`}>
              {user.name.split(' ').map(n => n[0]).join('').slice(0, 2)}
            </div>
            <button type="button" aria-label={translate('My account')} onClick={()=>onNavigate('account')} className="min-w-0 flex-1 text-left">
              <p className="truncate text-sm font-medium text-white">{user.name}</p>
              <p className="truncate text-xs text-slate-500">{translate(user.role)}</p>
            </button>
            <button onClick={store.logout} className="rounded-md px-2 py-1 text-xs text-slate-400 hover:bg-slate-800 hover:text-white">
              {translate("Logout")}
            </button>
          </div>
        </div>
      </aside>
    </>
  );
}
