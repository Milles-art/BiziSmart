// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ProductImage } from '../src/features/live/ProductImage';
import { ProductImageForm } from '../src/features/live/ProductImageForm';
import {compressProductImage} from '../src/features/live/prepareProductImage';
import { CameraScanner } from '../src/features/live/CameraScanner';
import { useBarcodeKeyboard } from '../src/features/live/useBarcodeKeyboard';

const metadata = { revision: '01JIMAGE', mime: 'image/png', bytes: 100, width: 10, height: 10 };

beforeEach(() => {
  vi.stubGlobal('IntersectionObserver', undefined);
  vi.stubGlobal('URL', { createObjectURL: vi.fn(() => 'blob:private-image'), revokeObjectURL: vi.fn() });
});
afterEach(() => { cleanup(); vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('private product images', () => {
  it('loads through the authenticated callback and revokes memory URLs on unmount', async () => {
    const load = vi.fn().mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }));
    const view = render(<ProductImage productId="prod_water" image={metadata} name="Water" loadImage={load} />);
    await screen.findByAltText('Water');
    expect(load).toHaveBeenCalledWith('prod_water', metadata.revision);
    expect(screen.getByAltText('Water').getAttribute('src')).toBe('blob:private-image');
    view.unmount(); expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:private-image');
  });
  it('shows an actionable retry instead of silently treating an error as no image', async () => {
    const load = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce(new Blob(['ok']));
    render(<ProductImage productId="prod_water" image={metadata} name="Water" loadImage={load} />);
    fireEvent.click(await screen.findByRole('button', { name: 'Retry image for Water' }));
    await screen.findByAltText('Water'); expect(load).toHaveBeenCalledTimes(2);
  });
  it('keeps unconfigured storage unavailable and confirms image removal before writing', async () => {
    const remove = vi.fn().mockResolvedValue({}); const load = vi.fn().mockResolvedValue(new Blob(['ok']));
    const props = { productId: 'prod_water', name: 'Water', image: metadata, loadImage: load, upload: vi.fn(), remove };
    const view = render(<ProductImageForm {...props} enabled={false} />);
    expect(screen.getByRole('status').textContent).toContain('private storage');
    expect((screen.getByLabelText('Choose product image') as HTMLInputElement).disabled).toBe(true);
    view.rerender(<ProductImageForm {...props} enabled />);
    fireEvent.click(screen.getByRole('button', { name: 'Remove image' })); expect(remove).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm removal' }));
    await screen.findByText('Product image removed.'); expect(remove).toHaveBeenCalledWith(metadata.revision);
  });
  it('rejects SVG before invoking native image decoding', async () => {
    const decode = vi.fn(); vi.stubGlobal('createImageBitmap', decode);
    await expect(compressProductImage(new File(['<svg/>'], 'unsafe.svg', { type: 'image/svg+xml' }))).rejects.toThrow('JPEG, PNG or WebP');
    expect(decode).not.toHaveBeenCalled();
  });
});

function KeyboardHarness({ scan, disabled = false }: { scan: (value: string) => void; disabled?: boolean }) {
  useBarcodeKeyboard(scan, disabled); return <input aria-label="Product search" />;
}

describe('barcode input', () => {
  it('reads a keyboard scanner burst while leaving focused editable fields untouched', () => {
    const scan = vi.fn(); render(<KeyboardHarness scan={scan} />);
    for (const key of '06123456789012') fireEvent.keyDown(document.body, { key });
    fireEvent.keyDown(document.body, { key: 'Enter' }); expect(scan).toHaveBeenCalledWith('06123456789012');
    scan.mockClear(); const input = screen.getByRole('textbox');
    for (const key of '06123456789012') fireEvent.keyDown(input, { key });
    fireEvent.keyDown(input, { key: 'Enter' }); expect(scan).not.toHaveBeenCalled();
  });
  it('does not intercept scanner input while financial operations lock POS', () => {
    const scan = vi.fn(); render(<KeyboardHarness scan={scan} disabled />);
    for (const key of '06123456789012') fireEvent.keyDown(document.body, { key });
    fireEvent.keyDown(document.body, { key: 'Enter' }); expect(scan).not.toHaveBeenCalled();
  });
  it('explains unsupported camera scanning without requesting permission', () => {
    vi.stubGlobal('BarcodeDetector', undefined);
    const request = vi.fn(); Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: request }, configurable: true });
    render(<CameraScanner onScan={vi.fn()} />);
    expect((screen.getByRole('button', { name: 'Scan with camera' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByText(/Camera unavailable/)).toBeTruthy(); expect(request).not.toHaveBeenCalled();
  });
  it('stops camera tracks on close and ignores late detector completion', async () => {
    const track = { stop: vi.fn() }; const scan = vi.fn();
    const detect = vi.fn().mockResolvedValue([]);
    class Detector { static getSupportedFormats = vi.fn().mockResolvedValue(['ean_13']); detect = detect; }
    vi.stubGlobal('BarcodeDetector', Detector);
    const request = vi.fn().mockResolvedValue({ getTracks: () => [track] });
    Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia: request }, configurable: true });
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    render(<CameraScanner onScan={scan} />);
    fireEvent.click(screen.getByRole('button', { name: 'Scan with camera' }));
    await waitFor(() => expect(request).toHaveBeenCalledTimes(1));
    fireEvent.click(screen.getByRole('button', { name: 'Close camera' }));
    await waitFor(() => expect(track.stop).toHaveBeenCalled()); expect(scan).not.toHaveBeenCalled();
  });
});
