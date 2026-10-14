import { useState } from 'react';
import { useDispatch,useSelector,useStore } from 'react-redux';
import { errorMessage,type SessionState } from './api';
import {useWriteFinanceMutation} from './financeApi';
import {financialPending,financialFinished,type FinancialPending,type FinancialState} from './financeState';
import {useLanguage} from '../../i18n';
export function useFinancialWrite(){
 useLanguage();const dispatch=useDispatch();const store=useStore();const pending=useSelector((s:{financial:FinancialState})=>s.financial.pending);const[write]=useWriteFinanceMutation();const[busy,setBusy]=useState(false);const[error,setError]=useState('');
 async function commit(input:FinancialPending){if(busy)return false;const token=(store.getState()as{session:SessionState}).session.token;if(!token)return false;dispatch(financialPending(input));setBusy(true);setError('');const request=write(input);
 try{await request.unwrap();if((store.getState()as{session:SessionState}).session.token!==token)return false;dispatch(financialFinished());return true;}
 catch(e){if((store.getState()as{session:SessionState}).session.token!==token)return false;setError(errorMessage(e));const failure=e as{status?:number|string;data?:{error?:{code?:string}}};if((typeof failure.status==='number'&&failure.status>=400&&failure.status<500&&failure.status!==408&&failure.status!==429)||['VALIDATION_ERROR','EXPENSE_CHANGED','TILL_CHANGED','INSUFFICIENT_CASH','DROP_RESOLVED','PAYMENT_REFERENCE_REUSED','APPROVAL_REQUIRED','APPROVAL_EXPIRED','INVALID_APPROVAL'].includes(failure.data?.error?.code??''))dispatch(financialFinished());return false;}
 finally{request.reset();setBusy(false);}}
 return{pending,busy,error,commit};
}
