import { OfflineRecoveryButton } from '../features/live/OfflinePOS';
import { translate, useLanguage } from '../i18n';
import { AlertCircle, KeyRound, Lock, Phone, Store } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useDispatch } from 'react-redux';
import { errorMessage, signedIn, useLoginMutation, useSetupOwnerMutation, useVerifyCodeMutation, useChangePinMutation, type Challenge } from '../features/live/api';

const fieldClass = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-400';
function deviceUid() {
  const key = 'bizismart.device.v1';
  let id = localStorage.getItem(key);
  if (!id || !/^[A-Za-z0-9_-]{8,128}$/.test(id)) { id = crypto.randomUUID(); localStorage.setItem(key, id); }
  return id;
}
function invitation() {
  const value = new URLSearchParams(location.hash.slice(1)).get('setup');
  return value && /^[a-f0-9]{64}$/.test(value) ? value : null;
}
export function Login() {
  const { language, setLanguage } = useLanguage();
  const dispatch = useDispatch();
  const [invite, setInvite] = useState(invitation);
  useEffect(() => { if (location.hash.startsWith('#setup=')) history.replaceState(null, '', location.pathname + location.search); }, []);
  const [name, setName] = useState('Owner');
  const [phone, setPhone] = useState('');
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  const [code, setCode] = useState('');
  const [challenge, setChallenge] = useState<Challenge | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [login] = useLoginMutation();
  const [setup] = useSetupOwnerMutation();
  const [verify] = useVerifyCodeMutation();
  const [changePin] = useChangePinMutation();
  const changingPin = challenge?.next_step === 'change_pin';
  const enrolling = challenge?.next_step === 'enroll_totp';
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    setError('');
    if ((invite || changingPin) && pin !== confirm) { setError('The two PINs must match.'); return; }
    setBusy(true);
    let pending;
    try {
      if (changingPin) pending = changePin({ challenge_token: challenge!.challenge_token, new_pin: pin });
      else if (challenge) pending = verify({ challenge_token: challenge.challenge_token, code });
      else {
        const body = { phone, pin, device_uid: deviceUid(), device_name: 'Shop browser' };
        pending = invite ? setup({ ...body, invitation_token: invite, name }) : login(body);
      }
      const result = await pending.unwrap();
      setInvite(null);
      if ('token' in result) { dispatch(signedIn(result)); setChallenge(null); setPhone(''); }
      else setChallenge(result);
    } catch (failure) {
      const err = failure as { data?: { error?: { code?: string } } };
      if (err.data?.error?.code === 'INVALID_CHALLENGE') setChallenge(null);
      if (['OWNER_ALREADY_CONFIGURED', 'INVALID_INVITATION'].includes(err.data?.error?.code ?? '')) setInvite(null);
      setError(errorMessage(failure));
    } finally { pending?.reset(); setPin(''); setConfirm(''); setCode(''); setBusy(false); }
  }
  return <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-emerald-950 p-4">
    <div className="w-full max-w-md">
      <div className="mb-8 text-center">
        <div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500 shadow-lg shadow-emerald-500/30"><Store className="h-8 w-8 text-white" /></div>
        <h1 className="text-2xl font-bold text-white">BiziSmart</h1><p className="text-sm text-slate-400">{translate("Supermarket POS System")}</p>
        <button type="button" aria-label={translate('Change language')} onClick={() => setLanguage(language === 'en' ? 'sw' : 'en')} className="mt-3 rounded-lg border border-slate-600 px-3 py-1 text-xs text-slate-200">English / Kiswahili</button>
      </div>
      <div className="rounded-2xl bg-white p-7 shadow-2xl">
        <h2 className="mb-1 text-lg font-bold text-slate-800">{invite ? translate("Set up owner account") : changingPin ? translate("Choose a new PIN") : enrolling ? translate("Set up your authenticator") : challenge ? translate("Verify your authenticator") : translate("Sign In")}</h2>
        <p className="mb-5 text-sm text-slate-500">{invite ? translate("Choose the phone number and PIN for your shop owner.") : challenge && !changingPin ? translate("Enter the six-digit code from your authenticator app.") : changingPin ? translate("Choose 4–6 digits, different from your temporary PIN.") : translate("Enter your phone number and PIN to continue")}</p>
        {enrolling && <div className="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800"><p>{translate("Add a time-based account in your authenticator using this setup key:")}</p><code className="mt-2 block break-all font-bold select-all">{challenge?.setup_secret}</code><p className="mt-2 text-xs">{translate("Keep this key private.")}</p></div>}
        {error && <div role="alert" className="mb-4 flex items-center gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-600"><AlertCircle className="h-4 w-4 shrink-0" />{translate(error)}</div>}
        <form onSubmit={submit} className="space-y-4">
          {invite && <div><label htmlFor="owner-name" className="mb-1 block text-xs font-medium text-slate-500">{translate("Owner name")}</label><input id="owner-name" value={name} onChange={(e) => setName(e.target.value)} className={fieldClass} required maxLength={100} disabled={busy} autoComplete="name" /></div>}
          {!challenge && <div><label htmlFor="login-phone" className="mb-1 block text-xs font-medium text-slate-500">{translate("Phone Number")}</label><div className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 focus-within:border-emerald-400"><Phone className="h-4 w-4 text-slate-400" /><input id="login-phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="0712345678" className="min-w-0 flex-1 bg-transparent text-sm text-slate-700 outline-none placeholder:text-slate-300" required disabled={busy} autoComplete="tel" /></div></div>}
          {(!challenge || changingPin) && <div><label htmlFor="login-pin" className="mb-1 block text-xs font-medium text-slate-500">{changingPin ? translate("New PIN") : translate("4–6 digit PIN")}</label><div className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 focus-within:border-emerald-400"><KeyRound className="h-4 w-4 text-slate-400" /><input id="login-pin" type="password" inputMode="numeric" pattern="[0-9]{4,6}" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value)} className="min-w-0 flex-1 bg-transparent text-sm text-slate-700 outline-none" required disabled={busy} autoComplete={changingPin || invite ? "new-password" : "current-password"} /></div></div>}
          {(invite || changingPin) && <div><label htmlFor="confirm-pin" className="mb-1 block text-xs font-medium text-slate-500">{translate("Confirm PIN")}</label><input id="confirm-pin" type="password" inputMode="numeric" pattern="[0-9]{4,6}" maxLength={6} value={confirm} onChange={(e) => setConfirm(e.target.value)} className={fieldClass} required disabled={busy} autoComplete="new-password" /></div>}
          {challenge && !changingPin && <div><label htmlFor="auth-code" className="mb-1 block text-xs font-medium text-slate-500">{translate("Authenticator code")}</label><input id="auth-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} className={fieldClass} required disabled={busy} autoComplete="one-time-code" /></div>}
          <button type="submit" disabled={busy} className="mt-5 flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-500 py-3 text-sm font-bold text-white transition-all hover:bg-emerald-600 disabled:opacity-40"><Lock className="h-4 w-4" />{busy ? translate("Checking…") : invite ? translate("Create owner account") : changingPin ? translate("Save PIN") : challenge ? translate("Verify code") : translate("Sign In")}</button>
          {challenge && <button type="button" disabled={busy} onClick={() => { setChallenge(null); setError(''); }} className="w-full text-sm text-slate-500">{translate("Start sign-in again")}</button>}
        </form>
      </div>
      <div className="mt-4 text-center"><OfflineRecoveryButton/></div><p className="mt-4 text-center text-xs text-slate-500">{translate("BiziSmart Supermarket")}</p>
    </div>
  </div>;
}
