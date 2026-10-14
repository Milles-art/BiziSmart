import { useEffect, useRef } from 'react';

/** Keyboard-wedge scanners emit a rapid barcode burst followed by Enter. */
export function useBarcodeKeyboard(onScan: (barcode: string) => void, disabled = false) {
  const callback = useRef(onScan);
  callback.current = onScan;
  useEffect(() => {
    if (disabled) return;
    let buffer = ''; let lastAt = 0; let startAt = 0;
    const keydown = (event: KeyboardEvent) => {
      const target = event.target;
      if (event.isComposing || event.ctrlKey || event.altKey || event.metaKey
        || target instanceof HTMLElement && (target.closest('input,textarea,select,[contenteditable="true"],[role="textbox"]') || target.isContentEditable)) {
        buffer = ''; return;
      }
      const now = performance.now();
      if (now - lastAt > 80) { buffer = ''; startAt = now; }
      if (event.key === 'Enter') {
        // Longer human typing and short Enter shortcuts must not trigger scans.
        if (buffer.length >= 4 && buffer.length <= 100 && now - startAt <= buffer.length * 80 && /^[!-~]+$/.test(buffer)) {
          event.preventDefault(); callback.current(buffer);
        }
        buffer = ''; lastAt = now; return;
      }
      if (event.key.length === 1 && /^[!-~]$/.test(event.key)) {
        if (buffer.length === 0) startAt = now;
        buffer = buffer.length < 100 ? buffer + event.key : '';
        lastAt = now;
      } else { buffer = ''; }
    };
    document.addEventListener('keydown', keydown);
    return () => document.removeEventListener('keydown', keydown);
  }, [disabled]);
}
