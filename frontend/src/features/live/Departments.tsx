import { translate, useLanguage } from '../../i18n';
import { useState, type FormEvent } from 'react';
import { Dialog } from '@mui/material';
import { X } from 'lucide-react';
import { errorMessage, useDepartmentsQuery, useCreateDepartmentMutation, useUpdateDepartmentMutation, useArchiveDepartmentMutation, type Department } from './api';

const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm outline-none focus:border-emerald-400';
function departmentError(error: unknown) {
  const e = error as { data?: { error?: { code?: string } } };
  return e.data?.error?.code === 'NOT_FOUND' ? 'This department is no longer available. Refresh the list.' : errorMessage(error);
}

export function DepartmentPicker({ label, value, onChange, currentName, emptyLabel = 'Unassigned', compact = false }: { compact?: boolean; label: string; value: string; onChange: (id: string) => void; currentName?: string | null; emptyLabel?: string }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const [chosen, setChosen] = useState<Department | null>(null);
  const { currentData: data, error, isFetching, refetch } = useDepartmentsQuery({ page });
  const rows = data?.departments ?? [];
  const missing = value && !rows.some((row) => row.id === value);
  return <div><label className="block text-xs text-slate-500"><span className={compact ? 'sr-only' : undefined}>{translate(label)}</span><select value={value} onChange={(e) => { setChosen(rows.find((row) => row.id === e.target.value) ?? null); onChange(e.target.value); }} className={inputClass + (compact ? ' !mt-0 !py-2.5' : '')}><option value="">{translate(emptyLabel)}</option>{missing && <option value={value}>{chosen?.id === value ? chosen.name : currentName ?? 'Selected department'}</option>}{rows.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
    {isFetching && <p role="status" className="mt-1 text-xs text-slate-400">{translate("Loading departments…")}</p>}
    {error && <p role="alert" className="mt-1 text-xs text-red-600">{departmentError(error)} <button type="button" onClick={refetch} className="underline">{translate("Retry departments")}</button></p>}
    {data && data.meta.total > data.meta.per_page && <div className="mt-1 flex justify-between gap-2 text-xs text-slate-500"><button type="button" disabled={page === 1 || isFetching} aria-label={`${label}: previous departments`} onClick={() => setPage(page - 1)} className="disabled:opacity-40">{translate("Previous")}</button><span>{translate("Page")} {page}</span><button type="button" disabled={page * data.meta.per_page >= data.meta.total || isFetching} aria-label={`${label}: next departments`} onClick={() => setPage(page + 1)} className="disabled:opacity-40">{translate("Next")}</button></div>}
  </div>;
}

export function Departments({ onClose }: { onClose: () => void }) {
  useLanguage();
  const [page, setPage] = useState(1);
  const { currentData: data, error, isFetching, refetch } = useDepartmentsQuery({ page });
  const [editing, setEditing] = useState<Department | 'new' | null>(null);
  const [name, setName] = useState('');
  const [archive, setArchive] = useState<Department | null>(null);
  const [failure, setFailure] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [create] = useCreateDepartmentMutation(); const [update] = useUpdateDepartmentMutation(); const [remove] = useArchiveDepartmentMutation();
  function edit(row: Department | 'new') { setEditing(row); setName(row === 'new' ? '' : row.name); setFailure(''); setNotice(''); }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editing || busy) return;
    setBusy(true); setFailure('');
    const pending = editing === 'new' ? create({ name }) : update({ id: editing.id, name });
    try { await pending.unwrap(); setEditing(null); setPage(1); setNotice('Department saved.'); }
    catch (e) { setFailure(departmentError(e)); } finally { pending.reset(); setBusy(false); }
  }
  async function confirmArchive() {
    if (!archive || busy) return;
    setBusy(true); setFailure(''); const pending = remove(archive.id);
    try { await pending.unwrap(); setArchive(null); setPage(1); setNotice('Department archived.'); }
    catch (e) { setFailure(departmentError(e)); } finally { pending.reset(); setBusy(false); }
  }
  return <Dialog open onClose={() => { if (!busy) onClose(); }} aria-labelledby="departments-title" maxWidth="sm" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><div className="flex items-center justify-between"><h2 id="departments-title" className="text-lg font-bold text-slate-800">{translate("Manage departments")}</h2><button disabled={busy} aria-label={translate("Close departments")} onClick={onClose} className="text-slate-400"><X className="h-5 w-5" /></button></div><p className="mt-3 text-sm text-slate-500">{translate("Group products by department. Categories remain separate. Reassign or clear all products before archiving a department.")}</p>
    {notice && <p role="status" className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700">{translate(notice)}</p>}
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{departmentError(error)} <button onClick={refetch} className="underline">{translate("Try again")}</button></p>}
    {editing ? <form onSubmit={submit} className="mt-4 space-y-4"><h3 className="font-medium text-slate-700">{editing === 'new' ? translate("Add department") : translate("Rename department")}</h3><label className="block text-xs text-slate-500">{translate("Department name")}<input autoFocus required maxLength={160} disabled={busy} value={name} onChange={(e) => setName(e.target.value)} className={inputClass} /></label>{failure && <p role="alert" className="text-sm text-red-600">{translate(failure)}</p>}<div className="flex gap-3"><button type="button" disabled={busy} onClick={() => { setEditing(null); setFailure(''); }} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} className="flex-1 rounded-xl bg-emerald-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Saving…") : translate("Save department")}</button></div></form> : <>
      {isFetching && <p role="status" className="mt-3 text-sm text-slate-500">{translate("Loading departments…")}</p>}
      {!error && data && <div className="mt-4 space-y-3">{(data.departments ?? []).map((row) => <div key={row.id} aria-label={`Department ${row.name}`} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-3"><div><p className="font-medium text-slate-700">{row.name}</p><p className="text-xs text-slate-500">{row.assigned_products_count ?? '—'} {translate("assigned products, including archived")}</p></div><div className="flex gap-3 text-sm"><button aria-label={`Rename department ${row.name}`} onClick={() => edit(row)} className="text-emerald-600">{translate("Rename")}</button><button aria-label={`Archive department ${row.name}`} onClick={() => { setArchive(row); setFailure(''); setNotice(''); }} className="text-red-500">{translate("Archive")}</button></div></div>)}{!data.departments?.length && <p className="py-3 text-sm text-slate-500">{translate("No departments on this page.")}</p>}<div className="flex justify-between text-sm text-slate-500"><button disabled={page === 1 || isFetching} onClick={() => setPage(page - 1)} className="disabled:opacity-40">{translate("Previous departments")}</button><span>{translate("Page")} {data.meta.page}</span><button disabled={page * data.meta.per_page >= data.meta.total || isFetching} onClick={() => setPage(page + 1)} className="disabled:opacity-40">{translate("Next departments")}</button></div></div>}
      <button onClick={() => edit('new')} className="mt-5 rounded-lg bg-emerald-500 px-4 py-2 text-sm font-medium text-white">{translate("Add department")}</button>
    </>}
    <Dialog open={!!archive} onClose={() => { if (!busy) { setArchive(null); setFailure(''); } }} aria-labelledby="archive-department-title" maxWidth="xs" fullWidth slotProps={{ paper: { sx: { borderRadius: '16px' } } }}><div className="p-6"><h2 id="archive-department-title" className="text-lg font-bold text-slate-800">{translate("Archive")} {archive?.name}?</h2><p className="mt-3 text-sm text-slate-500">{translate("This succeeds only when no products remain assigned, including inactive and archived products.")}</p>{failure && <p role="alert" className="mt-3 text-sm text-red-600">{translate(failure)}</p>}<div className="mt-5 flex gap-3"><button disabled={busy} onClick={() => { setArchive(null); setFailure(''); }} className="flex-1 rounded-xl border border-slate-200 py-2.5 text-sm text-slate-600">{translate("Cancel")}</button><button disabled={busy} onClick={confirmArchive} className="flex-1 rounded-xl bg-red-500 py-2.5 text-sm font-bold text-white">{busy ? translate("Archiving…") : translate("Archive department")}</button></div></div></Dialog>
  </div></Dialog>;
}




