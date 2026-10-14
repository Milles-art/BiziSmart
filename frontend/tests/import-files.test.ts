// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Blob as NodeBlob } from 'node:buffer';
import { DecompressionStream as NodeDecompressionStream } from 'node:stream/web';
import { deflateRawSync } from 'node:zlib';
import { parseImportCsv, parseImportXlsx } from '../src/features/live/importFiles';

function crc32(bytes: Uint8Array): number { let crc = 0xffffffff; for (const byte of bytes) { crc ^= byte; for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0); } return (crc ^ 0xffffffff) >>> 0; }
function storedZip(files: Record<string, string>, declaredExtra = 0, compressed = false): ArrayBuffer {
  const chunks: Uint8Array[] = []; const directory: Uint8Array[] = []; let offset = 0;
  const encoder = new TextEncoder();
  for (const [path, content] of Object.entries(files)) {
    const name = encoder.encode(path); const bytes = encoder.encode(content);
    const packed = compressed ? deflateRawSync(bytes) : bytes;
    const local = new Uint8Array(30 + name.length + packed.length); const header = new DataView(local.buffer);
    header.setUint32(0, 0x04034b50, true); header.setUint16(4, 20, true); header.setUint16(8, compressed ? 8 : 0, true); header.setUint32(14, crc32(bytes), true); header.setUint32(18, packed.length, true); header.setUint32(22, bytes.length, true); header.setUint16(26, name.length, true); local.set(name, 30); local.set(packed, 30 + name.length); chunks.push(local);
    const central = new Uint8Array(46 + name.length); const info = new DataView(central.buffer);
    info.setUint32(0, 0x02014b50, true); info.setUint16(4, 20, true); info.setUint16(6, 20, true); info.setUint16(10, compressed ? 8 : 0, true); info.setUint32(16, crc32(bytes), true); info.setUint32(20, packed.length, true); info.setUint32(24, bytes.length + declaredExtra, true); info.setUint16(28, name.length, true); info.setUint32(42, offset, true); central.set(name, 46); directory.push(central); offset += local.length;
  }
  const directorySize = directory.reduce((sum, chunk) => sum + chunk.length, 0); const end = new Uint8Array(22); const info = new DataView(end.buffer);
  info.setUint32(0, 0x06054b50, true); info.setUint16(8, directory.length, true); info.setUint16(10, directory.length, true); info.setUint32(12, directorySize, true); info.setUint32(16, offset, true);
  const output = new Uint8Array(offset + directorySize + 22); let at = 0; for (const chunk of [...chunks, ...directory, end]) { output.set(chunk, at); at += chunk.length; } return output.buffer;
}
const excel = (sheet: string) => ({ 'xl/workbook.xml': '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Stock" r:id="rId1" /></sheets></workbook>', 'xl/_rels/workbook.xml.rels': '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml" /></Relationships>', 'xl/worksheets/sheet1.xml': sheet });
afterEach(() => vi.unstubAllGlobals());
describe('bounded import file reader', () => {
  it('reads BOM, quoted commas/newlines, leading-zero barcodes and exact decimal text', () => {
    expect(parseImportCsv('\ufeffbarcode,qty,note\r\n00123,0.750,"line 1\nline ""2"""\r\n')).toEqual([['barcode', 'qty', 'note'], ['00123', '0.750', 'line 1\nline "2"']]);
  });
  it('rejects malformed CSV, formulas, excess rows and oversized input', () => {
    expect(() => parseImportCsv('a,b\n"open,x')).toThrow('Unclosed'); expect(() => parseImportCsv('a\n=HYPERLINK()')).toThrow('Formula');
    expect(() => parseImportCsv('a\n' + '1\n'.repeat(201))).toThrow('200'); expect(() => parseImportCsv('a'.repeat(2 * 1024 * 1024 + 1))).toThrow('2 MB');
  });
  it('reads the first XLSX worksheet with sparse columns and inline strings', async () => {
    const sheet = '<worksheet><sheetData><row><c r="A1" t="inlineStr"><is><t>barcode</t></is></c><c r="C1" t="inlineStr"><is><t>qty</t></is></c></row><row><c r="A2" t="inlineStr"><is><t>00123</t></is></c><c r="C2"><v>0.750</v></c></row></sheetData></worksheet>';
    await expect(parseImportXlsx(storedZip(excel(sheet)))).resolves.toEqual([['barcode', '', 'qty'], ['00123', '', '0.750']]);
  });
  it('rejects formulas, XML entities, archive expansion bombs, macros and malformed archives', async () => {
    await expect(parseImportXlsx(storedZip(excel('<worksheet><c r="A1"><f>1+1</f><v>2</v></c></worksheet>')))).rejects.toThrow('Formula');
    await expect(parseImportXlsx(storedZip(excel('<!DOCTYPE x><worksheet/>')))).rejects.toThrow('entities');
    await expect(parseImportXlsx(storedZip(excel('<worksheet/>'), 10 * 1024 * 1024))).rejects.toThrow('10 MB');
    await expect(parseImportXlsx(storedZip({ ...excel('<worksheet/>'), 'xl/vbaProject.bin': 'macro' }))).rejects.toThrow('Macro');
    await expect(parseImportXlsx(new ArrayBuffer(3))).rejects.toThrow('malformed');
  });
  it('reads actual deflate-compressed XLSX entries with shared strings and exact text quantities', async () => {
    vi.stubGlobal('Blob', NodeBlob); vi.stubGlobal('DecompressionStream', NodeDecompressionStream);
    const sheet = '<worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row><c r="A2" t="s"><v>2</v></c><c r="B2"><v>0.750</v></c></row></sheetData></worksheet>';
    const files = { ...excel(sheet), 'xl/sharedStrings.xml': '<sst><si><t>barcode</t></si><si><t>qty</t></si><si><t>00123</t></si></sst>' };
    await expect(parseImportXlsx(storedZip(files, 0, true))).resolves.toEqual([['barcode', 'qty'], ['00123', '0.750']]);
  });
  it('stops actual inflation when the stream exceeds a forged declared uncompressed size', async () => {
    vi.stubGlobal('Blob', NodeBlob); vi.stubGlobal('DecompressionStream', NodeDecompressionStream);
    await expect(parseImportXlsx(storedZip(excel('<worksheet/>'), -5, true))).rejects.toThrow('expansion exceeds');
  });
  it('provides an explicit CSV fallback when native deflate decompression is unavailable', async () => {
    vi.stubGlobal('DecompressionStream', undefined);
    await expect(parseImportXlsx(storedZip(excel('<worksheet/>'), 0, true))).rejects.toThrow('Save the sheet as CSV');
  });
});
