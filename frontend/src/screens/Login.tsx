import { OfflineRecoveryButton } from '../features/live/OfflinePOS';
import { translate, useLanguage } from '../i18n';
import { AlertCircle, ArrowLeft, Eye, EyeOff, Lock, Mail, Store } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import { useDispatch } from 'react-redux';
import { errorMessage, signedIn, signedOut, liveApi } from '../features/live/api';
import { useActivateEmailAccountMutation, useEmailLoginMutation, useEmailOwnerSetupMutation, useForgotEmailPasswordMutation, useResetEmailPasswordMutation, useRegisterShopMutation, useShopRegistrationStatusQuery } from '../features/live/emailAuthApi';

const fieldClass = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100';
type Mode = 'setup' | 'reset' | 'activate' | 'login' | 'forgot' | 'register';
function deviceUid() {
  const key = 'bizismart.device.v1';
  try { const stored = localStorage.getItem(key); if (stored && /^[A-Za-z0-9_-]{8,128}$/.test(stored)) return stored; } catch { /* Storage may be unavailable. */ }
  const value = crypto.randomUUID();
  try { localStorage.setItem(key, value); } catch { /* Keep this page's identifier in memory. */ }
  return value;
}
function privateLink(): { mode: Mode; token: string | null; invalid: boolean } {
  const values = new URLSearchParams(location.hash.slice(1));
  const mode = (['setup', 'reset', 'activate'] as const).find(key => values.has(key));
  if (!mode) return { mode: 'login', token: null, invalid: false };
  const token = values.get(mode) ?? '';
  return { mode, token: /^[a-f0-9]{64}$/.test(token) ? token : null, invalid: !/^[a-f0-9]{64}$/.test(token) };
}
function authFailure(failure: unknown): string {
  const code = (failure as { data?: { error?: { code?: string } } })?.data?.error?.code;
  const messages: Record<string, string> = {
    INVALID_CREDENTIALS: 'The email or password is incorrect.',
    INVALID_CREDENTIAL_TOKEN: 'This private link has expired or already been used. Request a new link.',
    PASSWORD_RESET_UNAVAILABLE: 'Password reset email is unavailable. Contact your administrator.',
    INVALID_INVITATION: 'This setup link is invalid or expired. Ask your administrator for a new private link.',
    OWNER_ALREADY_CONFIGURED: 'This owner account is already set up. Sign in with your email and password.',
    INVALID_RESET_TOKEN: 'This password reset link is invalid or expired. Request a new link.',
    INVALID_ACTIVATION_TOKEN: 'This activation link is invalid or expired. Ask your administrator for a new private link.',
    EMAIL_DELIVERY_UNAVAILABLE: 'Password reset email is unavailable. Contact your administrator.',
    SHOP_ALREADY_EXISTS: 'This shop address is already taken. Sign in with your email and password instead.',
    REGISTRATION_UNAVAILABLE: 'Shop registration is temporarily unavailable. Contact BiziSmart.',
  };
  return messages[code ?? ''] ?? errorMessage(failure);
}
export function PasswordField({ id, label, value, onChange, busy, newPassword = false }: { id: string; label: string; value: string; onChange: (value: string) => void; busy: boolean; newPassword?: boolean }) {
  const [visible, setVisible] = useState(false);
  return <div><label htmlFor={id} className="mb-1 block text-xs font-medium text-slate-500">{translate(label)}</label><div className="flex items-center rounded-lg border border-slate-200 focus-within:border-emerald-400 focus-within:ring-2 focus-within:ring-emerald-100"><input id={id} type={visible ? 'text' : 'password'} value={value} onChange={event => onChange(event.target.value)} className="min-w-0 flex-1 rounded-lg bg-transparent px-3 py-2.5 text-sm text-slate-700 outline-none" required disabled={busy} minLength={newPassword ? 12 : undefined} maxLength={72} autoComplete={newPassword ? 'new-password' : 'current-password'} /><button type="button" aria-label={translate(visible ? 'Hide' : 'Show') + ' ' + translate(label).toLowerCase()} aria-pressed={visible} disabled={busy} onClick={() => setVisible(!visible)} className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-slate-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-500">{visible ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}</button></div></div>;
}
export function Login({onAuthenticated}:{onAuthenticated?:()=>void} = {}) {
  const { language, setLanguage } = useLanguage();
  const dispatch = useDispatch();
  const [link] = useState(privateLink);
  const [mode, setMode] = useState<Mode>(link.mode);
  const [token, setToken] = useState(link.token);
  const [uid] = useState(deviceUid);
  const [name, setName] = useState(''); const [email, setEmail] = useState('');
  const [password, setPassword] = useState(''); const [confirmation, setConfirmation] = useState('');
  const [shopName, setShopName] = useState(''); const [phone, setPhone] = useState('');
  const [error, setError] = useState(link.invalid ? 'This private link is invalid. Ask your administrator for a new link.' : '');
  const [notice, setNotice] = useState(''); const [busy, setBusy] = useState(false);
  const registration = useShopRegistrationStatusQuery(undefined, { skip: link.mode !== 'login' });
  const canRegister = !registration.error && registration.currentData?.available === true;
  const [login] = useEmailLoginMutation(); const [register] = useRegisterShopMutation(); const [setup] = useEmailOwnerSetupMutation(); const [forgot] = useForgotEmailPasswordMutation(); const [reset] = useResetEmailPasswordMutation(); const [activate] = useActivateEmailAccountMutation();
  useEffect(() => { const values = new URLSearchParams(location.hash.slice(1)); if (['setup', 'reset', 'activate'].some(key => values.has(key))) history.replaceState(null, '', location.pathname + location.search); }, []);
  function backToLogin() { setMode('login'); setToken(null); setPassword(''); setConfirmation(''); setName(''); setError(''); setNotice(''); }
  const newPassword = mode === 'setup' || mode === 'reset' || mode === 'activate' || mode === 'register';
  async function submit(event: FormEvent) {
    event.preventDefault(); if (busy || (newPassword && !token && mode !== 'register')) return;
    setError(''); setNotice('');
    if (newPassword && password !== confirmation) { setError('The two passwords must match.'); return; }
    if (mode !== 'forgot' && (new TextEncoder().encode(password).length > 72 || password.includes('\0'))) { setError('Use a password of at most 72 bytes without null characters.'); return; }
    setBusy(true);
    const credentials = { email: email.trim(), password, device_uid: uid, device_name: 'Shop browser' };
    const chosenPassword = { password, password_confirmation: confirmation };
    const pending = mode === 'login' ? login(credentials) : mode === 'register' ? register({ ...credentials, ...chosenPassword, shop_name: shopName.trim(), name: name.trim(), phone: phone.trim() }) : mode === 'setup' ? setup({ ...credentials, ...chosenPassword, invitation_token: token!, name: name.trim() }) : mode === 'forgot' ? forgot({ email: email.trim() }) : mode === 'reset' ? reset({ token: token!, ...chosenPassword }) : activate({ token: token!, email: email.trim(), ...chosenPassword });
    try {
      const result = await pending.unwrap();
      if ('token' in result) { dispatch(signedIn(result)); setToken(null); setEmail(''); setName(''); onAuthenticated?.(); }
      else if (mode === 'forgot') setNotice('If an eligible account uses that email, a password reset link will be sent. Check your inbox and spam folder.');
      else { dispatch(signedOut());dispatch(liveApi.util.resetApiState());setMode('login'); setToken(null); setNotice(mode === 'reset' ? 'Password updated. Sign in with your email and new password.' : 'Account activated. Sign in with your email and password.'); }
    } catch (failure) { setError(authFailure(failure)); }
    finally { pending.reset(); setPassword(''); setConfirmation(''); setBusy(false); }
  }
  const title = mode === 'register' ? 'Create your shop account' : mode === 'setup' ? 'Set up owner account' : mode === 'forgot' ? 'Forgot password?' : mode === 'reset' ? 'Choose a new password' : mode === 'activate' ? 'Activate your account' : 'Sign In';
  const description = mode === 'register' ? 'Create your shop on this address. You become the owner and sign in immediately.' : mode === 'setup' ? 'Create your owner login for this shop. Your private invitation already identifies the shop.' : mode === 'forgot' ? 'Enter your account email to request a private reset link.' : mode === 'reset' ? 'Choose a new password for your account. You will sign in again afterwards.' : mode === 'activate' ? 'Choose the email and password you will use to sign in to this shop.' : 'Enter your email and password to open your shop.';
  const action = mode === 'register' ? 'Create shop account' : mode === 'setup' ? 'Create owner account' : mode === 'forgot' ? 'Send reset link' : mode === 'reset' ? 'Save new password' : mode === 'activate' ? 'Activate account' : 'Sign In';
  return <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-emerald-950 p-4"><div className="w-full max-w-md">
    <div className="mb-7 text-center"><div className="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500 shadow-lg shadow-emerald-500/30"><Store className="h-8 w-8 text-white" /></div><h1 className="text-2xl font-bold text-white">BiziSmart</h1><p className="text-sm text-slate-400">{translate('Supermarket POS System')}</p><button type="button" aria-label={translate('Change language')} onClick={() => setLanguage(language === 'en' ? 'sw' : 'en')} className="mt-3 rounded-lg border border-slate-600 px-3 py-2 text-xs text-slate-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-400">English / Kiswahili</button></div>
    <section aria-labelledby="auth-title" className="rounded-2xl bg-white p-5 shadow-2xl sm:p-7"><h2 id="auth-title" className="mb-1 text-lg font-bold text-slate-800">{translate(title)}</h2><p className="mb-5 text-sm leading-relaxed text-slate-500">{translate(description)}</p>
      {error && <div role="alert" className="mb-4 flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-600"><AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />{translate(error)}</div>}{notice && <p role="status" className="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{translate(notice)}</p>}
      <form onSubmit={submit} className="space-y-4">
        {mode === 'register' && <><div><label htmlFor="shop-name" className="mb-1 block text-xs font-medium text-slate-500">{translate('Shop name')}</label><input id="shop-name" value={shopName} onChange={event => setShopName(event.target.value)} className={fieldClass} required maxLength={120} disabled={busy} autoComplete="organization" /></div>
          <div><label htmlFor="owner-name" className="mb-1 block text-xs font-medium text-slate-500">{translate('Owner name')}</label><input id="owner-name" value={name} onChange={event => setName(event.target.value)} className={fieldClass} required maxLength={100} disabled={busy} autoComplete="name" /></div>
          <div><label htmlFor="owner-phone" className="mb-1 block text-xs font-medium text-slate-500">{translate('Phone number')}</label><input id="owner-phone" type="tel" value={phone} onChange={event => setPhone(event.target.value)} className={fieldClass} required maxLength={32} disabled={busy} autoComplete="tel" placeholder="0714000001" /></div></>}
        {mode === 'setup' && <><div><label htmlFor="owner-name" className="mb-1 block text-xs font-medium text-slate-500">{translate('Owner name')}</label><input id="owner-name" value={name} onChange={event => setName(event.target.value)} className={fieldClass} required maxLength={100} disabled={busy} autoComplete="name" /></div></>}
        {mode !== 'reset' && <div><label htmlFor="login-email" className="mb-1 block text-xs font-medium text-slate-500">{translate('Email address')}</label><div className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 focus-within:border-emerald-400 focus-within:ring-2 focus-within:ring-emerald-100"><Mail className="h-4 w-4 shrink-0 text-slate-400" /><input id="login-email" type="email" value={email} onChange={event => setEmail(event.target.value)} placeholder="name@example.com" className="min-w-0 flex-1 bg-transparent py-2.5 text-sm text-slate-700 outline-none placeholder:text-slate-300" required maxLength={254} disabled={busy} autoComplete="username" autoCapitalize="none" spellCheck={false} /></div></div>}
        {mode !== 'forgot' && <PasswordField id="login-password" label={newPassword ? 'New password' : 'Password'} value={password} onChange={setPassword} busy={busy} newPassword={newPassword} />}
        {newPassword && <><PasswordField id="confirm-password" label="Confirm password" value={confirmation} onChange={setConfirmation} busy={busy} newPassword /><p className="text-xs leading-relaxed text-slate-500">{translate('Use at least 12 characters. A few memorable words work well. You can paste a password from your password manager.')}</p></>}
        {mode === 'login' && <div className="text-right"><button type="button" disabled={busy} onClick={() => { setMode('forgot'); setPassword(''); setError(''); setNotice(''); }} className="min-h-10 rounded-lg text-sm font-medium text-emerald-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-emerald-500">{translate('Forgot password?')}</button></div>}
        <button type="submit" disabled={busy || (newPassword && !token && mode !== 'register')} className="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-500 px-3 py-3 text-sm font-bold text-white transition-colors hover:bg-emerald-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500 disabled:opacity-40"><Lock className="h-4 w-4" />{translate(busy ? 'Please wait…' : action)}</button>
        {mode !== 'login' && <button type="button" disabled={busy} onClick={backToLogin} className="flex min-h-11 w-full items-center justify-center gap-2 rounded-lg text-sm text-slate-600"><ArrowLeft className="h-4 w-4" />{translate('Back to sign in')}</button>}
      </form>{mode === 'login' && <div className="mt-5 border-t border-slate-100 pt-4">{canRegister && <button type="button" disabled={busy} onClick={() => { setMode('register'); setError(''); setNotice(''); }} className="flex min-h-11 w-full items-center justify-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 text-sm font-semibold text-emerald-700"><Store className="h-4 w-4" />{translate('Create your shop account')}</button>}
        <p className="mt-3 text-xs leading-relaxed text-slate-500">{canRegister ? translate('This shop address is not registered yet. Create your shop account to get started.') : translate('First time here? Open the private setup or activation link from your administrator. Existing phone/PIN accounts need a private activation link before email sign-in.')}</p></div>}
    </section><div className="mt-4 text-center"><OfflineRecoveryButton /></div><p className="mt-4 text-center text-xs text-slate-500">{translate('BiziSmart Supermarket')}</p>
  </div></div>;
}
