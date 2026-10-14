import { Box } from "@mui/material";
import { translate, useLanguage } from '../../i18n';
import { TrendingDown, TrendingUp } from "lucide-react";
import type { ReactNode } from "react";

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
    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
      <div className="flex items-start justify-between">
        <div
          className={`flex h-11 w-11 items-center justify-center rounded-lg ${color}`}
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
      <p className="mt-4 text-2xl font-bold text-slate-800">{value}</p>
      <p className="mt-1 text-sm text-slate-500">{translate(title)}</p>
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
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
        <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
        {action}
      </div>
      <div className="p-5">{children}</div>
    </Box>
  );
}

export function SummaryCard({ title, value, emphasis = false }: { title: string; value: string; emphasis?: boolean }) {
  return <div className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">{title}</p><p className={`mt-1 text-xl font-bold ${emphasis ? 'text-emerald-600' : 'text-slate-800'}`}>{value}</p></div>;
}

export function ScreenTabs({ tabs, value, onChange }: { tabs: { id: string; label: string; unavailable?: string }[]; value: string; onChange: (id: string) => void }) {
  useLanguage();
  return <div role="tablist" className="flex w-fit max-w-full flex-wrap gap-1 rounded-lg bg-slate-200 p-1">{tabs.map(tab => <button key={tab.id} role="tab" aria-selected={value === tab.id} disabled={!!tab.unavailable} title={tab.unavailable ? translate(tab.unavailable) : undefined} onClick={() => onChange(tab.id)} className={`rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${value === tab.id ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700'}`}>{translate(tab.label)}</button>)}</div>;
}
