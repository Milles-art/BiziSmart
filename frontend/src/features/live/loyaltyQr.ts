// Fixed version 3, byte mode, error correction L, mask 0. Card tokens are ASCII.
export function loyaltyQr(token: string): boolean[][] {
  if (!/^BSL:[a-f0-9]{32}$/.test(token)) throw new Error('Invalid loyalty card token.');
  const bits: number[] = []; const append = (n: number, length: number) => { for (let i = length - 1; i >= 0; i--) bits.push((n >>> i) & 1); };
  append(4, 4); append(token.length, 8); for (const c of token) append(c.charCodeAt(0), 8);
  append(0, 4); while (bits.length % 8) bits.push(0);
  const data: number[] = []; for (let i = 0; i < bits.length; i += 8) data.push(bits.slice(i, i + 8).reduce((a, b) => a * 2 + b, 0));
  for (let i = 0; data.length < 55; i++) data.push(i % 2 ? 0x11 : 0xec);
  const multiply = (x: number, y: number) => { let z = 0; for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11d); z ^= ((y >>> i) & 1) * x; } return z; };
  const divisor = Array<number>(15).fill(0); divisor[14] = 1; let root = 1;
  for (let i = 0; i < 15; i++) { for (let j = 0; j < 15; j++) { divisor[j] = multiply(divisor[j], root); if (j + 1 < 15) divisor[j] ^= divisor[j + 1]; } root = multiply(root, 2); }
  const remainder = Array<number>(15).fill(0); for (const b of data) { const factor = b ^ remainder.shift()!; remainder.push(0); for (let i = 0; i < 15; i++) remainder[i] ^= multiply(divisor[i], factor); }
  const payload = [...data, ...remainder].flatMap(b => Array.from({ length: 8 }, (_, i) => (b >>> (7 - i)) & 1));
  const size = 29; const grid = Array.from({ length: size }, () => Array<boolean | null>(size).fill(null));
  const set = (x: number, y: number, value: boolean) => { if (x >= 0 && y >= 0 && x < size && y < size) grid[y][x] = value; };
  for (const [cx, cy] of [[3, 3], [25, 3], [3, 25]]) for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) { const d = Math.max(Math.abs(dx), Math.abs(dy)); set(cx + dx, cy + dy, d !== 2 && d !== 4); }
  for (let y = -2; y <= 2; y++) for (let x = -2; x <= 2; x++) set(22 + x, 22 + y, Math.max(Math.abs(x), Math.abs(y)) !== 1);
  for (let i = 0; i < size; i++) { if (grid[6][i] === null) set(i, 6, i % 2 === 0); if (grid[i][6] === null) set(6, i, i % 2 === 0); }
  const format = 0x77c4; const f = (i: number) => ((format >>> i) & 1) !== 0;
  for (let i = 0; i <= 5; i++) set(8, i, f(i)); set(8, 7, f(6)); set(8, 8, f(7)); set(7, 8, f(8)); for (let i = 9; i < 15; i++) set(14 - i, 8, f(i));
  for (let i = 0; i < 8; i++) set(size - 1 - i, 8, f(i)); for (let i = 8; i < 15; i++) set(8, size - 15 + i, f(i)); set(8, size - 8, true);
  let index = 0;
  for (let right = size - 1; right >= 1; right -= 2) { if (right === 6) right = 5; for (let vertical = 0; vertical < size; vertical++) { const y = ((right + 1) & 2) === 0 ? size - 1 - vertical : vertical; for (let j = 0; j < 2; j++) { const x = right - j; if (grid[y][x] === null) grid[y][x] = ((payload[index++] ?? 0) ^ ((x + y) % 2 === 0 ? 1 : 0)) !== 0; } } }
  return grid as boolean[][];
}
