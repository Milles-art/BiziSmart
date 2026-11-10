import type {CashSale} from './api';
import type {BusinessProfile} from './settingsApi';
export type ReceiptProfile=Pick<BusinessProfile,'name'|'address'|'phone'|'tin'|'vrn'|'header'|'footer'|'paper_width'>;
import { translate, currentLanguage, type Language } from '../../i18n';
const amount = (value: number) => `TSh ${value.toLocaleString('en-TZ')}`;
const escape = (value: string) => value.replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]!));

/** Only immutable saved sale values are used. No catalogue prices or cart calculations. */
export function receiptRows(sale: CashSale, shopName: string, language: Language = currentLanguage(), profile?:ReceiptProfile): string[] {
  const t = (key: string) => translate(key, language);
  return [profile?.name??shopName, ...[profile?.header,profile?.address,profile?.phone,profile?.tin?`TIN: ${profile.tin}`:null,profile?.vrn?`VRN: ${profile.vrn}`:null].filter((row):row is string=>!!row), sale.receipt_number,
    new Date(sale.occurred_at ?? sale.created_at).toLocaleString(language === 'sw' ? 'sw-TZ' : 'en-TZ', { timeZone: 'Africa/Dar_es_Salaam' }),
    ...sale.lines.flatMap((line) => [line.name, `${line.qty} ${line.packaging_name ?? line.unit} @ ${amount(line.unit_price)}`, ...((line.automatic_discount??0)>0?[`${t('Automatic discount')}: ${amount(line.automatic_discount!)}`]:[]),...((line.loyalty_discount??0)>0?[`${t('Loyalty redemption')}: ${amount(line.loyalty_discount!)}`]:[]),...((line.line_discount ?? line.discount) > 0 ? [`${t('Line discount')}: ${amount(line.line_discount ?? line.discount)}`] : []), ...((line.cart_discount ?? 0) > 0 ? [`${t('Cart discount share')}: ${amount(line.cart_discount!)}`] : []), amount(line.line_total)]),
    `${t('Subtotal')}: ${amount(sale.subtotal)}`, `${t('Discount')}: ${amount(sale.discount)}`, `${t('Total')}: ${amount(sale.total)}`,
    `${t('VAT included')} (${sale.vat_rate}%): ${amount(sale.vat_amount)}`,
    ...(sale.payments ?? []).map((payment) => `${t(payment.method === 'mpesa' ? 'Selcom mobile payment' : payment.method === 'credit' ? 'Customer credit' : 'Cash')}: ${amount(payment.amount)}`),
    `${t('Cash tendered')}: ${amount(sale.cash_tendered)}`, `${t('Change')}: ${amount(sale.change_given)}`,
    t('Keep this receipt for returns.'),
    ...(profile?.footer?[profile.footer]:[]),
  ];
}

export function receiptHtml(sale: CashSale, shopName: string, language: Language = currentLanguage(), profile?:ReceiptProfile): string {
  const selected = language === 'sw' ? 'sw' : 'en';
  const width=profile?.paper_width==='58'?'50mm':profile?.paper_width==='A4'?'175mm':'72mm';
  return `<!doctype html><html lang="${selected}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escape(sale.receipt_number)}</title><style>body{margin:0;background:white;color:#0f172a;font:14px Arial,sans-serif}main{max-width:${width};margin:12px auto;padding:8px}p{overflow-wrap:anywhere;white-space:pre-wrap;margin:8px 0}p:first-child{font-size:18px;font-weight:bold}p:last-child{border-top:1px dashed #94a3b8;padding-top:12px}@media print{@page{margin:4mm}main{margin:0 auto;padding:0}}</style></head><body><main>${receiptRows(sale, shopName, selected,profile).map((row) => `<p>${escape(row)}</p>`).join('')}</main></body></html>`;
}

export async function downloadReceiptPdf(sale: CashSale, shopName: string, language: Language = currentLanguage(), profile?:ReceiptProfile): Promise<void> {
  const { jsPDF } = await import('jspdf');
  const thermal=profile?.paper_width==='58'||profile?.paper_width==='80';
  const width=profile?.paper_width==='58'?58:80;
  const pdf = thermal?new jsPDF({unit:'mm',format:[width,297]}):new jsPDF();
  pdf.setFontSize(11);
  let y = 16;
  for (const row of receiptRows(sale, shopName, language,profile)) {
    const wrapped = pdf.splitTextToSize(row, thermal?width-8:175) as string[];
    for (const text of wrapped) {
      if (y > 280) { pdf.addPage(); y = 16; }
      pdf.text(text, thermal?4:16, y); y += 6;
    }
    y += 2;
  }
  pdf.save(`${sale.receipt_number.replace(/[^a-zA-Z0-9_-]/g, '_')}.pdf`);
}



