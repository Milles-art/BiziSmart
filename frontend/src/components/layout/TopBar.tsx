import { translate, useLanguage } from '../../i18n';
import { useTillsQuery, useCurrentTillQuery } from '../../features/live/api';
import type { NavigationStore } from './types';
import type { Screen } from '@/types';
import { Bell,Globe,LogOut,Menu } from 'lucide-react';

const screenTitles: Record<Screen, string> = {
  dashboard: 'Dashboard', pos: 'Point of Sale', products: 'Products',
  inventory: 'Inventory', suppliers: 'Suppliers',
  tills: 'Tills & Cash', customers: 'Customers', staff: 'Staff',
  reports: 'Reports', settings: 'Settings',
  banking: 'Banking', messaging: 'Messaging', offline: 'Offline Sync',
  account: 'My account',
};

interface Props {
  screen: Screen;
  onMenuClick: () => void;
  store: NavigationStore;
}

export function TopBar({ screen, onMenuClick, store }: Props) {
  const { language, setLanguage } = useLanguage();
  const user = store.currentUser!;
  const tillAccess = store.canAccessScreen('tills');
  const tills = useTillsQuery({ page: 1, status: 'open' }, { skip: !tillAccess });
  const current = useCurrentTillQuery(undefined, { skip: screen !== 'pos' || !tillAccess });
  const ownTill = !current.error ? current.currentData?.till : null;
  const pendingCount = user.role === 'Owner' && !tills.error ? tills.currentData?.meta.owner_review_count ?? 0 : 0;
  const roleColors: Record<string, string> = {
    Owner: 'bg-violet-500', Manager: 'bg-blue-500', Stockkeeper: 'bg-amber-500', Cashier: 'bg-emerald-500',
  };

  return (
    <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 lg:px-6">
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <button aria-label={translate("Open navigation")} onClick={onMenuClick} className="lg:hidden"><Menu className="h-6 w-6 text-slate-600" /></button>
        <h1 className="min-w-0 text-base font-bold text-slate-800 sm:text-lg">{translate(screenTitles[screen])}</h1>
        {screen === 'pos' && ownTill && (
          <span className="hidden rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-600 sm:inline">
            {ownTill.branch_name} · {ownTill.cashier_name}
          </span>
        )}
      </div>
      <div className="flex shrink-0 items-center gap-1 sm:gap-2 lg:gap-3">
        <button type="button" aria-label={translate('Change language')} title={translate('Change language')} onClick={() => setLanguage(language === 'en' ? 'sw' : 'en')} className="flex items-center gap-1.5 rounded-lg px-2 py-2 text-sm font-medium text-slate-600">
          <Globe className="h-4 w-4" /><span className="hidden sm:inline">{translate("EN / SW")}</span>
        </button>
        <button disabled={!tillAccess} aria-label={tillAccess ? translate("Review till alerts") : translate("Till alerts unavailable")} title={tillAccess ? translate("Review till variances") : translate("Your role does not have till access")} onClick={() => store.setScreen('tills')} className="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100 disabled:text-slate-300">
          <Bell className="h-5 w-5" />
          {pendingCount > 0 && <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white">{pendingCount}</span>}
        </button>
        <div className="flex items-center gap-2">
          <button type="button" aria-label={translate('My account')} title={translate('My account')} onClick={()=>store.setScreen('account')} className={`flex h-9 w-9 items-center justify-center rounded-full ${roleColors[user.role]} text-sm font-semibold text-white`}>
            {user.name.split(' ').map(n => n[0]).join('').slice(0, 2)}
          </button>
          <button aria-label={translate("Sign out")} onClick={store.logout} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-500">
            <LogOut className="h-4 w-4" />
          </button>
        </div>
      </div>
    </header>
  );
}
