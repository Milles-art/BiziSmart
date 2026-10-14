// @vitest-environment jsdom
import {cleanup,render,screen,fireEvent} from '@testing-library/react';
import {afterEach,it,expect,vi} from 'vitest';
import {CustomerDisplay} from '../src/features/live/CustomerDisplay';
afterEach(()=>{cleanup();vi.unstubAllGlobals();});
it('explains blocked popups without presenting a display as opened',()=>{vi.spyOn(window,'open').mockReturnValueOnce(null);render(<CustomerDisplay review={null} entries={[]} shopName="Public shop"/>);fireEvent.click(screen.getByRole('button',{name:'Open customer display'}));expect(screen.getByRole('alert').textContent).toBe('Allow popups for BiziSmart, then open the customer display again.');expect(screen.queryByText('Public shop')).toBeNull();});
