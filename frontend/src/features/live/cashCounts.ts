export const denominations = [500, 1000, 2000, 5000, 10000, 20000, 50000] as const;

export function denominationTotal(counts: Record<string, string | number>): number {
  return denominations.reduce((sum, note) => {
    const raw = counts[String(note)];
    if (raw === undefined || raw === '') return sum;
    const count = typeof raw === 'number' ? raw : Number(raw);
    if (!Number.isSafeInteger(count) || count < 0) return sum;
    return sum + note * count;
  }, 0);
}

export function tenderNotes(total: number): number[] {
  if (!Number.isFinite(total) || total <= 0) return [];
  return denominations.filter(note => note >= total);
}

export function roundUpToNote(total: number): number {
  if (!Number.isFinite(total) || total <= 0) return 0;
  const usable = denominations.filter(note => note >= total);
  return usable[0] ?? Math.ceil(total / 1000) * 1000;
}