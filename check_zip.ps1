Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead('C:\Users\HP\Documents\Learn-Proctor-Secure-Browser\learnguard_learnguard.zip')
$zip.Entries | Select-Object -First 10 | ForEach-Object { Write-Host $_.FullName }
$zip.Dispose()
