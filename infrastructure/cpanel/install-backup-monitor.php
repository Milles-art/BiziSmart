<?php
declare(strict_types=1);
// Account-specific managed cron lines. Never changes unrelated entries or TZ.
$base='/home/afyabgzo/bizismart';$php='/opt/alt/php85/usr/bin/php';$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||realpath($base)!==$base||!in_array($mode,['proof','daily'],true))throw new RuntimeException('Use proof or daily for the verified existing account.');
umask(0077);
$proof=json_decode(file_get_contents($base.'/recovery-proof.json'),true,512,JSON_THROW_ON_ERROR);
$latest=json_decode(file_get_contents($base.'/encrypted-backups/last-success.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['signature_verified','decrypted_archive_hashes_match','row_counts_match','encrypted_owner_data_recovered','disposable_database_removed','plaintext_staging_removed']as$key)if(($proof[$key]??false)!==true)throw new RuntimeException('Successful isolated recovery proof required.');
if(($proof['restored_tables']??0)<20||!($latest['offsite_verified']??false)||is_file($base.'/backup-keys/recovery-private.pem'))throw new RuntimeException('Protected backup preflight failed.');
if($mode==='proof'&&!hash_equals($proof['source_sha256']??'',$latest['sha256']))throw new RuntimeException('Recovery proof must match the installed initial snapshot.');
foreach(['encrypted-backup.php','backup-crypto.php','check-runtime.php']as$name)if(!is_file($base.'/'.$name)||is_link($base.'/'.$name))throw new RuntimeException('Missing private helper.');
$lock=fopen($base.'/.cron-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another cron installer is active.');
function cronProcess(array $args,?string $input=null):array{
 $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('Cannot access account crontab.');
 if($input!==null)fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);return[$exit,$out,$err];
}
try{
 [$exit,$before,$error]=cronProcess(['/usr/bin/crontab','-l']);if($exit!==0)throw new RuntimeException('Existing account crontab must be readable.');
 $stateFile=$base.'/managed-backup-cron.json';$state=is_file($stateFile)?json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR):null;
 $lines=explode("\n",rtrim($before,"\n"));
 if($state){foreach(['backup','monitor']as$type){$old=$state[$type];$matches=array_keys($lines,$old,true);if(count($matches)!==1)throw new RuntimeException('Managed cron changed; inspect before replacing.');unset($lines[$matches[0]]);}}
 foreach($lines as$line)if(!preg_match('/^\s*(#|$)/',$line)&&preg_match('/encrypted-backup\.php|check-runtime\.php/',$line))throw new RuntimeException('Unrecognized duplicate backup/monitor entry.');
 $count=0;foreach($lines as$line)if(!preg_match('/^\s*(#|$|[A-Za-z_][A-Za-z_0-9]*\s*=)/',$line))$count++;
 if($count+2>5)throw new RuntimeException('Account cron allowance would be exceeded.');
 if($mode==='proof'){
  [$exit,$schedule]=cronProcess(['/usr/bin/date','-d','+3 minutes','+%M %H']);if($exit!==0||!preg_match('/^\d{2} \d{2}\s*$/',$schedule))throw new RuntimeException('Cannot determine a one-time schedule proof.');$schedule=trim($schedule).' * * *';
 }else{$schedule='17 20 * * *';}
 $backup=$schedule.' /usr/bin/timeout --signal=TERM --kill-after=2s 50s '.$php.' '.$base.'/encrypted-backup.php >> '.$base.'/encrypted-backups/backup-cron.log 2>&1';
 $monitor='4-59/5 * * * * /usr/bin/timeout --signal=TERM --kill-after=2s 35s '.$php.' '.$base.'/check-runtime.php >> '.$base.'/monitor.log 2>&1';
 $after=implode("\n",array_values($lines))."\n".$backup."\n".$monitor."\n";
 foreach([$base.'/encrypted-backups/backup-cron.log',$base.'/monitor.log']as$log){if(is_link($log))throw new RuntimeException('Refusing linked private log.');if(!is_file($log))touch($log);chmod($log,0600);}
 $saved=$base.'/backups/cron-before-backup-monitor-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.txt';file_put_contents($saved,$before);
 [$exit,$current]=cronProcess(['/usr/bin/crontab','-l']);if($exit!==0||$current!==$before)throw new RuntimeException('Crontab changed during preflight; refusing overwrite.');
 [$exit]=cronProcess(['/usr/bin/crontab','-'],$after);if($exit!==0)throw new RuntimeException('Crontab install failed.');
 [$exit,$installed]=cronProcess(['/usr/bin/crontab','-l']);if($exit!==0||$installed!==$after)throw new RuntimeException('Installed cron verification failed.');
 $state=['backup'=>$backup,'monitor'=>$monitor,'mode'=>$mode,'installed_at'=>gmdate('c'),'prior_crontab'=>$saved];file_put_contents($stateFile,json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
 echo json_encode(['installed'=>true,'mode'=>$mode,'backup_schedule'=>$schedule,'monitor_schedule'=>'4-59/5 * * * *','active_entries'=>$count+2,'prior_crontab'=>$saved,'unrelated_entries_preserved'=>true],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{flock($lock,LOCK_UN);fclose($lock);}
