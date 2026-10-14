import {spawn,spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../../',import.meta.url));
const probe=spawnSync('php',['-r','echo extension_loaded("intl") ? "ready" : "missing";'],{encoding:'utf8',cwd:root});
if(probe.status!==0){console.error('PHP is unavailable. Configure the documented local runtime.');process.exit(1);}
const extensions=probe.stdout.trim()==='ready'?[]:['-d','extension=intl'];
const child=spawn('php',[...extensions,'-d','opcache.enable_cli=1','-d','opcache.validate_timestamps=1','-d','opcache.revalidate_freq=0','-S','127.0.0.1:18080','-t','backend/public','backend/dev-server.php'],{cwd:root,stdio:'inherit'});
child.on('error',()=>{console.error('Could not start the local PHP API.');process.exit(1);});
child.on('exit',code=>process.exit(code??1));
for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>child.kill(signal));
