#requires -Version 7.4
# No database access: every MySQL invocation goes to a fake argv-safe process.
. (Join-Path $PSScriptRoot 'mysql-common.ps1')
$testRoot = Join-Path ([IO.Path]::GetTempPath()) ('bizismart-helper-test-' + [Guid]::NewGuid().ToString('N'))
$names = 'MYSQL_DEFAULTS_FILE','MYSQL_DATABASE','BIZISMART_BACKUP_CONFIRM_DB','BACKUP_DIR','BACKUP_FILE','MYSQLDUMP_BIN','MYSQL_BIN','MYSQL_DUMP_FLAVOR','RETENTION_DAYS','FAKECLI_MODE','FAKECLI_PAYLOAD','FAKECLI_LOG'
$original = @{}
foreach ($name in $names) { $original[$name] = [Environment]::GetEnvironmentVariable($name) }
$checks = 0
function Check([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw "Helper test failed: $Message" }
    $script:checks++
}
function Run-Helper([string]$Name) {
    $output = & (Join-Path $PSHOME 'pwsh.exe') -NoProfile -File (Join-Path $PSScriptRoot "$Name.ps1") 2>&1 | Out-String
    return @{ Exit = $LASTEXITCODE; Output = $output }
}
try {
    Initialize-PrivateDirectory $testRoot
    $env:MYSQL_DATABASE = 'bizismart_prod_testfake'
    $env:BIZISMART_BACKUP_CONFIRM_DB = $env:MYSQL_DATABASE
    $env:MYSQL_DEFAULTS_FILE = Join-Path $testRoot 'client.cnf'
    [IO.File]::WriteAllText($env:MYSQL_DEFAULTS_FILE, "[client]`npassword=`"fake-only & ! Unicode 茶`"`n")
    $env:BACKUP_DIR = Join-Path $testRoot 'backups'
    Initialize-PrivateDirectory $env:BACKUP_DIR
    $env:FAKECLI_LOG = Join-Path $testRoot 'calls.txt'
    $env:RETENTION_DAYS = '30'
    $env:MYSQL_DUMP_FLAVOR = 'mysql'
    $env:FAKECLI_PAYLOAD = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("-- café 中文 🛒`nINSERT INTO example VALUES ('quote \\ 茶');`n"))
    $fake = Join-Path $testRoot 'fake-mysql.exe'
    $fakeSource = Join-Path $testRoot 'fake-mysql.cs'
    [IO.File]::WriteAllText($fakeSource, @'
using System;
using System.IO;
public static class FakeMysql {
    public static int Main(string[] args) {
        if (args.Length == 0 || !args[0].StartsWith("--defaults-extra-file=")) return 19;
        foreach (string arg in args) if (arg.StartsWith("--password")) return 19;
        File.AppendAllText(Environment.GetEnvironmentVariable("FAKECLI_LOG"), "called\n");
        string payload = Environment.GetEnvironmentVariable("FAKECLI_PAYLOAD");
        string mode = Environment.GetEnvironmentVariable("FAKECLI_MODE");
        foreach (string arg in args) {
            if (arg.StartsWith("--result-file=")) {
                bool hasGtid = Array.IndexOf(args, "--set-gtid-purged=OFF") >= 0;
                if (hasGtid != (Environment.GetEnvironmentVariable("MYSQL_DUMP_FLAVOR") == "mysql")) return 18;
                File.WriteAllBytes(arg.Substring("--result-file=".Length), Convert.FromBase64String(payload));
                return mode == "faildump" ? 23 : 0;
            }
        }
        using (MemoryStream memory = new MemoryStream()) {
            Console.OpenStandardInput().CopyTo(memory);
            if (Convert.ToBase64String(memory.ToArray()) != payload) return 24;
        }
        if (mode == "failrestore") {
            Console.Error.WriteLine("ERROR 1273 (HY000): PRIVATE_SQL_VALUE_MUST_NOT_BE_PRINTED");
            return 25;
        }
        return 0;
    }
}
'@)
    $compiler = Join-Path $env:WINDIR 'Microsoft.NET/Framework64/v4.0.30319/csc.exe'
    if (-not (Test-Path -LiteralPath $compiler)) { throw 'The no-DB Windows regression needs the existing Windows .NET Framework C# compiler; do not install one automatically.' }
    & $compiler /nologo /target:exe "/out:$fake" $fakeSource
    if ($LASTEXITCODE -ne 0) { throw 'Could not compile the isolated fake native CLI.' }
    $env:MYSQLDUMP_BIN = $fake; $env:MYSQL_BIN = $fake
    $old = Join-Path $env:BACKUP_DIR ("$($env:MYSQL_DATABASE)-20000101-000000-$('a' * 32).sql")
    $unrelated = Join-Path $env:BACKUP_DIR "$($env:MYSQL_DATABASE)-manual.sql"
    [IO.File]::WriteAllText($old, 'old'); [IO.File]::WriteAllText($unrelated, 'manual')
    (Get-Item -LiteralPath $old).LastWriteTimeUtc = [DateTime]::UtcNow.AddDays(-90)
    (Get-Item -LiteralPath $unrelated).LastWriteTimeUtc = [DateTime]::UtcNow.AddDays(-90)

    $env:FAKECLI_MODE = 'faildump'
    $failed = Run-Helper 'backup'
    Check ($failed.Exit -ne 0 -and $failed.Output -notmatch 'Plaintext backup complete') 'native dump failure must fail'
    Check (Test-Path -LiteralPath $old) 'failed dump must not prune prior backup'
    Check (@(Get-ChildItem -LiteralPath $env:BACKUP_DIR -Filter '*.part').Count -eq 0) 'failed dump must remove temporary file'
    Check (@(Get-ChildItem -LiteralPath $env:BACKUP_DIR -Filter '*.sql').Count -eq 2) 'failed dump must not publish partial SQL'

    $env:FAKECLI_MODE = 'success'
    $success = Run-Helper 'backup'
    Check ($success.Exit -eq 0 -and $success.Output -match 'Plaintext backup complete') "successful dump must publish: $($success.Output)"
    Check (-not (Test-Path -LiteralPath $old)) 'successful publication must prune only expired helper files'
    Check (Test-Path -LiteralPath $unrelated) 'retention must preserve unrelated SQL'
    $completed = @(Get-ChildItem -LiteralPath $env:BACKUP_DIR -File | Where-Object { $_.Name -ne (Split-Path $unrelated -Leaf) })
    Check ($completed.Count -eq 1 -and [Convert]::ToBase64String([IO.File]::ReadAllBytes($completed[0].FullName)) -ceq $env:FAKECLI_PAYLOAD) 'published SQL must preserve Unicode bytes'
    Assert-PrivatePath $completed[0].FullName
    $env:BACKUP_FILE = $completed[0].FullName
    $restored = Run-Helper 'restore'
    Check ($restored.Exit -eq 0 -and $restored.Output -match 'Restore completed') 'restore must preserve exact Unicode bytes'
    $env:MYSQL_DUMP_FLAVOR = 'mariadb'
    Check ((Run-Helper 'backup').Exit -eq 0) 'MariaDB dump must omit the unsupported MySQL GTID option'
    $beforeFlavor = [IO.File]::ReadAllText($env:FAKECLI_LOG)
    $env:MYSQL_DUMP_FLAVOR = 'invalid'
    Check ((Run-Helper 'backup').Exit -ne 0) 'unknown dump flavor must fail'
    Check ([IO.File]::ReadAllText($env:FAKECLI_LOG) -ceq $beforeFlavor) 'invalid flavor must not invoke native dump'
    $env:MYSQL_DUMP_FLAVOR = 'mysql'
    $env:FAKECLI_MODE = 'failrestore'
    $failedRestore = Run-Helper 'restore'
    Check ($failedRestore.Exit -ne 0 -and $failedRestore.Output -notmatch 'Restore completed') 'native restore failure must fail'
    Check ($failedRestore.Output -match 'MySQL error 1273') 'native failure must identify only its numeric MySQL diagnostic'
    Check ($failedRestore.Output -notmatch 'PRIVATE_SQL_VALUE_MUST_NOT_BE_PRINTED') 'native diagnostics must not expose SQL or row contents'

    $calls = [IO.File]::ReadAllText($env:FAKECLI_LOG)
    $env:BIZISMART_BACKUP_CONFIRM_DB = ''
    Check ((Run-Helper 'restore').Exit -ne 0) 'production-like arbitrary database needs exact confirmation'
    $env:MYSQL_DATABASE = 'invalid`db'; $env:BIZISMART_BACKUP_CONFIRM_DB = $env:MYSQL_DATABASE
    Check ((Run-Helper 'restore').Exit -ne 0) 'SQL identifier injection must be rejected'
    $env:MYSQL_DATABASE = 'bizismart_prod_testfake'; $env:BIZISMART_BACKUP_CONFIRM_DB = $env:MYSQL_DATABASE
    $env:RETENTION_DAYS = '-1'
    Check ((Run-Helper 'backup').Exit -ne 0) 'negative retention must be rejected'
    $env:RETENTION_DAYS = '30'
    $privateCredentialAcl = Get-Acl -LiteralPath $env:MYSQL_DEFAULTS_FILE
    $acl = Get-Acl -LiteralPath $env:MYSQL_DEFAULTS_FILE
    $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new([Security.Principal.SecurityIdentifier]::new('S-1-1-0'), 'Read', 'Allow'))
    Set-Acl -LiteralPath $env:MYSQL_DEFAULTS_FILE -AclObject $acl
    Check ((Run-Helper 'backup').Exit -ne 0) 'world-readable connection must be rejected'
    Set-Acl -LiteralPath $env:MYSQL_DEFAULTS_FILE -AclObject $privateCredentialAcl
    # icacls changes only the DACL; Set-Acl requests an unavailable SACL privilege
    # on this host for existing directories, even with a fresh descriptor.
    $null = & (Join-Path $env:WINDIR 'System32/icacls.exe') $env:BACKUP_DIR '/grant' '*S-1-1-0:(R)' '/q'
    if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the isolated unsafe-DACL fixture.' }
    Check ((Run-Helper 'backup').Exit -ne 0) 'unsafe existing staging directory must be rejected'
    Check ([IO.File]::ReadAllText($env:FAKECLI_LOG) -ceq $calls) 'validation failures must not execute a native tool'
    "Production helper tests passed: $checks assertions; fake processes only, no database access."
} finally {
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $original[$name]) }
    # Remove only the files and direct child directory created by this test.
    if (Test-Path -LiteralPath (Join-Path $testRoot 'backups')) {
        Get-ChildItem -LiteralPath (Join-Path $testRoot 'backups') -File | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
        Remove-Item -LiteralPath (Join-Path $testRoot 'backups') -Force
    }
    if (Test-Path -LiteralPath $testRoot) {
        Get-ChildItem -LiteralPath $testRoot -File | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Force }
        Remove-Item -LiteralPath $testRoot -Force
    }
}
