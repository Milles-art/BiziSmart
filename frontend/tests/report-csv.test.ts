import {expect,it} from 'vitest';
import {serializeReportCsv} from '../src/features/live/reportCsv';
it('escapes user text formulas quotes and newlines while retaining exact server monetary integers',()=>{
 const csv=serializeReportCsv([['Name','Value','Reason'],['=SUM(A1:A2)',2400,'Line "one"\nLine two'],['  @bad',-800,'Safe'],['-3',0,'Quantity string']]);
 expect(csv).toBe('\uFEFF"Name","Value","Reason"\r\n"\'=SUM(A1:A2)",2400,"Line ""one""\nLine two"\r\n"\'  @bad",-800,"Safe"\r\n"\'-3",0,"Quantity string"');
});
