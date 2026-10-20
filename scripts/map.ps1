# Repository Symbol & Architecture Map Generator (Aider-style Repo-Map)
# Usage: powershell -ExecutionPolicy Bypass -File ./scripts/map.ps1

$ErrorActionPreference = "Stop"

$outputFile = ".agents/REPO_MAP.md"
$mapContent = New-Object System.Text.StringBuilder

[void]$mapContent.AppendLine("# Repository Symbol & Architecture Map")
[void]$mapContent.AppendLine("> Auto-generated repository map. Use this to inspect code architecture with minimal token cost.`n")
[void]$mapContent.AppendLine("Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')`n")

# Directories to search
$targetDirs = @("src", "lib", "app", "pkg", "core", "api")
$existingDirs = @()
foreach ($d in $targetDirs) {
    if (Test-Path $d) { $existingDirs += $d }
}

# If no standard subfolder exists, scan root files (excluding ignored directories)
$excludedDirs = @("node_modules", "dist", "build", ".venv", "venv", ".git", ".agents", "specs", "scripts", "out", "target")

$filesToScan = @()
if ($existingDirs.Count -gt 0) {
    $filesToScan = Get-ChildItem -Path $existingDirs -Recurse -File -Include "*.php","*.ts","*.tsx","*.js","*.jsx","*.py","*.go","*.rs","*.sql" -ErrorAction SilentlyContinue
} else {
    $allFiles = Get-ChildItem -Path . -Recurse -File -Include "*.php","*.ts","*.tsx","*.js","*.jsx","*.py","*.go","*.rs","*.sql" -ErrorAction SilentlyContinue
    foreach ($f in $allFiles) {
        $skip = $false
        foreach ($exc in $excludedDirs) {
            if ($f.FullName -replace '\\', '/' -match "/$exc/") {
                $skip = $true
                break
            }
        }
        if (-not $skip) {
            $filesToScan += $f
        }
    }
}

$scannedCount = 0

if ($filesToScan.Count -gt 0) {
    foreach ($file in $filesToScan) {
        $relPath = Resolve-Path -Relative $file.FullName
        $relPath = $relPath -replace '^\.\\', '' -replace '\\', '/'
        
        $lines = Get-Content $file.FullName
        $fileSymbols = @()

        for ($i = 0; $i -lt $lines.Count; $i++) {
            $line = $lines[$i]
            $trimmed = $line.Trim()
            $lineNum = $i + 1

            # Match PHP class, interface, trait, enum, and public functions
            if ($line -match '^(final\s+|readonly\s+|abstract\s+)*(class|interface|trait|enum)\s+([A-Za-z0-9_]+)' -or
                $line -match '^\s*(public\s+)?function\s+([A-Za-z0-9_]+)\s*\(') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
            # Match TypeScript / JavaScript exported definitions or top-level declarations
            elseif ($line -match '^export\s+(default\s+)?(class|interface|type|enum|function|const)\s+([A-Za-z0-9_]+)' -or
                $line -match '^(class|interface|type|enum|function)\s+([A-Za-z0-9_]+)') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
            # Match Python top-level class and functions or class methods
            elseif ($line -match '^(class|def)\s+([A-Za-z0-9_]+)' -or $line -match '^    def\s+([A-Za-z0-9_]+)') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
            # Match Go types and functions (top-level only)
            elseif ($line -match '^func\s+(\(.*?\)\s+)?([A-Za-z0-9_]+)\s*\(.*?\)') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
            elseif ($line -match '^type\s+([A-Za-z0-9_]+)\s+(struct|interface)') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
            # Match SQL table/view creations
            elseif ($trimmed -match '^(CREATE\s+(TABLE|VIEW|INDEX))\s+([A-Za-z0-9_\.]+)') {
                $fileSymbols += "  - L${lineNum}: $trimmed"
            }
        }

        if ($fileSymbols.Count -gt 0) {
            [void]$mapContent.AppendLine("### ``$relPath``")
            foreach ($sym in $fileSymbols) {
                [void]$mapContent.AppendLine($sym)
            }
            [void]$mapContent.AppendLine("")
            $scannedCount++
        }
    }
}

if ($scannedCount -eq 0) {
    [void]$mapContent.AppendLine('*(Belum ada file kode sumber aplikasi atau simbol yang terdeteksi di direktori proyek)*')
    [void]$mapContent.AppendLine('Peta ini akan otomatis terisi saat file kode (.ts, .py, .go, .sql, dll.) dibuat di folder proyek.')
}

Set-Content -Path $outputFile -Value $mapContent.ToString() -Encoding UTF8
Write-Host " PASS: Repository Map berhasil diperbarui di $outputFile ($scannedCount file dipetakan)." -ForegroundColor Green
