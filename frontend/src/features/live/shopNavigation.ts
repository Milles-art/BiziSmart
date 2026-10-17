import type { Screen } from '../../types';

type Actor = { role: string; permissions: string[] };
const permissions: Partial<Record<Screen, string[]>> = {
  pos: ['sell'], products: ['manage_products'], inventory: ['receive_stock', 'adjust_stock'],
  suppliers: ['manage_settings', 'receive_stock'], tills: ['manage_till'],
  customers: ['manage_customers'], staff: ['manage_users'], reports: ['view_reports'], settings: ['manage_settings'],
};
const modules: Partial<Record<Screen, string>> = {
  inventory: 'inventory', suppliers: 'suppliers', reports: 'reports',
};

// Match existing server authority when exposing the new design's navigation.
// This is presentation only; every request remains authorized by Laravel.
export function canAccessShopScreen(screen: Screen, actor: Actor, features?: Record<string, boolean>): boolean {
  if (screen === 'dashboard' || screen === 'account') return true;
  if (screen === 'banking') return actor.role === 'owner' && features?.banking !== false && features?.suppliers !== false;
  if (screen === 'messaging') return actor.role === 'owner' && features?.messaging !== false;
  if (screen === 'offline') return actor.permissions.includes('sell') && features?.offline !== false;
  return features?.[modules[screen] ?? ''] !== false && (permissions[screen] ?? []).some(permission => actor.permissions.includes(permission));
}
