<?php
declare(strict_types=1);
require __DIR__.'/backup-crypto.php';
$parent=getenv('BIZISMART_CRYPTO_TEST_DIR');
if (!$parent || !is_dir($parent)) throw new RuntimeException('Provide an existing private crypto test directory.');
$root=rtrim($parent,'/\\').'/crypto-'.bin2hex(random_bytes(8));
mkdir($root,0700);umask(0077);
$passed=0;
$reject=function(callable $operation)use(&$passed):void {try{$operation();}catch(RuntimeException){$passed++;return;}throw new RuntimeException('Unsafe crypto input was accepted.');};
try {
 foreach (['recipient','signer','wrong'] as $name) backupCryptoRun(['req','-x509','-newkey','rsa:2048','-nodes','-keyout',"$root/$name.key",'-out',"$root/$name.pem",'-days','1','-subj','/CN=Disposable backup test'], $root);
 $data="BiziSmart Unicode: Kiswahili — bidhaa\n".random_bytes(524300)."\x00\xff";
 file_put_contents("$root/input",$data);
 backupSeal("$root/input","$root/backup.cms","$root/recipient.pem","$root/signer.pem","$root/signer.key");
 backupOpen("$root/backup.cms","$root/recovered","$root/recipient.pem","$root/recipient.key","$root/signer.pem");
 if(!hash_equals(hash('sha256',$data),hash_file('sha256',"$root/recovered"))) throw new RuntimeException('Binary/Unicode roundtrip failed.');$passed++;
 $reject(fn()=>backupOpen("$root/backup.cms","$root/wrong-key-output","$root/recipient.pem","$root/wrong.key","$root/signer.pem"));
 $reject(fn()=>backupOpen("$root/backup.cms","$root/wrong-signer-output","$root/recipient.pem","$root/recipient.key","$root/wrong.pem"));
 $cipher=file_get_contents("$root/backup.cms");$offset=intdiv(strlen($cipher),2);$cipher[$offset]=chr(ord($cipher[$offset])^1);file_put_contents("$root/tampered.cms",$cipher);
 $reject(fn()=>backupOpen("$root/tampered.cms","$root/tampered-output","$root/recipient.pem","$root/recipient.key","$root/signer.pem"));
 file_put_contents("$root/truncated.cms",substr($cipher,0,100));
 $reject(fn()=>backupOpen("$root/truncated.cms","$root/truncated-output","$root/recipient.pem","$root/recipient.key","$root/signer.pem"));
 $reject(fn()=>backupOpen("$root/backup.cms","$root/recovered","$root/recipient.pem","$root/recipient.key","$root/signer.pem"));
 foreach(['wrong-key-output','wrong-signer-output','tampered-output','truncated-output']as$name) foreach(['','.part','.envelope.part']as$suffix) if(file_exists("$root/$name$suffix")) throw new RuntimeException('Failed authentication exposed/published recovery output.');$passed++;
 echo "Backup cryptography: $passed checks passed (binary/Unicode roundtrip, wrong key, wrong signer, tamper, truncation, overwrite refusal, failed-output cleanup).\n";
} finally {
 foreach(glob($root.'/*')as$file)if(is_file($file))unlink($file);
 foreach(glob($root.'/.crypto-error-*')as$file)if(is_file($file))unlink($file);
 rmdir($root);
}
