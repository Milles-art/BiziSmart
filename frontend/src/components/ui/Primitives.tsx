import { Box } from "@mui/material";
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
      <p className="mt-1 text-sm text-slate-500">{title}</p>
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
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${badgeStyles[variant]}`}
    >
      {status}
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
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
        <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
        {action}
      </div>
      <div className="p-5">{children}</div>
    </Box>
  );
}
