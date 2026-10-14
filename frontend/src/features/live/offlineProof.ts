const encoder=new TextEncoder();
export function canonicalOfflineJson(value:unknown):string {
  function normalize(input:unknown):unknown {
    if(input===null || typeof input==='boolean' || typeof input==='string')return input;
    if(typeof input==='number'){if(!Number.isSafeInteger(input))throw new Error('Offline requests require exact integers.');return input;}
    if(Array.isArray(input))return input.map(normalize);
    if(typeof input==='object' && Object.getPrototypeOf(input)===Object.prototype)return Object.fromEntries(Object.keys(input).sort().map(key=>[key,normalize((input as Record<string,unknown>)[key])]));
    throw new Error('Unsupported offline request value.');
  }
  return JSON.stringify(normalize(value));
}
function hex(bytes:ArrayBuffer):string {return [...new Uint8Array(bytes)].map(value=>value.toString(16).padStart(2,'0')).join('');}
/** PHP OfflineProof signs the hexadecimal SHA-256 canonical snapshot hash, not raw JSON. */
export async function signOfflineBody(body:unknown,secret:string):Promise<string>{
  if(!/^[a-f0-9]{64}$/.test(secret))throw new Error('The offline signing key is invalid.');
  const digest=hex(await crypto.subtle.digest('SHA-256',encoder.encode(canonicalOfflineJson(body))));
  const key=await crypto.subtle.importKey('raw',encoder.encode(secret),{name:'HMAC',hash:'SHA-256'},false,['sign']);
  return hex(await crypto.subtle.sign('HMAC',key,encoder.encode(digest)));
}
