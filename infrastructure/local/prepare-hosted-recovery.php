<?php
declare(strict_types=1);
require __DIR__.'/../prod/backup-crypto.php';
$root=str_replace('\\','/',(string)getenv('USERPROFILE')).'/.ssh/bizismart-backup-recovery';
$file=$argv[1]??'';$verifyOnly=($argv[2]??'')==='--verify-only';
if(!preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{8}\.cms$/',$file)||!is_file($root.'/copies/'.$file))throw new RuntimeException('Unknown protected backup copy.');
$nonce=bin2hex(random_bytes(8));$directory=$root.'/payload-'.$nonce;mkdir($directory,0700);umask(0077);
try {
 backupOpen($root.'/copies/'.$file,$directory.'/snapshot.tar.gz',$root.'/recovery-public.pem',$root.'/recovery-private.pem',$root.'/signing-public.pem');
 $errors=$directory.'/extract.error';
 $p=proc_open(['C:/Windows/System32/tar.exe','-xzf',$directory.'/snapshot.tar.gz','-C',$directory,'manifest.json','database.sql','application.env'],[0=>['pipe','r'],1=>['file','NUL','w'],2=>['file',$errors,'w']],$pipes,null,null,['bypass_shell'=>true]);
 if(!is_resource($p))throw new RuntimeException('Cannot extract authenticated recovery payload.');fclose($pipes[0]);
 if(proc_close($p)!==0)throw new RuntimeException('Recovery extraction failed.');
 $m=json_decode(file_get_contents($directory.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(!hash_equals($m['sql_sha256'],hash_file('sha256',$directory.'/database.sql'))||!hash_equals($m['env_sha256'],hash_file('sha256',$directory.'/application.env')))throw new RuntimeException('Authenticated content mismatch.');
 $boot=$m['source_bootstrap_files']??[];
 $archive=new PharData($directory.'/snapshot.tar.gz');
 foreach($boot as$path)if(!isset($archive[$path]))throw new RuntimeException('Required application startup source missing.');
 unset($archive);
 unlink($directory.'/snapshot.tar.gz');unlink($errors);
 $report=['nonce'=>$nonce,'protected_payload_directory'=>$directory,'tables'=>count($m['rows']),'rows'=>array_sum($m['rows']),'source_backup'=>$file,'source_sha256'=>hash_file('sha256',$root.'/copies/'.$file),'signature_and_content_verified'=>true,'startup_files_verified'=>count($boot),'restore_directories'=>$m['restore_directories']??[]];
 if($verifyOnly){foreach(['database.sql','application.env','manifest.json']as$name)unlink($directory.'/'.$name);rmdir($directory);$report['plaintext_staging_removed']=true;unset($report['protected_payload_directory']);}
 echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable$error){foreach(glob($directory.'/*')as$path)if(is_file($path))unlink($path);if(is_dir($directory))rmdir($directory);throw$error;}
