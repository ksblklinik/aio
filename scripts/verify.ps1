# Verification Script for jstudio (PowerShell)
# Usage: powershell -ExecutionPolicy Bypass -File ./scripts/verify.ps1

$ErrorActionPreference = "Stop"
$scriptFailed = $false

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "  jstudio Pre-Commit Verification Gate   " -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

# 1. Security Check: Ensure sensitive files and hardcoded secrets are not staged
Write-Host "`n[1/4] Checking for staged secrets & sensitive data..." -ForegroundColor Yellow
$stagedFiles = git diff --name-only --cached 2>$null
if ($stagedFiles) {
    foreach ($file in $stagedFiles) {
        if ($file -match "^\.env" -and $file -notmatch "\.env\.example") {
            Write-Host "CRITICAL ERROR: Staged sensitive file detected: $file" -ForegroundColor Red
            $scriptFailed = $true
        }
    }

    # Inspect staged diff content for common secret patterns (added lines only)
    $addedLines = (git diff --cached 2>$null) | Where-Object { $_ -match '^\+[^\+]' }
    if ($addedLines) {
        $addedContent = $addedLines -join "`n"
        $secretPatterns = @(
            "-----BEGIN (RSA|EC|DSA|OPENSSH|PGP) PRIVATE KEY-----",
            "sk_live_[0-9a-zA-Z]{24,}",
            "sk_test_[0-9a-zA-Z]{24,}",
            "AKIA[0-9A-Z]{16}",
            "ghp_[0-9a-zA-Z]{36}",
            "gho_[0-9a-zA-Z]{36}",
            "xox[baprs]-[0-9a-zA-Z]{10,48}"
        )
        foreach ($pattern in $secretPatterns) {
            if ($addedContent -match $pattern) {
                Write-Host "CRITICAL ERROR: Detected potential hardcoded secret in added lines: $pattern" -ForegroundColor Red
                Write-Host "                Pindahkan secret ke .env lokal dan gunakan .env.example untuk referensi." -ForegroundColor Red
                $scriptFailed = $true
                break
            }
        }
    }
}
if (-not $scriptFailed) {
    Write-Host " PASS: No sensitive files or hardcoded secrets detected." -ForegroundColor Green
}

# 2. Spec-Drift Check: If code is staged, ensure a spec or docs is referenced/updated
Write-Host "`n[2/4] Checking spec synchronization..." -ForegroundColor Yellow
$hasCodeStaged = $false
$hasSpecStaged = $false

if ($stagedFiles) {
    foreach ($file in $stagedFiles) {
        if ($file -match "^specs/") { $hasSpecStaged = $true }
        if ($file -match "\.(ts|js|tsx|jsx|php|py|go|rs|sql)$") { $hasCodeStaged = $true }
    }
    if ($hasCodeStaged -and -not $hasSpecStaged) {
        Write-Host " WARNING: Code changes are staged without corresponding specs/ update." -ForegroundColor Yellow
        Write-Host "          Pastikan checkbox di dokumen spec terkait sudah dicentang." -ForegroundColor Yellow
    } else {
        Write-Host " PASS: Spec synchronization check clean." -ForegroundColor Green
    }
} else {
    Write-Host " INFO: Staging area is empty. Ready for checks." -ForegroundColor Gray
}

# 3. Automated Tests & Type Check (Auto-detect stack)
Write-Host "`n[3/4] Running automated test suites..." -ForegroundColor Yellow
$ranAnyTest = $false

if (Test-Path "composer.json") {
    Write-Host " Detected PHP project..." -ForegroundColor Cyan
    $ranAnyTest = $true
    if (Test-Path "vendor/bin/phpunit.bat") {
        .\vendor\bin\phpunit.bat --testdox
        if ($LASTEXITCODE -ne 0) { $scriptFailed = $true }
    } elseif (Test-Path "vendor/bin/phpunit") {
        php vendor/bin/phpunit --testdox
        if ($LASTEXITCODE -ne 0) { $scriptFailed = $true }
    }
}

if (Test-Path "package.json") {
    Write-Host " Detected Node.js / Frontend project..." -ForegroundColor Cyan
    $ranAnyTest = $true
    $pkgJson = Get-Content "package.json" -Raw | ConvertFrom-Json
    if ($pkgJson.scripts.test) {
        npm test
        if ($LASTEXITCODE -ne 0) { $scriptFailed = $true }
    }
    if ($pkgJson.scripts.typecheck) {
        npm run typecheck
        if ($LASTEXITCODE -ne 0) { $scriptFailed = $true }
    }
}

if ((Test-Path "pyproject.toml") -or (Test-Path "requirements.txt")) {
    Write-Host " Detected Python project..." -ForegroundColor Cyan
    $ranAnyTest = $true
    if (Get-Command pytest -ErrorAction SilentlyContinue) {
        pytest
        if ($LASTEXITCODE -ne 0) { $scriptFailed = $true }
    }
}

if (-not $ranAnyTest) {
    Write-Host " INFO: No runtime stack detected yet. Skipping runtime test suite." -ForegroundColor Gray
}

# 4. Git Working Tree Status
Write-Host "`n[4/4] Git repository status check..." -ForegroundColor Yellow
git status --short
Write-Host " PASS: Git status inspected." -ForegroundColor Green

Write-Host "`n==========================================" -ForegroundColor Cyan
if ($scriptFailed) {
    Write-Host " VERIFICATION FAILED! Selesaikan issue di atas sebelum commit." -ForegroundColor Red
    exit 1
} else {
    Write-Host " ALL CHECKS PASSED. Siap untuk atomic commit." -ForegroundColor Green
    exit 0
}
