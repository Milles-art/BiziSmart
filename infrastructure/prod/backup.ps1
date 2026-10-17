#requires -Version 7.4
# Creates PRIVATE PLAINTEXT SQL; encryption/offsite storage remain deployment gates.
# Required: MYSQL_DEFAULTS_FILE, MYSQL_DATABASE, BIZISMART_BACKUP_CONFIRM_DB, BACKUP_DIR.
. (Join-Path $PSScriptRoot 'mysql-common.ps1')
Assert-BackupDatabase
Assert-MySqlConnectionFile
if ([string]::IsNullOrWhiteSpace($env:BACKUP_DIR)) { throw 'BACKUP_DIR is required.' }
$retention = 30
if ($env:RETENTION_DAYS -and (-not [int]::TryParse($env:RETENTION_DAYS, [ref]$retention) -or $retention -lt 1 -or $retention -gt 36500)) {
    throw 'RETENTION_DAYS must be between 1 and 36500.'
}
Initialize-PrivateDirectory $env:BACKUP_DIR
$stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss')
$out = Join-Path $env:BACKUP_DIR ("$($env:MYSQL_DATABASE)-$stamp-$([Guid]::NewGuid().ToString('N')).sql")
$temporaryDump = "$out.part"
$dump = if ($env:MYSQLDUMP_BIN) { $env:MYSQLDUMP_BIN } else { 'mysqldump' }
$flavor = if ($env:MYSQL_DUMP_FLAVOR) { $env:MYSQL_DUMP_FLAVOR } else { 'mysql' }
if ($flavor -cnotin @('mysql','mariadb')) { throw 'MYSQL_DUMP_FLAVOR must be mysql or mariadb.' }
$gtidArguments = if ($flavor -ceq 'mysql') { @('--set-gtid-purged=OFF') } else { @() }
try {
    $file = [IO.File]::Open($temporaryDump, [IO.FileMode]::CreateNew)
    $file.Dispose()
    Assert-PrivatePath $temporaryDump
    Invoke-MySqlTool -Executable $dump -Arguments (@("--defaults-extra-file=$($env:MYSQL_DEFAULTS_FILE)", '--single-transaction', '--routines', '--events', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4', '--hex-blob') + $gtidArguments + @("--result-file=$temporaryDump", $env:MYSQL_DATABASE))
    if ((Get-Item -LiteralPath $temporaryDump).Length -eq 0) { throw 'Backup produced an empty file.' }
    [IO.File]::Move($temporaryDump, $out)
    Get-ChildItem -LiteralPath $env:BACKUP_DIR -File -Filter "$($env:MYSQL_DATABASE)-*.sql" |
        Where-Object { $_.Name -cmatch "^$($env:MYSQL_DATABASE)-[0-9]{8}-[0-9]{6}-[a-f0-9]{32}\.sql$" -and $_.LastWriteTimeUtc -lt [DateTime]::UtcNow.AddDays(-$retention) -and ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0 } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
    "Plaintext backup complete: $out"
} finally {
    if (Test-Path -LiteralPath $temporaryDump) { Remove-Item -LiteralPath $temporaryDump -Force }
}
