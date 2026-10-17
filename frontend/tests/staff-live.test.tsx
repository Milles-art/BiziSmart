// @vitest-environment jsdom
import {cleanup,fireEvent,render,screen,within} from '@testing-library/react';
import {afterEach,expect,it,vi} from 'vitest';
import {Staff} from '../src/features/live/Staff';
import type {ServerUser} from '../src/features/live/api';
const api=vi.hoisted(()=>({update:vi.fn(),create:vi.fn(),deactivate:vi.fn(),reset:vi.fn()}));
vi.mock('../src/features/live/StaffEmailAccess',()=>({StaffEmailAccess:()=> <button>Create sign-in link</button>}));
vi.mock('../src/features/live/api',()=>({
 errorMessage:()=> 'Request failed',
 useUsersQuery:()=>({data:{users:[{id:'usr_staff',name:'Asha cashier',phone:'255620000000',role:'cashier',is_active:true,two_factor_enabled:false,must_change_pin:true,permissions:['sell','manage_till']}],meta:{total:1}},isLoading:false,refetch:vi.fn()}),
 useCreateUserMutation:()=>[api.create],useUpdateUserMutation:()=>[api.update],useDeactivateUserMutation:()=>[api.deactivate],useResetPinMutation:()=>[api.reset],
}));
const owner:ServerUser={id:'usr_owner',name:'Owner',role:'owner',branch_id:'br_main',permissions:['manage_staff']};
afterEach(()=>{cleanup();vi.clearAllMocks();});
it('keeps permissions in a closed native disclosure and retains real staff actions and supplied navigation',()=>{
 render(<Staff actor={owner} navigation={<nav aria-label="Staff sections">Attendance and leaderboard</nav>}/>);
 expect(screen.getAllByRole('button',{name:'Add Staff'})).toHaveLength(1);
 expect(screen.getByRole('navigation',{name:'Staff sections'})).toBeTruthy();
 const card=within(screen.getByLabelText('Staff member Asha cashier'));
 const summary=card.getByText('Assigned permissions');
 const disclosure=summary.closest('details')!;
 expect(disclosure.open).toBe(false);
 expect(disclosure.querySelectorAll('li')).toHaveLength(2);
 expect(card.getByRole('button',{name:'Edit staff'})).toBeTruthy();
 expect(card.getByRole('button',{name:'Deactivate'})).toBeTruthy();
 expect(card.getByRole('button',{name:'Create sign-in link'})).toBeTruthy();
 expect(card.queryByRole('button',{name:'Reset PIN'})).toBeNull();
 fireEvent.click(card.getByRole('button',{name:'Edit staff'}));
 const dialog=within(screen.getByRole('dialog',{name:'Edit Staff Member'}));
 expect(dialog.getByLabelText('Full Name')).toHaveProperty('value','Asha cashier');
 expect(dialog.queryByLabelText('Temporary PIN')).toBeNull();
});
