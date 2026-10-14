<?php
// Regenerate the independent frontend QR fixture with the installed Filament dependency.
require __DIR__.'/../../vendor/autoload.php';
$options=new \chillerlan\QRCode\QROptions(['version'=>3,'eccLevel'=>\chillerlan\QRCode\Common\EccLevel::L,'maskPattern'=>0,'addQuietzone'=>false]);
$matrix=(new \chillerlan\QRCode\QRCode($options))->addByteSegment('BSL:0123456789abcdef0123456789abcdef')->getQRMatrix()->getMatrix(true);
echo json_encode($matrix,JSON_PRETTY_PRINT);
