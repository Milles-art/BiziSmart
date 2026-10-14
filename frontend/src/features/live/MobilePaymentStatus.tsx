import { usePaymentCapabilitiesQuery } from './api';

export function MobilePaymentStatus() {
  const status = usePaymentCapabilitiesQuery();
  const text = status.isError ? 'Mobile payment availability could not be checked.'
    : !status.data ? 'Checking mobile payment availability…'
    : status.data.reason === 'merchant_setup_required' ? 'Selcom mobile payments require merchant setup.'
    : 'Selcom mobile payments are available.';
  return <p className="mt-4 text-xs text-slate-400" role="status">{text}</p>;
}



