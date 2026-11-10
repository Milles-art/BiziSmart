import {liveApi} from './api';

export interface BusinessProfile {
 name:string; address:string|null; phone:string|null; tin:string|null; vrn:string|null;
 header:string|null; footer:string|null; paper_width:'58'|'80'|'A4'; currency:'TZS'; timezone:string;
}
export type BusinessProfileInput=Omit<BusinessProfile,'currency'|'timezone'>;
const api=liveApi.enhanceEndpoints({addTagTypes:['BusinessSettings']}).injectEndpoints({endpoints:b=>({
 businessSettings:b.query<BusinessProfile,void>({query:()=> 'settings/business',transformResponse:(r:{data:BusinessProfile})=>r.data,providesTags:['BusinessSettings']}),
 saveBusinessSettings:b.mutation<BusinessProfile,BusinessProfileInput>({query:body=>({url:'settings/business',method:'PUT',body}),transformResponse:(r:{data:BusinessProfile})=>r.data,invalidatesTags:['BusinessSettings']}),
})});
export const {useBusinessSettingsQuery,useSaveBusinessSettingsMutation}=api;
