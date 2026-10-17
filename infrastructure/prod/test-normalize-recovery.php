<?php
declare(strict_types=1);
require __DIR__.'/normalize-recovery-sql.php';
$parent=getenv('BIZISMART_CRYPTO_TEST_DIR');if(!$parent||!is_dir($parent))throw new RuntimeException('Private test root required.');
$root=$parent.'/normalizer-'.bin2hex(random_bytes(8));mkdir($root,0700);umask(0077);$passed=0;
$schema="CREATE TABLE `till_sessions` (\n  `open_cashier_id` bigint(20) unsigned GENERATED ALWAYS AS (case when `status` = 'open' then `cashier_id` else NULL end) STORED,\n);\n";
$row="INSERT INTO `till_sessions` (`cashier_id`, `status`, `opening_float`, `note`, `open_cashier_id`) VALUES (17,'open',900000000000,'O\\'Neil, {\"qty\":\"0.750\",\"code\":\"00123\"}',17);\n";
$attempt=function(string $content,bool $accept=true)use($root,&$passed):?string{
 $id=bin2hex(random_bytes(4));$input="$root/$id.sql";$output="$root/$id.out";file_put_contents($input,$content);
 try{normalizeRecoverySql($input,$output);if(!$accept)throw new LogicException('Unsafe derivation accepted.');$passed++;return file_get_contents($output);}
 catch(RuntimeException$error){if($accept)throw$error;if(file_exists($output)||file_exists($output.'.part'))throw new LogicException('Failed normalization published SQL.');$passed++;return null;}
};
try{
 $actual=$attempt($schema.$row);if($actual!==$schema.str_replace("',17);","',DEFAULT);",$row))throw new RuntimeException('Business literals changed.');
 $closed=str_replace(["'open',900","}',17);"],["'closed',900","}',NULL);"],$row);$actual=$attempt($schema.$closed);if(!str_contains($actual,',DEFAULT);'))throw new RuntimeException('Closed derivation not restored.');
 $attempt($schema.str_replace("}',17);","}',18);",$row),false);
 $attempt(str_replace("then `cashier_id`","then `cashier_id` + 1",$schema).$row,false);
 $attempt($schema.str_replace("900000000000,'O", "900000000000,O",$row),false);
 $attempt(str_replace('`open_cashier_id`','`other_generated`',$schema).$row,false);
 $ordinary="INSERT INTO `products` (`name`, `buying_price`) VALUES ('literal DEFAULT, 00123',123456789);\n";if($attempt($ordinary)!==$ordinary)throw new RuntimeException('Unrelated records changed.');
 $attempt($schema.str_replace(",900000000000",'', $row),false);
 echo "Recovery SQL compatibility: $passed checks passed; money/quantity/escaped literals preserved, invalid derivations refused.\n";
}finally{foreach(glob($root.'/*')as$file)if(is_file($file))unlink($file);rmdir($root);}
