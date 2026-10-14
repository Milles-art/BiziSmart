/** Preserve the established report CSV escaping, including spreadsheet formula protection. */
export function serializeReportCsv(rows:(string|number)[][]):string {
 const safe=(value:string|number)=>{if(typeof value==='number')return String(value);const text=String(value);return '"'+(/^\s*[=+@-]/.test(text)?"'"+text:text).replace(/"/g,'""')+'"';};
 return '\uFEFF'+rows.map(row=>row.map(safe).join(',')).join('\r\n');
}
export function downloadReportCsv(name:string,rows:(string|number)[][]):void {
 const blob=new Blob([serializeReportCsv(rows)],{type:'text/csv;charset=utf-8'});const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=name;link.click();setTimeout(()=>URL.revokeObjectURL(url),0);
}
