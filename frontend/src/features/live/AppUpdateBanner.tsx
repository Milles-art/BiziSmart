import { useRegisterSW } from 'virtual:pwa-register/react';
import { RefreshCw } from 'lucide-react';
import { translate } from '../../i18n';

export function AppUpdateBanner() {
  const { needRefresh: [needRefresh, setNeedRefresh], updateServiceWorker } = useRegisterSW();
  if (!needRefresh) return null;
  return <div role="status" className="m-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
    <p>{translate("A newer version of BiziSmart is ready. Refresh so your shop runs the current app.")}</p>
    <div className="flex gap-2">
      <button onClick={() => setNeedRefresh(false)} className="min-h-11 rounded-lg border border-blue-200 px-3 py-2 text-sm font-medium text-blue-700">{translate("Later")}</button>
      <button onClick={() => void updateServiceWorker(true)} className="flex min-h-11 items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white"><RefreshCw className="h-4 w-4" />{translate("Refresh now")}</button>
    </div>
  </div>;
}