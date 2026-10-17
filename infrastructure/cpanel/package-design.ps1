param([string]$Output = 'tmp/BiziSmart-all-pages-design-20261016.zip')
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$buildRoot = Join-Path $repoRoot 'frontend/dist'
$archivePath = [IO.Path]::GetFullPath((Join-Path $repoRoot $Output))
if (!$archivePath.StartsWith($repoRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Archive must stay in the workspace.' }
if (!(Test-Path -LiteralPath (Join-Path $buildRoot 'index.html')) -or !(Test-Path -LiteralPath (Join-Path $buildRoot 'sw.js'))) { throw 'Complete the production PWA build first.' }
$files = @(Get-ChildItem -LiteralPath $buildRoot -Recurse -File | Sort-Object FullName)
$entries = @($files | ForEach-Object {
    $relative = [IO.Path]::GetRelativePath($buildRoot, $_.FullName).Replace('\', '/')
    if ($relative -notmatch '^(assets/[^/]+\.(js|css|png|jpg|jpeg|webp|svg|woff2?)|index\.html|bizismart\.svg|manifest\.webmanifest|registerSW\.js|sw\.js|workbox-[A-Za-z0-9_-]+\.js)$') { throw "Unexpected public build file: $relative" }
    [pscustomobject]@{ path = $relative; sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant(); bytes = $_.Length; source = $_.FullName }
})
New-Item -ItemType Directory -Path (Split-Path $archivePath) -Force | Out-Null
$stream = [IO.File]::Open($archivePath, [IO.FileMode]::Create)
try {
    $zip = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Create)
    try { foreach ($entry in $entries) { [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $entry.source, $entry.path, [IO.Compression.CompressionLevel]::Optimal) | Out-Null } }
    finally { $zip.Dispose() }
} finally { $stream.Dispose() }
$zip = [IO.Compression.ZipFile]::OpenRead($archivePath)
try {
    if ($zip.Entries.Count -ne $entries.Count) { throw 'Entry count differs.' }
    foreach ($expected in $entries) {
        $entry = $zip.GetEntry($expected.path)
        if (!$entry) { throw "Archive entry missing: $($expected.path)" }
        $inputStream = $entry.Open(); $sha = [Security.Cryptography.SHA256]::Create()
        try { $actual = [Convert]::ToHexString($sha.ComputeHash($inputStream)).ToLowerInvariant() } finally { $inputStream.Dispose(); $sha.Dispose() }
        if ($actual -ne $expected.sha256) { throw "Archive entry differs: $($expected.path)" }
    }
} finally { $zip.Dispose() }
$manifest = [ordered]@{ built_at_utc = [DateTime]::UtcNow.ToString('o'); archive = [IO.Path]::GetFileName($archivePath); sha256 = (Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash.ToLowerInvariant(); bytes = (Get-Item -LiteralPath $archivePath).Length; entries = @($entries | Select-Object path,sha256,bytes) }
$manifestPath = [IO.Path]::ChangeExtension($archivePath, '.manifest.json')
$manifest | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $manifestPath -Encoding utf8NoBOM
$checksumLines = @($entries | ForEach-Object { "$($_.sha256)  $($_.path)" })
# GNU sha256sum treats CR as part of a filename; write portable LF records explicitly.
[IO.File]::WriteAllText([IO.Path]::ChangeExtension($archivePath, '.sha256'), ($checksumLines -join "`n") + "`n", [Text.Encoding]::ASCII)
[pscustomobject]@{ archive=$manifest.archive; sha256=$manifest.sha256; bytes=$manifest.bytes; entry_count=$manifest.entries.Count } | ConvertTo-Json
