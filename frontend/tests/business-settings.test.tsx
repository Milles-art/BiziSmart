// @vitest-environment jsdom
import {afterEach,expect,it,vi} from 'vitest';
import {cleanup,fireEvent,render,screen,waitFor} from '@testing-library/react';
import {Settings} from '../src/features/live/Settings';

const mocks=vi.hoisted(()=>({save:vi.fn(),reset:vi.fn(),unwrap:vi.fn(),profile:{name:'Fresh Choice',address:'Current address',phone:null,tin:null,vrn:null,header:'Welcome',footer:'Thank you',paper_width:'80',currency:'TZS',timezone:'Africa/Dar_es_Salaam'}}));
vi.mock('../src/features/live/settingsApi',()=>({useBusinessSettingsQuery:()=>({data:mocks.profile}),useSaveBusinessSettingsMutation:()=>[mocks.save,{isLoading:false}]}));
vi.mock('../src/features/live/Devices',()=>({Devices:()=> <div>Approved devices</div>}));
afterEach(()=>{cleanup();vi.clearAllMocks();});

it('sends edited business and receipt settings to Laravel without tax-rate or tenancy fields',async()=>{
 mocks.unwrap.mockResolvedValue({...mocks.profile,name:'Updated shop'});mocks.save.mockReturnValue({unwrap:mocks.unwrap,reset:mocks.reset});
 render(<Settings shopName="Fresh Choice"/>);
 fireEvent.change(screen.getByLabelText('Business Name'),{target:{value:'Updated shop'}});
 fireEvent.change(screen.getByLabelText('Receipt paper width'),{target:{value:'58'}});
 fireEvent.click(screen.getByRole('button',{name:'Save settings'}));
 await waitFor(()=>expect(mocks.save).toHaveBeenCalledWith({name:'Updated shop',address:'Current address',phone:null,tin:null,vrn:null,header:'Welcome',footer:'Thank you',paper_width:'58'}));
 expect(await screen.findByText('Settings saved.')).toBeTruthy();
 expect(mocks.reset).toHaveBeenCalled();
});

it('keeps an unsuccessful edit visible and reports the API failure',async()=>{
 mocks.unwrap.mockRejectedValue({data:{error:{message:'Settings unavailable'}}});mocks.save.mockReturnValue({unwrap:mocks.unwrap,reset:mocks.reset});
 render(<Settings shopName="Fresh Choice"/>);
 fireEvent.change(screen.getByLabelText('Header message'),{target:{value:'New header'}});
 fireEvent.click(screen.getByRole('button',{name:'Save settings'}));
 await waitFor(()=>expect(screen.getByRole('alert').textContent).toContain('Settings unavailable'));
 expect((screen.getByLabelText('Header message') as HTMLTextAreaElement).value).toBe('New header');
 expect(screen.queryByText('Settings saved.')).toBeNull();
});
