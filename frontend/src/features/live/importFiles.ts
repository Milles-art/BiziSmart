const MAX_FILE = 2 * 1024 * 1024;
const MAX_EXPANDED = 10 * 1024 * 1024;
export const MAX_IMPORT_ROWS = 200;
export function parseImportCsv(text: string): string[][] {
  if (new TextEncoder().encode(text).byteLength > MAX_FILE) throw new Error('The import file must be at most 2 MB.');
  const rows: string[][] = []; let row: string[] = []; let cell = ''; let quoted = false; let closed = false;
  const pushCell = () => { row.push(cell); cell = ''; closed = false; };
  const pushRow = () => { pushCell(); if (row.some((value) => value.trim() !== '')) rows.push(row); row = []; if (rows.length > MAX_IMPORT_ROWS + 1) throw new Error('Import at most 200 data rows at a time.'); };
  text = text.replace(/^\ufeff/, '');
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) { if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else { quoted = false; closed = true; } } else cell += c; }
    else if (c === '"') { if (cell || closed) throw new Error('Malformed CSV quotation.'); quoted = true; }
    else if (c === ',') pushCell();
    else if (c === '\r' || c === '\n') { if (c === '\r' && text[i + 1] === '\n') i++; pushRow(); }
    else { if (closed) throw new Error('Unexpected text after a quoted CSV cell.'); cell += c; }
  }
  if (quoted) throw new Error('Unclosed CSV quotation.');
  if (cell || row.length || closed) pushRow();
  return validateTable(rows);
}
function validateTable(rows: string[][]): string[][] {
  if (rows.length < 2) throw new Error('The file needs a header row and at least one data row.');
  if (rows.length > MAX_IMPORT_ROWS + 1 || rows.some((row) => row.length > 100 || row.some((cell) => cell.length > 1000))) throw new Error('The import exceeds its row, column or cell limits.');
  if (rows.some((row) => row.some((cell) => /^\s*[=+@]/.test(cell)))) throw new Error('Formula cells are unsupported. Use plain values.');
  return rows;
}
function xml(text: string): Document {
  if (/<!DOCTYPE|<!ENTITY/i.test(text)) throw new Error('XML entities are unsupported.');
  const result = new DOMParser().parseFromString(text, 'application/xml');
  if (result.getElementsByTagName('parsererror').length) throw new Error('Malformed Excel XML.');
  return result;
}
async function inflate(bytes: Uint8Array, expected: number): Promise<Uint8Array> {
  if (typeof DecompressionStream === 'undefined') throw new Error('This browser cannot read compressed Excel files. Save the sheet as CSV.');
  let stream: DecompressionStream;
  try { stream = new DecompressionStream('deflate-raw' as CompressionFormat); } catch { throw new Error('This browser cannot read compressed Excel files. Save the sheet as CSV.'); }
  const reader = new Blob([new Uint8Array(bytes).buffer]).stream().pipeThrough(stream).getReader(); const chunks: Uint8Array[] = []; let length = 0;
  try { while (true) { const chunk = await reader.read(); if (chunk.done) break; length += chunk.value.length; if (length > expected || length > MAX_EXPANDED) throw new Error('Excel archive expansion exceeds its declared size.'); chunks.push(chunk.value); } }
  catch (error) { await reader.cancel().catch(() => undefined); throw error; }
  if (length !== expected) throw new Error('Invalid Excel archive size.');
  const output = new Uint8Array(length); let offset = 0; for (const chunk of chunks) { output.set(chunk, offset); offset += chunk.length; } return output;
}
export async function parseImportXlsx(buffer: ArrayBuffer): Promise<string[][]> {
  if (buffer.byteLength > MAX_FILE) throw new Error('The import file must be at most 2 MB.');
  const data = new DataView(buffer); const bytes = new Uint8Array(buffer); const decoder = new TextDecoder('utf-8', { fatal: true });
  let end = -1;
  for (let offset = Math.max(0, buffer.byteLength - 22); offset >= Math.max(0, buffer.byteLength - 65557); offset--) { if (offset + 22 <= buffer.byteLength && data.getUint32(offset, true) === 0x06054b50) { end = offset; break; } }
  if (end < 0 || data.getUint16(end + 4, true) || data.getUint16(end + 6, true)) throw new Error('Unsupported or malformed Excel archive.');
  const count = data.getUint16(end + 10, true); let position = data.getUint32(end + 16, true); let expanded = 0;
  if (count > 200 || count === 0 || position >= end) throw new Error('The Excel archive contains too many entries or is malformed.');
  const entries = new Map<string, { offset: number; size: number; packed: number; method: number }>();
  for (let index = 0; index < count; index++) {
    if (position + 46 > end || data.getUint32(position, true) !== 0x02014b50) throw new Error('Malformed Excel archive directory.');
    const flags = data.getUint16(position + 8, true); const method = data.getUint16(position + 10, true); const packed = data.getUint32(position + 20, true); const size = data.getUint32(position + 24, true);
    const nameLength = data.getUint16(position + 28, true); const extraLength = data.getUint16(position + 30, true); const commentLength = data.getUint16(position + 32, true); const offset = data.getUint32(position + 42, true);
    if (position + 46 + nameLength + extraLength + commentLength > end || flags & 1 || ![0, 8].includes(method) || [size, packed, offset].includes(0xffffffff)) throw new Error('Encrypted, ZIP64 or unsupported Excel compression.');
    const name = decoder.decode(bytes.subarray(position + 46, position + 46 + nameLength));
    if (name.includes('..') || name.includes('\\') || name.startsWith('/') || entries.has(name)) throw new Error('Unsafe Excel archive entry.');
    if (/vbaProject\.bin$/i.test(name)) throw new Error('Macro-enabled spreadsheets are unsupported.');
    expanded += size; if (expanded > MAX_EXPANDED) throw new Error('Expanded Excel data must be at most 10 MB.');
    entries.set(name, { offset, size, packed, method }); position += 46 + nameLength + extraLength + commentLength;
  }
  const read = async (name: string): Promise<string> => {
    const entry = entries.get(name); if (!entry) throw new Error(`Missing Excel part: ${name}.`);
    const { offset, size, packed, method } = entry;
    if (offset + 30 > end || data.getUint32(offset, true) !== 0x04034b50) throw new Error('Malformed Excel archive entry.');
    const start = offset + 30 + data.getUint16(offset + 26, true) + data.getUint16(offset + 28, true);
    if (start + packed > end || (method === 0 && packed !== size)) throw new Error('Malformed Excel archive length.');
    return decoder.decode(method === 0 ? bytes.subarray(start, start + packed) : await inflate(bytes.subarray(start, start + packed), size));
  };
  const workbook = xml(await read('xl/workbook.xml')); const first = workbook.getElementsByTagName('sheet')[0];
  const relationship = first?.getAttribute('r:id'); const relationships = xml(await read('xl/_rels/workbook.xml.rels'));
  const match = Array.from(relationships.getElementsByTagName('Relationship')).find((node) => node.getAttribute('Id') === relationship);
  let target = match?.getAttribute('Target') ?? ''; if (match?.getAttribute('TargetMode') === 'External') throw new Error('External worksheets are unsupported.');
  target = target.startsWith('/xl/') ? target.slice(1) : `xl/${target}`;
  if (!/^xl\/worksheets\/[a-zA-Z0-9_-]+\.xml$/.test(target)) throw new Error('Unsupported Excel worksheet path.');
  const shared = entries.has('xl/sharedStrings.xml') ? Array.from(xml(await read('xl/sharedStrings.xml')).getElementsByTagName('si'), (entry) => Array.from(entry.getElementsByTagName('t'), (part) => part.textContent ?? '').join('')) : [];
  const sheet = xml(await read(target)); if (sheet.getElementsByTagName('f').length) throw new Error('Formula cells are unsupported. Use plain values.');
  const rows: string[][] = [];
  for (const row of Array.from(sheet.getElementsByTagName('row'))) {
    const values: string[] = [];
    for (const cell of Array.from(row.getElementsByTagName('c'))) {
      const ref = cell.getAttribute('r') ?? ''; const letters = /^([A-Z]+)[0-9]+$/.exec(ref)?.[1]; if (!letters) throw new Error('Invalid Excel cell reference.');
      let column = 0; for (const letter of letters) column = column * 26 + letter.charCodeAt(0) - 64; if (column > 100) throw new Error('Import at most 100 columns.');
      const type = cell.getAttribute('t'); const value = cell.getElementsByTagName('v')[0]?.textContent ?? '';
      if (type === 'e' || type === 'b') throw new Error('Error and boolean Excel cells are unsupported.');
      const text = type === 's' ? shared[Number(value)] : type === 'inlineStr' ? Array.from(cell.getElementsByTagName('t'), (node) => node.textContent ?? '').join('') : value;
      if (text === undefined) throw new Error('Invalid Excel shared string.'); values[column - 1] = text;
    }
    if (values.some((value) => value.trim() !== '')) rows.push(Array.from({ length: values.length }, (_, index) => values[index] ?? ''));
    if (rows.length > MAX_IMPORT_ROWS + 1) throw new Error('Import at most 200 data rows at a time.');
  }
  return validateTable(rows);
}
export async function readImportFile(file: File): Promise<string[][]> {
  if (file.size > MAX_FILE) throw new Error('The import file must be at most 2 MB.');
  if (/\.csv$/i.test(file.name)) return parseImportCsv(await file.text());
  if (/\.xlsx$/i.test(file.name)) return parseImportXlsx(await file.arrayBuffer());
  throw new Error('Choose a CSV or .xlsx file. Older .xls files are unsupported.');
}
