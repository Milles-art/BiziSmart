param([ValidateSet('Start', 'Stop', 'Status')][string]$Action = 'Status')
$ErrorActionPreference = 'Stop'
$projectRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$runtimeRoot = Join-Path $projectRoot '.local'
$binary = Join-Path $runtimeRoot 'mysql-8.4.11-winx64/bin/mysqld.exe'
$config = Join-Path $runtimeRoot 'mysql.ini'
$adminConfig = Join-Path $runtimeRoot 'mysqladmin.cnf'

if (!(Test-Path -LiteralPath $binary) -or !(Test-Path -LiteralPath $config)) {
    throw 'Local MySQL is not installed. Follow docs/12-local-database.md first.'
}

switch ($Action) {
    'Start' {
        $probe = [System.Net.Sockets.TcpClient]::new()
        try { $probe.Connect('127.0.0.1', 3307); $occupied = $true }
        catch { $occupied = $false }
        finally { $probe.Dispose() }
        if ($occupied) {
            & php (Join-Path $PSScriptRoot 'mysql-setup.php') verify
            if ($LASTEXITCODE -ne 0) { throw 'Port 3307 is occupied and does not match BiziSmart MySQL.' }
            return
        }
        $databaseProcess = Start-Process -FilePath $binary -ArgumentList "--defaults-file=`"$config`"" -WindowStyle Hidden -PassThru
        Write-Output 'Starting BiziSmart MySQL on 127.0.0.1:3307...'
        $ready = $false
        for ($attempt = 0; $attempt -lt 60; $attempt++) {
            if ($databaseProcess.HasExited) { throw 'MySQL exited during startup. Inspect .local/mysql-error.log privately.' }
            $probe = [System.Net.Sockets.TcpClient]::new()
            try { $probe.Connect('127.0.0.1', 3307); $ready = $true }
            catch { }
            finally { $probe.Dispose() }
            if ($ready) { break }
            Start-Sleep -Seconds 2
        }
        if (!$ready) { throw 'MySQL startup timed out. Inspect .local/mysql-error.log privately.' }
        & php (Join-Path $PSScriptRoot 'mysql-setup.php') verify
        if ($LASTEXITCODE -ne 0) { throw 'MySQL started but its BiziSmart checks failed.' }
    }
    'Stop' {
        & (Join-Path $runtimeRoot 'mysql-8.4.11-winx64/bin/mysqladmin.exe') "--defaults-extra-file=$adminConfig" shutdown
        if ($LASTEXITCODE -ne 0) { throw 'MySQL did not shut down cleanly.' }
        Write-Output 'BiziSmart MySQL stopped.'
    }
    'Status' {
        & php (Join-Path $PSScriptRoot 'mysql-setup.php') verify
        if ($LASTEXITCODE -ne 0) { throw 'BiziSmart MySQL is not ready.' }
    }
}
