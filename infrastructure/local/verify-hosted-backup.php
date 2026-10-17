<?php
declare(strict_types=1);
require __DIR__.'/../prod/backup-crypto.php';
require __DIR__.'/../prod/normalize-recovery-sql.php';

// Real backup, disposable LOCAL restore only. Never boots the recovered .env/app.
$repo=dirname(__DIR__,2);
$keyRoot=str_replace('\\','/',(string)getenv('USERPROFILE')).'/.ssh/bizismart-backup-recovery';
$input=$argv[1]??'';
if(PHP_OS_FAMILY!=='Windows'||!is_file($input)||!preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{8}\.cms$/',basename($input)))throw new RuntimeException('Provide the downloaded encrypted backup on the Windows recovery computer.');
if(!str_starts_with(str_replace('\\','/',realpath($input)),$keyRoot.'/copies/'))throw new RuntimeException('Recovery input must be in the protected copies directory.');
$admin=parse_ini_file($repo.'/.local/mysqladmin.cnf',false,INI_SCANNER_RAW);
if(!is_array($admin)||($admin['host']??'')!=='127.0.0.1'||(string)($admin['port']??'')!=='3307')throw new RuntimeException('Recovery refuses any database endpoint except127.0.0.1:3307.');
$directory=$keyRoot.'/drill-'.bin2hex(random_bytes(8));mkdir($directory,0700);umask(0077);
$database='bizismart_recovery_'.bin2hex(random_bytes(10));$created=false;$pdo=null;$report=null;
$run=static function(array $command,array $environment=[])use($directory):void {
 $errors=$directory.'/command.error';
 $process=proc_open($command,[0=>['pipe','r'],1=>['file','NUL','w'],2=>['file',$errors,'w']],$pipes,null,array_merge(getenv(),$environment),['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('Cannot start recovery helper.');fclose($pipes[0]);
 $exit=proc_close($process);
 if($exit!==0){
  $details=is_file($errors)?file_get_contents($errors):'';
  $reason='unclassified helper failure';
  foreach(['Private path grants access','unexpected owner','Restore completed','MySQL tool failed','requires PowerShell','not recognized','cannot find','Unable to find','MYSQL_DATABASE must','BACKUP_FILE must','MYSQL_DEFAULTS_FILE must']as$marker)if(stripos($details,$marker)!==false){$reason=$marker;break;}
  // Only redacted infrastructure errors can reach this file, never SQL stderr/data.
  file_put_contents(dirname($directory).'/last-recovery-error.txt',$details);
  throw new RuntimeException('Recovery helper failed (exit '.$exit.', category '.$reason.'). No successful restore claimed.');
 }
};
try {
 backupOpen($input,$directory.'/snapshot.tar.gz',$keyRoot.'/recovery-public.pem',$keyRoot.'/recovery-private.pem',$keyRoot.'/signing-public.pem');
 $run(['C:/Windows/System32/tar.exe','-xzf',$directory.'/snapshot.tar.gz','-C',$directory,'manifest.json','database.sql','application.env']);
 $manifest=json_decode(file_get_contents($directory.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
 if(($manifest['format']??0)!==1||!hash_equals($manifest['sql_sha256'],hash_file('sha256',$directory.'/database.sql'))||!hash_equals($manifest['env_sha256'],hash_file('sha256',$directory.'/application.env')))throw new RuntimeException('Recovery content does not match the authenticated snapshot manifest.');
 $pdo=new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4',$admin['user'],$admin['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 if((int)$pdo->query('SELECT @@port')->fetchColumn()!==3307)throw new RuntimeException('Unexpected local database.');
 $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;
 // Existing local development options may inherit broad ACLs. The production
 // restore guard correctly refuses them; copy into this owner-only scratch
 // directory instead of changing/bypassing that guard or its existing file.
 if(!copy($repo.'/.local/mysqladmin.cnf',$directory.'/client.cnf'))throw new RuntimeException('Cannot create protected local recovery connection.');
 $compatibility=normalizeRecoverySql($directory.'/database.sql',$directory.'/recovery-mysql.sql');
 $run(['pwsh','-NoProfile','-File',$repo.'/infrastructure/prod/restore.ps1'],['MYSQL_DATABASE'=>$database,'BIZISMART_BACKUP_CONFIRM_DB'=>$database,'MYSQL_DEFAULTS_FILE'=>$directory.'/client.cnf','MYSQL_BIN'=>$repo.'/.local/mysql-8.4.11-winx64/bin/mysql.exe','BACKUP_FILE'=>$directory.'/recovery-mysql.sql']);
 $pdo->exec("USE `$database`");
 $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
 sort($tables);$expected=array_keys($manifest['rows']);sort($expected);
 if($tables!==$expected)throw new RuntimeException('Restored schema table list mismatch.');
 $businesses=$pdo->query('SELECT id FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
 $ids=implode(',',array_map('intval',$businesses));if($ids==='')$ids='0';
 $columns=[];
 foreach($tables as$table){
  if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new RuntimeException('Unexpected schema identifier.');
  $statement=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$statement->execute([$database,$table]);$columns[$table]=$statement->fetchAll(PDO::FETCH_COLUMN);
  // Explicit tenant IDs, plus globally nullable infrastructure rows, during this admin recovery inspection.
  $where=in_array('business_id',$columns[$table],true)?" WHERE business_id IN ($ids) OR business_id IS NULL":'';
  if((int)$pdo->query("SELECT COUNT(*) FROM `$table`$where")->fetchColumn()!==(int)$manifest['rows'][$table])throw new RuntimeException('Restored row count mismatch.');
 }
 $statement=$pdo->prepare('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION');$statement->execute([$database]);
 $groups=[];foreach($statement->fetchAll(PDO::FETCH_ASSOC)as$foreign){$groups[$foreign['TABLE_NAME'].':'.$foreign['CONSTRAINT_NAME']][]=$foreign;}
 foreach($groups as$foreign){
  $child=$foreign[0]['TABLE_NAME'];$parent=$foreign[0]['REFERENCED_TABLE_NAME'];$join=[];$present=[];
  foreach($foreign as$column){foreach([$column['COLUMN_NAME'],$column['REFERENCED_COLUMN_NAME'],$child,$parent]as$id)if(!preg_match('/^[A-Za-z0-9_]+$/',$id))throw new RuntimeException('Unexpected relation identifier.');$join[]='c.`'.$column['COLUMN_NAME'].'`=p.`'.$column['REFERENCED_COLUMN_NAME'].'`';$present[]='c.`'.$column['COLUMN_NAME'].'` IS NOT NULL';}
  $scope=in_array('business_id',$columns[$child],true)?" AND (c.business_id IN ($ids) OR c.business_id IS NULL)":'';
  $missing='p.`'.$foreign[0]['REFERENCED_COLUMN_NAME'].'` IS NULL';
  if((int)$pdo->query("SELECT COUNT(*) FROM `$child` c LEFT JOIN `$parent` p ON ".implode(' AND ',$join).' WHERE '.implode(' AND ',$present).' AND '.$missing.$scope)->fetchColumn()!==0)throw new RuntimeException('Restored foreign-key integrity failed.');
 }
 require $repo.'/backend/vendor/autoload.php';
 $environment=Dotenv\Dotenv::parse(file_get_contents($directory.'/application.env'));
 $key=$environment['APP_KEY']??'';if(!str_starts_with($key,'base64:'))throw new RuntimeException('Recovered application key unavailable.');
 $encrypter=new Illuminate\Encryption\Encrypter(base64_decode(substr($key,7),true),'AES-256-CBC');
 $phone=$pdo->query("SELECT owner_phone FROM businesses WHERE subdomain='freshchoice'")->fetchColumn();
 $readable=is_string($phone)&&strlen($encrypter->decryptString($phone))>0;
 unset($environment,$key,$encrypter,$phone);
 if(!$readable)throw new RuntimeException('Existing encrypted owner data cannot be recovered with the backed-up application key.');
 $report=['signature_verified'=>true,'decrypted_archive_hashes_match'=>true,'compatibility'=>$compatibility,'restored_tables'=>count($tables),'restored_rows'=>array_sum($manifest['rows']),'row_counts_match'=>true,'foreign_key_checks'=>count($groups),'encrypted_owner_data_recovered'=>true];
} finally {
 if($created&&$pdo instanceof PDO){$pdo->exec("DROP DATABASE `$database`");$created=false;}
 foreach(glob($directory.'/*')as$file)if(is_file($file))unlink($file);
 foreach(glob($directory.'/.crypto-error-*')as$file)if(is_file($file))unlink($file);
 if(is_dir($directory))rmdir($directory);
}
$report['disposable_database_removed']=!$created;
$report['plaintext_staging_removed']=!is_dir($directory);
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
