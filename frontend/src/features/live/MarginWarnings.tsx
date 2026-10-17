import { translate } from '../../i18n';
import type { MarginWarning } from './api';

const money = (amount: number) => `TSh ${amount.toLocaleString('en-TZ')}`;

/** Renders server-computed S22 margin warnings verbatim. Never calculates costs or margins; cost fields appear only when Laravel sent them (owners). Managers see redacted entries; cashiers receive no array, so nothing renders. Warnings never block the sale. */
export function MarginWarnings({ warnings }: { warnings: MarginWarning[] | undefined | null }) {
  if (!warnings || warnings.length === 0) return null;
  return (
    <div role="note" aria-label={translate('Below-cost lines')} className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
      <p className="font-semibold">{translate('Some lines sell below buying cost. This warning does not change prices or block the sale.')}</p>
      <ul className="mt-2 space-y-1">
        {warnings.map((w, i) => (
          <li key={`${w.batch_id}-${i}`} className="flex justify-between gap-3">
            <span>{w.product_name} · {w.base_qty}</span>
            <span>
              {money(w.net_total)}
              {w.cost_total !== undefined && (
                <> · {translate('Cost:')} {money(w.cost_total)}{w.shortfall !== undefined && <> · {translate('Short by:')} {money(w.shortfall)}</>}</>
              )}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
