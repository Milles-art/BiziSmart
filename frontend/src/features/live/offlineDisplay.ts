import type {OfflineSnapshot} from './offlineTypes';
export interface OfflineCartLine {snapshot:OfflineSnapshot;qty:string}
function quantityAtoms(value:string,weighted:boolean):bigint{
  if(!(weighted?/^(0|[1-9][0-9]*)(?:\.[0-9]{1,3})?$/:/^(0|[1-9][0-9]*)$/).test(value))throw new Error(weighted?'Enter kilograms with up to three decimal places.':'Enter whole positive quantities.');
  const [whole,fraction='']=value.split('.');const atoms=weighted?BigInt(whole)*1000n+BigInt(fraction.padEnd(3,'0')):BigInt(whole);
  if(atoms<1n||atoms>9223372036854775807n)throw new Error('The quantity is outside the supported range.');return atoms;
}
/** Provisional display from signed server prices. Laravel repeats exact pricing before committing. */
export function offlineDisplayTotal(lines:OfflineCartLine[]):number{
  let total=0n;
  for(const {snapshot,qty}of lines){
    if(!Number.isSafeInteger(snapshot.unit_price)||snapshot.unit_price<0)throw new Error('The cached price is invalid.');
    const weighted=snapshot.quantity_type==='weight'&&!snapshot.packaging_id;
    const atoms=quantityAtoms(qty,weighted),denominator=weighted?1000n:1n;
    if(snapshot.packaging_id){
      if(!/^[1-9][0-9]*$/.test(snapshot.conversion_atoms)||atoms*BigInt(snapshot.conversion_atoms)>9223372036854775807n)throw new Error('The package quantity is outside the supported range.');
    }
    total+=(atoms*BigInt(snapshot.unit_price)+denominator/2n)/denominator;
    if(total>9007199254740991n)throw new Error('The sale total is outside the supported range.');
  }
  return Number(total);
}
export function parseOfflineCash(value:string):number {
  if(!/^(0|[1-9][0-9]*)$/.test(value)||!Number.isSafeInteger(Number(value)))throw new Error('Enter cash tendered as whole TSh.');return Number(value);
}
