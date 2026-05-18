Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$sourceDir = 'C:\Users\HP\Documents\Learn-Proctor-Secure-Browser\learnguard-ai'
$zipPath   = 'C:\Users\HP\Documents\Learn-Proctor-Secure-Browser\learnguard_ai_services.zip'
$parentDir = Split-Path $sourceDir -Parent

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$zipStream = [System.IO.File]::Open($zipPath, [System.IO.FileMode]::Create)
$archive   = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

Get-ChildItem -Path $sourceDir -Recurse -File | ForEach-Object {
    $fullPath  = $_.FullName
    $entryName = $fullPath.Substring($parentDir.Length + 1) -replace [regex]::Escape('\'), '/'
    if ([string]::IsNullOrWhiteSpace($entryName)) { return }
    $entry       = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
    $entryStream = $entry.Open()
    $fileStream  = [System.IO.File]::OpenRead($fullPath)
    $fileStream.CopyTo($entryStream)
    $fileStream.Close()
    $entryStream.Close()
}

$archive.Dispose()
$zipStream.Dispose()
$size = [Math]::Round((Get-Item $zipPath).Length / 1KB, 1)
Write-Host "Built: $zipPath ($size KB)"
