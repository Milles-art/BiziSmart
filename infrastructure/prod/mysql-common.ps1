#requires -Version 7.4
# Windows-only operator tooling. Private credentials belong in [client] in an
# owner-only MySQL option file. Never put a password in process arguments.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-BackupDatabase {
    if ($env:MYSQL_DATABASE -cnotmatch '^[A-Za-z][A-Za-z0-9_]{0,63}$') { throw 'MYSQL_DATABASE must be a valid ASCII database identifier (1-64 characters).' }
    if ($env:BIZISMART_BACKUP_CONFIRM_DB -cne $env:MYSQL_DATABASE) { throw 'Set BIZISMART_BACKUP_CONFIRM_DB to the exact target database.' }
}

function Assert-PrivatePath([string]$Path) {
    if (-not $IsWindows) { throw 'Use the .sh helpers on Unix; these scripts validate Windows ACLs.' }
    $item = Get-Item -LiteralPath $Path -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Private paths must not be symbolic links or junctions.' }
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    $allowed = @($sid, 'S-1-5-18', 'S-1-5-32-544')
    $acl = Get-Acl -LiteralPath $Path
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -notin $allowed) { throw 'Private path has an unexpected owner.' }
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        if ($rule.AccessControlType -eq [Security.AccessControl.AccessControlType]::Allow -and $rule.IdentityReference.Value -notin $allowed) {
            throw 'Private path grants access beyond this account, SYSTEM and Administrators; restrict its ACL first.'
        }
    }
}

function Initialize-PrivateDirectory([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) {
        $null = New-Item -ItemType Directory -Path $Path
        $acl = [Security.AccessControl.DirectorySecurity]::new()
        $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
        $acl.SetOwner($sid)
        $acl.SetAccessRuleProtection($true, $false)
        $acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow'))
        Set-Acl -LiteralPath $Path -AclObject $acl
    }
    Assert-PrivatePath $Path
    if (-not (Get-Item -LiteralPath $Path).PSIsContainer) { throw 'BACKUP_DIR must be a directory.' }
}

function Assert-MySqlConnectionFile {
    if ([string]::IsNullOrWhiteSpace($env:MYSQL_DEFAULTS_FILE) -or -not (Test-Path -LiteralPath $env:MYSQL_DEFAULTS_FILE -PathType Leaf)) {
        throw 'MYSQL_DEFAULTS_FILE must be a private MySQL option file.'
    }
    if (-not [IO.Path]::IsPathFullyQualified($env:MYSQL_DEFAULTS_FILE)) { throw 'MYSQL_DEFAULTS_FILE must be an absolute path.' }
    Assert-PrivatePath $env:MYSQL_DEFAULTS_FILE
}

function Invoke-MySqlTool {
    param([string]$Executable, [string[]]$Arguments, [string]$InputFile = '')
    $start = [Diagnostics.ProcessStartInfo]::new()
    $start.FileName = (Get-Command $Executable -CommandType Application -ErrorAction Stop).Source
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.RedirectStandardInput = $InputFile -ne ''
    foreach ($argument in $Arguments) { $start.ArgumentList.Add($argument) }
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $start
    $started = $false
    try {
        if (-not $process.Start()) { throw 'Could not start MySQL tool.' }
        $started = $true
        $stdout = $process.StandardOutput.ReadToEndAsync()
        $stderr = $process.StandardError.ReadToEndAsync()
        if ($InputFile -ne '') {
            $inputStream = [IO.File]::OpenRead($InputFile)
            try { $inputStream.CopyTo($process.StandardInput.BaseStream) }
            finally { $inputStream.Dispose(); $process.StandardInput.Close() }
        }
        $process.WaitForExit()
        $null = $stdout.GetAwaiter().GetResult()
        $nativeError = $stderr.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) {
            # Report only a numeric MySQL code or a known client-compatibility
            # category. Never echo native SQL, credentials or row values.
            $diagnostic = ''
            if ($nativeError -match '\bERROR\s+([0-9]{3,5})\s*(?:\([A-Z0-9]{5}\))?') { $diagnostic = "; MySQL error $($Matches[1])" }
            elseif ($nativeError -match 'Unknown command') { $diagnostic = '; unsupported client directive' }
            throw "MySQL tool failed (exit $($process.ExitCode)$diagnostic); no success recorded. Inspect the database/server safely."
        }
    } finally {
        if ($started -and -not $process.HasExited) { $process.Kill($true); $process.WaitForExit() }
        $process.Dispose()
    }
}
