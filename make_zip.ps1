Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Creates a Moodle-compatible zip with forward-slash paths.
# IMPORTANT: Always copies amd/src/assessment.js -> amd/build/assessment.min.js
# before zipping, because Moodle loads the compiled build file, not the source.
function Compress-PluginZip {
    param([string]$SourceDir, [string]$ZipPath)

    # Sync JS build from source (critical — Moodle ignores amd/src/ at runtime).
    $srcJs   = Join-Path $SourceDir 'amd\src\assessment.js'
    $buildJs = Join-Path $SourceDir 'amd\build\assessment.min.js'
    if (Test-Path $srcJs) {
        Copy-Item $srcJs $buildJs -Force
        Write-Host "  JS build synced: assessment.min.js"
    }

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

$base = 'C:\Users\HP\Documents\Learn-Proctor-Secure-Browser'

Compress-PluginZip -SourceDir "$base\_lg_work\learnguard"  -ZipPath "$base\learnguard_learnguard.zip"
Compress-PluginZip -SourceDir "$base\_ls_work\learnshield" -ZipPath "$base\learnshield_learnshield.zip"
