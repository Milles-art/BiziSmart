# Run from PowerShell after setting up backend/.env. Installs only this project's local runtime.
$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$runtimeRoot = Join-Path $projectRoot '.local'
$archive = Join-Path $runtimeRoot 'downloads/mysql-8.4.11-winx64.zip'
$binary = Join-Path $runtimeRoot 'mysql-8.4.11-winx64/bin/mysqld.exe'
$config = Join-Path $runtimeRoot 'mysql.ini'
$bootstrapConfig = Join-Path $runtimeRoot 'mysql-bootstrap.ini'

if (Test-Path -LiteralPath (Join-Path $runtimeRoot 'mysql-data/auto.cnf')) {
    throw 'An existing MySQL data directory was found. Use mysql.ps1 Start/Status; do not initialise it again.'
}
if (!(Test-Path -LiteralPath $archive)) {
    New-Item -ItemType Directory -Path (Split-Path $archive) -Force | Out-Null
    & curl.exe --fail --location --retry 3 --output $archive 'https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.11-winx64.zip'
    if ($LASTEXITCODE -ne 0) { throw 'MySQL download failed.' }
}
if ((Get-FileHash -LiteralPath $archive -Algorithm MD5).Hash -ne '2E833921898A9A030EA6BFE81BD811BC') {
    throw 'MySQL archive does not match the checksum on the official download page.'
}
if (!(Test-Path -LiteralPath $binary)) {
    & tar.exe -xf $archive -C $runtimeRoot
    if ($LASTEXITCODE -ne 0) { throw 'MySQL archive extraction failed.' }
}
if (!(Test-Path -LiteralPath $config)) {
    & php (Join-Path $PSScriptRoot 'mysql-setup.php') prepare
    if ($LASTEXITCODE -ne 0) { throw 'MySQL configuration preparation failed.' }
}
$probe = [System.Net.Sockets.TcpClient]::new()
try { $probe.Connect('127.0.0.1', 3307); throw 'Port 3307 is already occupied; stop installation.' }
catch [System.Net.Sockets.SocketException] { }
finally { $probe.Dispose() }

& $binary "--defaults-file=$config" --initialize-insecure
if ($LASTEXITCODE -ne 0) { throw 'MySQL initialisation failed. Inspect .local/mysql-error.log privately.' }
# init-file assigns generated passwords before the first server accepts client connections.
Start-Process -FilePath $binary -ArgumentList "--defaults-file=`"$bootstrapConfig`"" -WindowStyle Hidden | Out-Null
$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    & php (Join-Path $PSScriptRoot 'mysql-setup.php') verify 2>$null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 1
}
if (!$ready) { throw 'MySQL did not become ready. Inspect .local/mysql-error.log privately.' }
& php (Join-Path $PSScriptRoot 'mysql-setup.php') configure
if ($LASTEXITCODE -ne 0) { throw 'Laravel local database configuration failed.' }
& (Join-Path $PSScriptRoot 'mysql.ps1') Stop
Remove-Item -LiteralPath (Join-Path $runtimeRoot 'mysql-init.sql'), $bootstrapConfig
& (Join-Path $PSScriptRoot 'mysql.ps1') Start
Write-Output 'Installation complete. Run mysql.ps1 Status to verify the normal startup.'
