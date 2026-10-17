<?php
declare(strict_types=1);

// Fixed existing installation. Read-only consistent DB dump; no operational restore.
require is_file(__DIR__.'/backup-crypto.php') ? __DIR__.'/backup-crypto.php' : __DIR__.'/../prod/backup-crypto.php';
$base='/home/afyabgzo/bizismart'; $backend=$base.'/backend';
$private=$base.'/encrypted-backups'; $crypto=$base.'/backup-keys';
if (PHP_SAPI!=='cli' || realpath($base)!==$base || realpath($backend)!==$backend || !is_file($backend.'/artisan')) throw new RuntimeException('Unexpected backup target.');
umask(0077);
foreach ([$private,$crypto] as $directory) {
    if (is_link($directory)) throw new RuntimeException('Refusing linked private directory.');
    if (!is_dir($directory) && !mkdir($directory,0700)) throw new RuntimeException('Cannot create private backup directory.');
    chmod($directory,0700);
}
$lock=fopen($private.'/.backup.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another encrypted backup is running.');
// A killed process can leave owner-only plaintext staging. Remove only our exact
// known files after obtaining the exclusive lock, never arbitrary directories.
foreach (glob($private.'/.backup-*',GLOB_ONLYDIR) as $stale) {
    if (is_link($stale) || dirname(realpath($stale))!==$private || !preg_match('/^\.backup-\d{8}-\d{6}-[a-f0-9]{8}$/',basename($stale))) continue;
    foreach (['client.cnf','database.sql','dump.error','application.env','manifest.json','snapshot.tar.gz'] as $name) if (is_file($stale.'/'.$name) && !is_link($stale.'/'.$name)) unlink($stale.'/'.$name);
    if (count(scandir($stale))===2) rmdir($stale);
}
$run='backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
$work=$private.'/.'.$run; mkdir($work,0700);
$files=[];
try {
    foreach (['recovery-public.pem','signing-public.pem','signing-private.pem'] as $name) {
        $path=$crypto.'/'.$name;
        if (!is_file($path) || is_link($path) || fileowner($path)!==getmyuid() || (fileperms($path)&0077)!==0) throw new RuntimeException('Unprotected/missing backup certificate or signing key.');
    }
    require $backend.'/vendor/autoload.php';
    $app=require $backend.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connection=config('database.connections.'.config('database.default'));
    if (($connection['driver']??'')!=='mysql' || ($connection['database']??'')!=='afyabgzo_bizismart') throw new RuntimeException('Refusing another database.');
    $quote=static fn(string $value):string=> '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
    $client="[client]\n";
    foreach (['host','port','username','password','unix_socket'] as $field) {
        $value=(string)($connection[$field]??'');
        if (preg_match('/[\r\n\x00]/',$value)) throw new RuntimeException('Invalid client configuration.');
        if ($value!=='') $client.=(['username'=>'user','unix_socket'=>'socket'][$field]??$field).'='.$quote($value)."\n";
    }
    $cnf=$work.'/client.cnf'; $files[]=$cnf;
    file_put_contents($cnf,$client,LOCK_EX); chmod($cnf,0600); unset($client,$connection);
    $sql=$work.'/database.sql'; $files[]=$sql;
    $errors=$work.'/dump.error'; $files[]=$errors;
    $command=['/usr/bin/mariadb-dump','--defaults-extra-file='.$cnf,'--single-transaction','--skip-lock-tables','--routines','--events','--triggers','--no-tablespaces','--hex-blob','--skip-extended-insert','--complete-insert','--default-character-set=utf8mb4','--result-file='.$sql,'afyabgzo_bizismart'];
    $process=proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$errors,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start consistent database dump.');
    fclose($pipes[0]);
    if (proc_close($process)!==0 || !is_file($sql) || filesize($sql)<1) throw new RuntimeException('Database dump failed. No backup success.');
    chmod($sql,0600); unlink($cnf);
    $counts=[]; $inData=false;
    $handle=fopen($sql,'rb');
    while (($line=fgets($handle))!==false) {
        if (preg_match('/^CREATE TABLE `([A-Za-z0-9_]+)`/',$line,$match)) {$counts[$match[1]]=0;$inData=false;}
        if (str_starts_with($line,'-- Dumping data for table ')) $inData=true;
        elseif (str_starts_with($line,'-- Dumping ')) $inData=false;
        if ($inData && preg_match('/^INSERT INTO `([A-Za-z0-9_]+)` /',$line,$match)) {
            if (!array_key_exists($match[1],$counts)) throw new RuntimeException('Unexpected dump data section.');
            $counts[$match[1]]++;
        }
    }
    fclose($handle); ksort($counts);
    if (count($counts)<20 || !isset($counts['businesses'],$counts['users'],$counts['sales'],$counts['migrations'])) throw new RuntimeException('Incomplete application schema snapshot.');
    $env=$work.'/application.env'; $files[]=$env;
    if (!copy($backend.'/.env',$env)) throw new RuntimeException('Cannot protect existing application key/configuration.');
    chmod($env,0600);
    $manifest=['format'=>1,'created_at'=>gmdate('c'),'database'=>'afyabgzo_bizismart','sql_sha256'=>hash_file('sha256',$sql),'env_sha256'=>hash_file('sha256',$env),'rows'=>$counts,'source_bootstrap_files'=>['backend/artisan','backend/bootstrap/app.php','backend/bootstrap/providers.php','backend/public/index.php','backend/public/.htaccess'],'restore_directories'=>['backend/bootstrap/cache','backend/storage/app/private','backend/storage/framework/cache/data','backend/storage/framework/sessions','backend/storage/framework/views','backend/storage/logs'],'notes'=>'Read-only single-transaction snapshot. Restore ONLY to an explicitly isolated target. Recreate writable runtime directories; reinstall locked Composer dependencies.'];
    $metadata=$work.'/manifest.json';$files[]=$metadata;
    file_put_contents($metadata,json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($metadata,0600);
    $archive=$work.'/snapshot.tar.gz';$files[]=$archive;
    $command=['/usr/bin/tar','-czf',$archive,'-C',$work,'database.sql','application.env','manifest.json','-C',$base,'backend/app','backend/config','backend/database','backend/resources','backend/routes','backend/artisan','backend/bootstrap/app.php','backend/bootstrap/providers.php','backend/composer.json','backend/composer.lock','backend/public/index.php','backend/public/.htaccess','-C','/home/afyabgzo/bizismart.com','index.html','gateway.php','.htaccess','assets','manifest.webmanifest','registerSW.js','sw.js','workbox-2fbc6a65.js','bizismart.svg'];
    $process=proc_open($command,[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$errors,'w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot stage recovery archive.');fclose($pipes[0]);
    if (proc_close($process)!==0 || !is_file($archive) || filesize($archive)<1) throw new RuntimeException('Archive staging failed.');
    chmod($archive,0600);
    $packed=new PharData($archive);
    foreach($manifest['source_bootstrap_files'] as$required) if(!isset($packed[$required])) throw new RuntimeException('Application startup file missing from archive.');
    unset($packed);
    $output=$private.'/'.$run.'.cms';
    backupSeal($archive,$output,$crypto.'/recovery-public.pem',$crypto.'/signing-public.pem',$crypto.'/signing-private.pem');
    // Platform backup, never a tenant product/image key. Only already encrypted
    // and signed bytes leave this host; the decryption key is never on the host.
    $storage=app(App\Services\ProductImageService::class)->privateStorage();
    $offsite='platform-backups/'.basename($output);
    if ($storage->exists($offsite)) throw new RuntimeException('Refusing existing offsite backup.');
    $stream=fopen($output,'rb');
    try { if (!$storage->put($offsite,$stream,['visibility'=>'private','ContentType'=>'application/pkcs7-mime'])) throw new RuntimeException('Encrypted offsite upload failed.'); }
    finally { fclose($stream); }
    $stream=$storage->readStream($offsite);
    if (!is_resource($stream)) throw new RuntimeException('Offsite backup is not readable.');
    try {$hash=hash_init('sha256');hash_update_stream($hash,$stream);$offsiteHash=hash_final($hash);} finally {fclose($stream);}
    if (!hash_equals(hash_file('sha256',$output),$offsiteHash)) throw new RuntimeException('Offsite encrypted bytes mismatch.');
    $summary=['created_at'=>$manifest['created_at'],'file'=>basename($output),'sha256'=>hash_file('sha256',$output),'bytes'=>filesize($output),'tables'=>count($counts),'rows'=>array_sum($counts),'startup_files_verified'=>count($manifest['source_bootstrap_files']),'contains_application_key'=>true,'encrypted'=>true,'signed'=>true,'offsite_verified'=>true];
    file_put_contents($private.'/'.$run.'.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    file_put_contents($private.'/last-success.json.part',json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    rename($private.'/last-success.json.part',$private.'/last-success.json');
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} finally {
    foreach ($files as $path) if (is_file($path)) unlink($path);
    if (is_dir($work)) rmdir($work);
    flock($lock,LOCK_UN);fclose($lock);
}
