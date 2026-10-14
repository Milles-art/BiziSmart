import { translate, useLanguage } from '../../i18n';
import { useEffect, useRef, useState } from 'react';
import { Camera, X } from 'lucide-react';
import { Dialog } from '@mui/material';

interface BarcodeResult { rawValue: string }
interface Detector { detect(source: HTMLVideoElement): Promise<BarcodeResult[]> }
interface DetectorConstructor { new(options?: { formats: string[] }): Detector; getSupportedFormats?(): Promise<string[]> }
type ScannerWindow = Window & { BarcodeDetector?: DetectorConstructor };

const preferredFormats = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];

export function CameraScanner({ onScan, disabled = false }: { onScan: (barcode: string) => void; disabled?: boolean }) {
  useLanguage();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const video = useRef<HTMLVideoElement>(null);
  const callback = useRef(onScan); callback.current = onScan;
  const supported = typeof window !== 'undefined' && !!(window as ScannerWindow).BarcodeDetector && !!navigator.mediaDevices?.getUserMedia;
  useEffect(() => {
    if (!open || disabled) return;
    let active = true; let stream: MediaStream | null = null; let timer: ReturnType<typeof setTimeout> | null = null;
    const stop = () => { if (timer) clearTimeout(timer); stream?.getTracks().forEach(track => track.stop()); if (video.current) video.current.srcObject = null; };
    async function start() {
      setBusy(true); setError('');
      try {
        const Constructor = (window as ScannerWindow).BarcodeDetector;
        if (!Constructor) throw new Error('Camera scanning is unavailable. Use barcode search or a keyboard scanner.');
        const formats = Constructor.getSupportedFormats ? await Constructor.getSupportedFormats() : preferredFormats;
        const selected = preferredFormats.filter(format => formats.includes(format));
        if (!selected.length) throw new Error('This browser cannot scan product barcodes. Use barcode search or a keyboard scanner.');
        const detector = new Constructor({ formats: selected });
        if (!active) return;
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        if (!active) { stop(); return; }
        if (!video.current) { stop(); return; }
        video.current.srcObject = stream; await video.current.play();
        if (!active) return;
        setBusy(false);
        async function detect() {
          if (!active || !video.current) return;
          try {
            if (video.current.readyState >= 2) {
              const codes = await detector.detect(video.current);
              if (!active) return;
              const code = codes.find(value => value.rawValue.length <= 100 && /^[!-~]+$/.test(value.rawValue));
              if (code) { active = false; stop(); setOpen(false); callback.current(code.rawValue); return; }
            }
            if (active) timer = setTimeout(() => void detect(), 250);
          } catch { if (active) { stop(); setError('The camera could not read barcodes. Close and try again, or use barcode search.'); } }
        }
        void detect();
      } catch (reason) {
        stop();
        if (active) { setBusy(false); setError(reason instanceof Error && reason.name === 'NotAllowedError' ? 'Camera access was declined. Allow camera access in your browser or use barcode search.' : reason instanceof Error ? reason.message : 'Camera access failed. Use barcode search.'); }
      }
    }
    void start();
    const escape = (event: KeyboardEvent) => { if (event.key === 'Escape') setOpen(false); };
    document.addEventListener('keydown', escape);
    return () => { active = false; stop(); document.removeEventListener('keydown', escape); };
  }, [open, disabled]);
  useEffect(() => { if (disabled) setOpen(false); }, [disabled]);
  return <>
    <button type="button" disabled={disabled || !supported} onClick={() => setOpen(true)} title={supported ? translate("Scan a registered product barcode") : translate("Camera scanning is unavailable in this browser. Use barcode search or a keyboard scanner.")} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:text-slate-400"><Camera size={16} />{translate("Scan with camera")}</button>
    {!supported && <span className="text-xs text-slate-500">{translate("Camera unavailable; use barcode search or a keyboard scanner.")}</span>}
    {open && <Dialog open onClose={() => setOpen(false)} aria-label={translate("Scan product barcode")} maxWidth="sm" fullWidth>
      <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
        <div className="mb-3 flex items-center justify-between"><h2 className="font-semibold text-slate-800">{translate("Scan product barcode")}</h2><button type="button" aria-label={translate("Close camera")} onClick={() => setOpen(false)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"><X size={20} /></button></div>
        <video ref={video} muted playsInline className="aspect-video w-full rounded-lg bg-slate-900 object-cover" />
        {busy && <p role="status" className="mt-3 text-sm text-slate-600">{translate("Opening camera…")}</p>}
        {error ? <p role="alert" className="mt-3 text-sm text-red-600">{translate(error)}</p> : <p className="mt-3 text-sm text-slate-500">{translate("Point the camera at the product barcode. The camera closes after a scan.")}</p>}
      </div>
    </Dialog>}
  </>;
}



