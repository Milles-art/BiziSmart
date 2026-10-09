import type { Screen } from '../../types';
export interface NavigationStore {
  currentUser: { id: string; name: string; role: string };
  canAccessScreen: (screen: Screen) => boolean;
  setScreen: (screen: Screen) => void;
  logout: () => void;
  pendingApprovals: unknown[];
  activeTill?: { name: string; cashier: string } | null;
}
