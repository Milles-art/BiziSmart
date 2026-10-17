import { Box } from "@mui/material";
import { translate, useLanguage } from '../../i18n';
import { TrendingDown, TrendingUp } from "lucide-react";
import type { KeyboardEvent, ReactNode } from "react";

interface StatCardProps {
  title: string;
  value: string;
  change?: string;
  trend?: "up" | "down";
  icon: ReactNode;
  color: string;
}

export function StatCard({
  title,
  value,
  change,
  trend,
  icon,
  color,
}: StatCardProps) {
  useLanguage();
  return (
    <div className="summary-stat-card flex min-w-0 items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:block sm:p-5">
      <div className="flex shrink-0 items-start justify-between gap-2">
        <div
          className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg sm:h-11 sm:w-11 ${color}`}
        >
          {icon}
        </div>
        {change && (
          <div
            className={`flex items-center gap-1 text-xs font-semibold ${trend === "up" ? "text-emerald-600" : "text-red-600"}`}
          >
            {trend === "up" ? (
              <TrendingUp className="h-3.5 w-3.5" />
            ) : (
              <TrendingDown className="h-3.5 w-3.5" />
            )}
            {change}
          </div>
        )}
      </div>
      <div className="min-w-0 sm:mt-3"><p className="break-words text-lg font-bold tabular-nums text-slate-900 sm:text-2xl">{value}</p>
      <p className="mt-0.5 text-xs leading-snug text-slate-500 sm:text-sm">{translate(title)}</p></div>
    </div>
  );
}

interface BadgeProps {
  status: string;
  variant?: "success" | "warning" | "danger" | "info" | "neutral";
}

const badgeStyles: Record<string, string> = {
  success: "bg-emerald-100 text-emerald-700",
  warning: "bg-amber-100 text-amber-700",
  danger: "bg-red-100 text-red-700",
  info: "bg-blue-100 text-blue-700",
  neutral: "bg-slate-100 text-slate-600",
};

export function Badge({ status, variant = "neutral" }: BadgeProps) {
  useLanguage();
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${badgeStyles[variant]}`}
    >
      {translate(status)}
    </span>
  );
}

interface SectionCardProps {
  title: string;
  children: ReactNode;
  action?: ReactNode;
  className?: string;
}

export function SectionCard({
  title,
  children,
  action,
  className = "",
}: SectionCardProps) {
  return (
    <Box
      className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}
    >
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5 sm:py-3.5">
        <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
        {action}
      </div>
      <div className="p-4 sm:p-5">{children}</div>
    </Box>
  );
}

export function SummaryCard({ title, value, emphasis = false, icon, color='bg-blue-500' }: { title: string; value: string; emphasis?: boolean; icon?: ReactNode; color?: string }) {
  if(icon)return <StatCard title={title} value={value} icon={icon} color={color}/>;
  return <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">{title}</p><p className={`mt-1 text-xl font-bold ${emphasis ? 'text-emerald-600' : 'text-slate-800'}`}>{value}</p></div>;
}

export function ScreenTabs({ tabs, value, onChange }: { tabs: { id: string; label: string; unavailable?: string }[]; value: string; onChange: (id: string) => void }) {
  useLanguage();
  const enabled = tabs.filter(tab => !tab.unavailable);
  function move(event: KeyboardEvent<HTMLButtonElement>, id: string) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || !enabled.length) return;
    event.preventDefault();
    const current = enabled.findIndex(tab => tab.id === id);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + enabled.length) % enabled.length;
    const buttons = event.currentTarget.parentElement?.querySelectorAll<HTMLButtonElement>('[role="tab"]:not(:disabled)');
    buttons?.[next]?.focus();
    onChange(enabled[next].id);
  }
  const selected = enabled.some(tab => tab.id === value) ? value : enabled[0]?.id;
  return <div role="tablist" aria-label={translate('Section navigation')} className="screen-tabs flex w-fit max-w-full gap-1 overflow-x-auto rounded-lg bg-slate-200 p-1">{tabs.map(tab => <button key={tab.id} role="tab" tabIndex={selected === tab.id ? 0 : -1} aria-selected={value === tab.id} disabled={!!tab.unavailable} title={tab.unavailable ? translate(tab.unavailable) : undefined} onKeyDown={event => move(event, tab.id)} onClick={() => onChange(tab.id)} className={`shrink-0 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 sm:px-4 ${value === tab.id ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'}`}>{translate(tab.label)}</button>)}</div>;
}
