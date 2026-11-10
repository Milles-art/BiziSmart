import {useState} from 'react';
import {ScreenTabs} from '../../components/ui/Primitives';
import {Staff} from './Staff';
import StaffOperations from './StaffOperations';
import {CommissionLeaderboard} from './CommissionLeaderboard';
import {Settings} from './Settings';
import {Incentives} from './Incentives';
import {ScaleSettings} from './ScaleSettings';
import type {ServerUser} from './api';

export function StaffWorkspace({actor,features}:{actor:ServerUser;features?:Record<string,boolean>}){
 const [tab,setTab]=useState('directory');
 const tabs=<ScreenTabs value={tab} onChange={setTab} tabs={[{id:'directory',label:'Staff Members'},{id:'attendance',label:'Attendance & notifications'},...(actor.role==='owner'&&features?.commissions!==false?[{id:'commissions',label:'Commission leaderboard'}]:[])]}/>;
 if(tab==='directory')return <Staff actor={actor} navigation={tabs}/>;
 return <div className="space-y-5 p-4 lg:p-6">{tabs}{tab==='commissions'&&actor.role==='owner'&&features?.commissions!==false?<CommissionLeaderboard/>:<StaffOperations actor={actor}/>}</div>;
}

export function SettingsWorkspace({actor,shopName,features}:{actor:ServerUser;shopName:string;features?:Record<string,boolean>}){
 const [tab,setTab]=useState('general');
 const rewards=actor.role==='owner'&&(features?.loyalty!==false||features?.commissions!==false);
 const scales=actor.role==='owner'&&features?.scales!==false;
 const tabs=<ScreenTabs value={tab} onChange={setTab} tabs={[{id:'general',label:'Business & devices'},...(rewards?[{id:'rewards',label:'Loyalty & commissions'}]:[]),...(scales?[{id:'scales',label:'Scale labels'}]:[])]}/>;
 if(tab==='rewards'&&rewards)return <div className="space-y-5 p-4 lg:p-6">{tabs}<Incentives actor={actor} includeScales={false}/></div>;
 if(tab==='scales'&&scales)return <div className="space-y-5 p-4 lg:p-6">{tabs}<ScaleSettings/></div>;
 return <Settings shopName={shopName} navigation={tabs}/>;
}
