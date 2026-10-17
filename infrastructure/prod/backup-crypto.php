<?php
declare(strict_types=1);

/** OpenSSL CMS SignedData over AES-256-CBC EnvelopedData, RSA-OAEP/SHA256.
 * Signature is checked with the separately pinned certificate BEFORE decryption.
 * No private key/password/data is emitted in process output or shell arguments.
 */
function backupCryptoRun(array $arguments, string $directory): void
{
    $errors = tempnam($directory, '.crypto-error-');
    if ($errors === false) throw new RuntimeException('Cannot create private crypto status file.');
    chmod($errors, 0600);
    try {
        $binary = getenv('BIZISMART_OPENSSL') ?: 'openssl';
        $process = proc_open(array_merge([$binary], $arguments), [0=>['pipe','r'],1=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','w'],2=>['file',$errors,'w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start cryptography process.');
        fclose($pipes[0]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Cryptography verification failed (exit '.$exit.'). No recovery success.');
    } finally { if (is_file($errors)) unlink($errors); }
}

function backupSeal(string $input, string $output, string $recipient, string $signer, string $signingKey): void
{
    $envelope=$output.'.envelope.part'; $signed=$output.'.part';
    foreach ([$output,$envelope,$signed] as $path) if (file_exists($path) || is_link($path)) throw new RuntimeException('Refusing existing backup output.');
    try {
        backupCryptoRun(['cms','-encrypt','-binary','-aes-256-cbc','-in',$input,'-outform','DER','-out',$envelope,'-recip',$recipient,'-keyopt','rsa_padding_mode:oaep','-keyopt','rsa_oaep_md:sha256'],dirname($output));
        chmod($envelope,0600);
        backupCryptoRun(['cms','-sign','-binary','-nodetach','-nocerts','-md','sha256','-in',$envelope,'-outform','DER','-out',$signed,'-signer',$signer,'-inkey',$signingKey],dirname($output));
        chmod($signed,0600);
        if (!is_file($signed) || filesize($signed)<1 || !rename($signed,$output)) throw new RuntimeException('Cannot publish protected backup.');
    } finally { foreach ([$envelope,$signed] as $path) if (is_file($path)) unlink($path); }
}

function backupOpen(string $input, string $output, string $recipient, string $recoveryKey, string $trustedSigner): void
{
    $envelope=$output.'.envelope.part'; $plain=$output.'.part';
    foreach ([$output,$envelope,$plain] as $path) if (file_exists($path) || is_link($path)) throw new RuntimeException('Refusing existing recovery output.');
    try {
        // -noverify skips public CA trust, NOT the signature/content checks.
        // -nointern forces the separately pinned local certificate, never an embedded signer.
        backupCryptoRun(['cms','-verify','-binary','-inform','DER','-in',$input,'-certfile',$trustedSigner,'-nointern','-noverify','-out',$envelope],dirname($output));
        chmod($envelope,0600);
        backupCryptoRun(['cms','-decrypt','-binary','-inform','DER','-in',$envelope,'-recip',$recipient,'-inkey',$recoveryKey,'-out',$plain],dirname($output));
        chmod($plain,0600);
        if (!is_file($plain) || filesize($plain)<1 || !rename($plain,$output)) throw new RuntimeException('Cannot publish verified recovery file.');
    } finally { foreach ([$envelope,$plain] as $path) if (is_file($path)) unlink($path); }
}
