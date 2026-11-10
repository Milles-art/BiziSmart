import {liveApi} from './api';

export interface SupplierStatementEntry {id:string;kind:'invoice'|'payment'|'credit';at:string;date:string;document_date:string|null;reference:string;charge:number;payment:number;credit:number;balance:number}
export interface SupplierStatement {supplier:{id:string;name:string;phone:string|null};period:{from:string;to:string;timezone:string;basis:'recorded_at'};opening_balance:number;charges:number;payments:number;credits:number;closing_balance:number;outstanding_payable:number;unapplied_credit:number;currency:'TSh';entries:SupplierStatementEntry[];meta:{page:number;per_page:number;total:number;last_page:number}}
export interface SupplierStatementFilters {supplier:string;from:string;to:string;page?:number;per_page?:number}
const statementApi=liveApi.enhanceEndpoints({addTagTypes:['Suppliers']}).injectEndpoints({endpoints:b=>({
 supplierStatement:b.query<SupplierStatement,SupplierStatementFilters>({query:({supplier,...params})=>({url:`suppliers/${supplier}/statement`,params}),providesTags:['Suppliers'],transformResponse:(r:{data:SupplierStatement})=>r.data}),
 exportSupplierStatement:b.mutation<string,SupplierStatementFilters>({query:({supplier,from,to})=>({url:`suppliers/${supplier}/statement.csv`,params:{from,to},responseHandler:async(response:Response)=>response.ok?response.text():response.json()})}),
})});
export const {useSupplierStatementQuery,useExportSupplierStatementMutation}=statementApi;
