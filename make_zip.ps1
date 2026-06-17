Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Copies every amd/src/*.js file -> amd/build/*.min.js so Moodle loads the latest.
# (Moodle ignores amd/src/ at runtime — it only reads amd/build/)
function Sync-AmdBuild {
    param([string]$SourceDir)
    $srcDir   = Join-Path $SourceDir 'amd\src'
    $buildDir = Join-Path $SourceDir 'amd\build'
    if (-not (Test-Path $srcDir)) { return }
    if (-not (Test-Path $buildDir)) { New-Item -ItemType Directory -Path $buildDir | Out-Null }
    Get-ChildItem -Path $srcDir -Filter '*.js' | ForEach-Object {
        $base     = [System.IO.Path]::GetFileNameWithoutExtension($_.Name)
        $destName = $base + '.min.js'
        $destPath = Join-Path $buildDir $destName
        Copy-Item $_.FullName $destPath -Force
        Write-Host "  JS synced: $destName"
    }
}

function Compress-PluginZip {
    param([string]$SourceDir, [string]$ZipPath)

    # Sync all AMD JS source files to build before zipping.
    Sync-AmdBuild -SourceDir $SourceDir

    $parentDir = Split-Path $SourceDir -Parent
    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
    $zipStream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
    $archive   = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
    Get-ChildItem -Path $SourceDir -Recurse -File | ForEach-Object {
        $entryName   = $_.FullName.Substring($parentDir.Length + 1) -replace '\\', '/'
        $entry       = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream  = [System.IO.File]::OpenRead($_.FullName)
        $fileStream.CopyTo($entryStream)
        $fileStream.Close()
        $entryStream.Close()
    }
    $archive.Dispose()
    $zipStream.Dispose()
    $size = [Math]::Round((Get-Item $ZipPath).Length / 1KB, 1)
    Write-Host "Built: $ZipPath ($size KB)"
}

$base = 'C:\Users\HP\Learn-Proctor-Secure-Browser'

Compress-PluginZip -SourceDir "$base\_lg_work\learnguard"       -ZipPath "$base\learnguard_learnguard.zip"
Compress-PluginZip -SourceDir "$base\_lg_work\block_learnguard" -ZipPath "$base\block_learnguard.zip"
# LearnShield is ARCHIVED (2026-06-16) — do not rebuild its ZIP.
# Compress-PluginZip -SourceDir "$base\_ls_work\learnshield" -ZipPath "$base\learnshield_learnshield.zip"
