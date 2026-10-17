<?php
declare(strict_types=1);

/** Split trusted mysqldump VALUES without changing any quoted/binary literal. */
function recoverySqlValues(string $text):array
{
 $values=[];$start=0;$quote=null;$depth=0;$length=strlen($text);
 for($i=0;$i<$length;$i++){
  $char=$text[$i];
  if($quote!==null){
   if($char==='\\'){$i++;continue;}
   if($char===$quote){if($i+1<$length&&$text[$i+1]===$quote){$i++;continue;}$quote=null;}
   continue;
  }
  if($char==="'"||$char==='"'){$quote=$char;continue;}
  if($char==='('){$depth++;continue;}if($char===')'){if(--$depth<0)throw new RuntimeException('Invalid recovered SQL nesting.');continue;}
  if($char===','&&$depth===0){$values[]=trim(substr($text,$start,$i-$start));$start=$i+1;}
 }
 if($quote!==null||$depth!==0)throw new RuntimeException('Unterminated recovered SQL literal.');
 $values[]=trim(substr($text,$start));return$values;
}

/** MariaDB permits stored-generated values in its dump; MySQL requires DEFAULT.
 * Only the existing till derived-open-cashier expression is allowed. Every other
 * byte of business literals stays unchanged. Original authenticated SQL retained.
 */
function normalizeRecoverySql(string $input,string $output):array
{
 if(file_exists($output)||is_link($output)||file_exists($output.'.part'))throw new RuntimeException('Refusing existing normalized SQL.');
 $read=fopen($input,'rb');$write=fopen($output.'.part','xb');$table=null;$known=false;$changed=0;
 if(!$read||!$write)throw new RuntimeException('Cannot stage recovered SQL.');
 try{
  while(($line=fgets($read))!==false){
   if(preg_match('/^CREATE TABLE `([A-Za-z0-9_]+)`/',$line,$match))$table=$match[1];
   if(preg_match('/^\s*`.*\bGENERATED\s+ALWAYS\b/i',$line)){
    $valid="~^\\s*`open_cashier_id`\\s+bigint(?:\\(20\\))?\\s+unsigned\\s+GENERATED\\s+ALWAYS\\s+AS\\s*\\(case\\s+when\\s+`status`\\s*=\\s*'open'\\s+then\\s+`cashier_id`\\s+else\\s+NULL\\s+end\\)\\s+STORED,?\\s*$~i";
    if($table!=='till_sessions'||!preg_match($valid,$line))throw new RuntimeException('Unsupported generated expression: matching-engine recovery required.');$known=true;
   }
   if(preg_match('/^INSERT INTO `till_sessions` \((.+)\) VALUES \((.*)\);\s*$/',$line,$match)){
    $columns=array_map('trim',explode(',',$match[1]));$index=array_search('`open_cashier_id`',$columns,true);
    if($index!==false){
     if(!$known)throw new RuntimeException('Generated schema must precede restored data.');
     $values=recoverySqlValues($match[2]);if(count($values)!==count($columns))throw new RuntimeException('Recovered till column/value mismatch.');
     foreach($values as$value){
      $scalar=preg_match('/^(?:NULL|DEFAULT|-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|0x[0-9A-Fa-f]*)$/D',$value);
      $string=preg_match("~^(?:_[A-Za-z0-9]+[ ]*)?'(?:[^'\\\\]|\\\\.|'')*'$~sD",$value);
      if(!$scalar&&!$string)throw new RuntimeException('Unexpected recovered till literal.');
     }
     $status=array_search('`status`',$columns,true);$cashier=array_search('`cashier_id`',$columns,true);
     if($status===false||$cashier===false||!in_array($values[$status],["'open'","'closed'"],true)||!preg_match('/^[1-9][0-9]*$/',$values[$cashier]))throw new RuntimeException('Unknown recovered till derivation.');
     $expected=$values[$status]==="'open'"?$values[$cashier]:'NULL';
     if($values[$index]!==$expected)throw new RuntimeException('Recorded generated till value disagrees with original status/cashier.');
     $values[$index]='DEFAULT';$line='INSERT INTO `till_sessions` ('.$match[1].') VALUES ('.implode(',',$values).");\n";$changed++;
    }
   }
   if(fwrite($write,$line)!==strlen($line))throw new RuntimeException('Recovered SQL write failed.');
  }
  fclose($read);$read=null;fclose($write);$write=null;
  if(!rename($output.'.part',$output))throw new RuntimeException('Cannot publish normalized recovery SQL.');
  return['generated_till_rows_verified'=>$changed,'normalization'=>'Only validated generated open_cashier_id assignments changed to DEFAULT.'];
 }finally{if(is_resource($read))fclose($read);if(is_resource($write))fclose($write);if(is_file($output.'.part'))unlink($output.'.part');}
}
