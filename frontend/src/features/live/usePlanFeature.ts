import {liveApi} from './api';
/** Read the workspace's already-authoritative /me snapshot without a second request. */
export function usePlanFeature(feature:string):boolean {const query=liveApi.endpoints.me.useQueryState();return query.data?.features?.[feature]!==false;}
