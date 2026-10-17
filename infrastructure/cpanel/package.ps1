param([string]$Output = 'tmp/BiziSmart-cpanel-release.zip')
$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$destination = [IO.Path]::GetFullPath((Join-Path $root $Output))
if (!$destination.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Release must remain inside this workspace.' }
if (!(Test-Path -LiteralPath (Join-Path $root 'frontend/dist/index.html'))) { throw 'Run the verified frontend build first.' }
$staging = Join-Path $root ('tmp/cpanel-release-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $staging | Out-Null
$private = Join-Path $staging 'private/bizismart/backend'
$public = Join-Path $staging 'public_html/bizismart'
New-Item -ItemType Directory -Path $private,$public | Out-Null
foreach ($directory in @('app','bootstrap','config','database','public','resources','routes')) {
    Copy-Item -LiteralPath (Join-Path $root "backend/$directory") -Destination $private -Recurse
}
foreach ($file in @('artisan','composer.json','composer.lock','.env.example','.env.shared.example')) {
    Copy-Item -LiteralPath (Join-Path $root "backend/$file") -Destination $private
}
# Cache artifacts may embed local paths/configuration and never belong in a release.
$cache = [IO.Path]::GetFullPath((Join-Path $private 'bootstrap/cache'))
if (!$cache.StartsWith($staging + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Invalid staging cache path.' }
Get-ChildItem -LiteralPath $cache -Filter '*.php' | Remove-Item
foreach ($directory in @('storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs')) {
    New-Item -ItemType Directory -Path (Join-Path $private $directory) -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $private "$directory/.gitignore"), "*`n!.gitignore`n")
}
Copy-Item -Path (Join-Path $root 'frontend/dist/*') -Destination $public -Recurse
foreach ($file in @('.htaccess','gateway.php')) { Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination $public }
$admin = Join-Path $staging 'public_html/bizismart-admin'
New-Item -ItemType Directory -Path $admin | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'admin-index.php') -Destination (Join-Path $admin 'index.php')
Copy-Item -LiteralPath (Join-Path $root 'backend/public/.htaccess') -Destination $admin
foreach ($asset in @('css','js','fonts','favicon.ico','robots.txt')) {
    $source = Join-Path $root "backend/public/$asset"
    if (Test-Path -LiteralPath $source) { Copy-Item -LiteralPath $source -Destination $admin -Recurse }
}
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'shared-cron.sh') -Destination (Join-Path $staging 'private/bizismart')
$backupTools = Join-Path $staging 'private/bizismart/infrastructure/prod'
New-Item -ItemType Directory -Path $backupTools | Out-Null
foreach ($file in @('backup.sh','restore.sh','mysql-common.sh')) { Copy-Item -LiteralPath (Join-Path $root "infrastructure/prod/$file") -Destination $backupTools }
Copy-Item -LiteralPath (Join-Path $root 'docs/14-shared-hosting.md') -Destination (Join-Path $staging 'DEPLOYMENT.md')
# Verify selection, rather than packaging the working tree or its secrets/tests.
$forbidden = Get-ChildItem -LiteralPath $staging -Recurse -File | Where-Object { $_.Name -eq '.env' -or $_.Name -eq '.env.testing' -or $_.Extension -in '.pem','.key','.log','.sql' }
if ($forbidden) { throw 'Forbidden runtime/secret files found in release staging.' }
New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
Compress-Archive -Path (Join-Path $staging '*') -DestinationPath $destination -Force
$hash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash
Write-Output "Source/build release package: $destination"
Write-Output "SHA256: $hash"
Write-Output 'Not installed: Composer no-dev dependencies, secrets, migrations, DNS/HTTPS and host acceptance remain required.'
