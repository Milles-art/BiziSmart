<?php
declare(strict_types=1);
$base='/home/afyabgzo/bizismart';
if(PHP_SAPI!=='cli'||realpath($base)!==$base)throw new RuntimeException('Unexpected monitoring target.');
umask(0077);
require $base.'/backend/vendor/autoload.php';
$app=require $base.'/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$now=time();$last=Illuminate\Support\Facades\Cache::get('infra:shared-last-run');
$runAge=$last?($now-strtotime($last['at'])):null;
$backupFile=$base.'/encrypted-backups/last-success.json';
$backup=is_file($backupFile)?json_decode(file_get_contents($backupFile),true):null;
$backupAge=$backup?($now-strtotime($backup['created_at'])):null;
$checks=['scheduler_fresh'=>$runAge!==null&&$runAge>=0&&$runAge<900,'scheduler_last_pass_clean'=>($last['failed']??-1)===0,'encrypted_backup_fresh'=>$backupAge!==null&&$backupAge>=0&&$backupAge<129600,'offsite_copy_verified'=>($backup['offsite_verified']??false)===true];
foreach(['freshchoice.bizismart.com','bizismart.com']as$host){
 $curl=curl_init('https://'.$host.'/api/v1/ready');
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10]);
 $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 $body=is_string($body)?json_decode($body,true):null;
 $checks[$host.'_ready']=$status===200&&($body['success']??false)===true&&($body['data']['ready']??false)===true;
}
$stale=0;foreach(glob($base.'/encrypted-backups/.backup-*',GLOB_ONLYDIR)as$path)if(!is_link($path)&&filemtime($path)<$now-300)$stale++;
$checks['no_stale_plaintext_staging']=$stale===0;
$report=['observed_at'=>gmdate('c'),'healthy'=>!in_array(false,$checks,true),'checks'=>$checks,'scheduler_age_seconds'=>$runAge,'backup_age_seconds'=>$backupAge,'last_run_completed'=>$last['completed']??0,'last_run_failed'=>$last['failed']??null,'stale_staging_count'=>$stale,'notification_delivery'=>'not configured; private status/log only'];
$path=$base.'/last-monitor.json';file_put_contents($path.'.part',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));rename($path.'.part',$path);
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
exit($report['healthy']?0:1);
