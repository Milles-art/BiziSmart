#requires -Version 7.4
# Restore trusted plaintext SQL into an EXISTING explicitly confirmed database.
# SQL is executable code; never use this operator tool for untrusted uploads.
. (Join-Path $PSScriptRoot 'mysql-common.ps1')
Assert-BackupDatabase
Assert-MySqlConnectionFile
if ([string]::IsNullOrWhiteSpace($env:BACKUP_FILE) -or -not (Test-Path -LiteralPath $env:BACKUP_FILE -PathType Leaf)) { throw 'BACKUP_FILE must be a nonempty SQL file.' }
Assert-PrivatePath $env:BACKUP_FILE
if ((Get-Item -LiteralPath $env:BACKUP_FILE).Length -eq 0) { throw 'BACKUP_FILE must be a nonempty SQL file.' }
$mysql = if ($env:MYSQL_BIN) { $env:MYSQL_BIN } else { 'mysql' }
Invoke-MySqlTool -Executable $mysql -Arguments @("--defaults-extra-file=$($env:MYSQL_DEFAULTS_FILE)", '--binary-mode=1', '--default-character-set=utf8mb4', "--database=$($env:MYSQL_DATABASE)") -InputFile $env:BACKUP_FILE
"Restore completed into $($env:MYSQL_DATABASE); application/data verification is still required."
