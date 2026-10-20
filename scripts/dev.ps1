# Dev Helper Script for aio (PowerShell)
# Usage:
#   powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1
#   powershell -ExecutionPolicy Bypass -File ./scripts/dev.ps1 -SetupOnly

param (
    [switch]$SetupOnly = $false,
    [switch]$CheckOnly = $false
)

$ErrorActionPreference = "Stop"

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "         aio Local Dev Environment        " -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan

# 1. Environment & Git Hooks Setup
Write-Host "`n[1/4] Checking environment & git hooks..." -ForegroundColor Yellow
if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Copy-Item ".env.example" ".env"
        Write-Host " [+] .env tidak ditemukan. Berhasil menyalin dari .env.example." -ForegroundColor Green
        Write-Host "     PERINGATAN: Sesuaikan nilai rahasia di .env sesuai kebutuhan Anda." -ForegroundColor DarkYellow
    } else {
        Write-Host " [!] .env.example tidak ditemukan. Lewati salin .env." -ForegroundColor Yellow
    }
} else {
    Write-Host " PASS: File .env lokal sudah aktif." -ForegroundColor Green
}

# Ensure Git hooks path is always wired up
if (Test-Path ".githooks") {
    git config core.hooksPath .githooks
    Write-Host " PASS: Git hooks path (.githooks) aktif." -ForegroundColor Green
}

# 2. Dependency & Stack Setup
Write-Host "`n[2/4] Checking project stack & dependencies..." -ForegroundColor Yellow
$hasDevServer = $false
$devCommand = ""

# Check PHP & Composer
if (Test-Path "composer.json") {
    Write-Host " Detected PHP environment..." -ForegroundColor Cyan
    if (-not (Test-Path "vendor")) {
        Write-Host " vendor/ belum terpasang. Menjalankan composer install..." -ForegroundColor Yellow
        if (Test-Path "C:\laragon\bin\composer\composer.phar") {
            php C:\laragon\bin\composer\composer.phar install --no-interaction
        } elseif (Get-Command composer -ErrorAction SilentlyContinue) {
            composer install --no-interaction
        }
        if ($LASTEXITCODE -eq 0) {
            Write-Host " PASS: Dependensi Composer (vendor/) berhasil dipasang." -ForegroundColor Green
        } else {
            Write-Host " FAIL: Gagal memasang dependensi Composer." -ForegroundColor Red
            exit 1
        }
    } else {
        Write-Host " PASS: vendor/ sudah tersedia." -ForegroundColor Green
    }
}

# Check Node.js / Vite
if (Test-Path "package.json") {
    Write-Host " Detected Node.js / Vite environment..." -ForegroundColor Cyan
    $pkgJson = Get-Content "package.json" -Raw | ConvertFrom-Json

    # Check node_modules
    if (-not (Test-Path "node_modules")) {
        Write-Host " node_modules belum terpasang. Menjalankan npm install..." -ForegroundColor Yellow
        npm install
        if ($LASTEXITCODE -eq 0) {
            Write-Host " PASS: Dependensi node_modules berhasil dipasang." -ForegroundColor Green
        } else {
            Write-Host " FAIL: Gagal memasang dependensi node_modules." -ForegroundColor Red
            exit 1
        }
    } else {
        Write-Host " PASS: node_modules sudah tersedia." -ForegroundColor Green
    }

    if ($pkgJson.scripts.dev) {
        $hasDevServer = $true
        $devCommand = "npm run dev"
    }
}

# 3. Project Plugin & MCP Config Inspection
Write-Host "`n[3/4] Inspecting workspace plugin & MCP configuration..." -ForegroundColor Yellow
$pluginMcpPath = ".agents/plugins/aio/mcp_config.json"
if (-not (Test-Path $pluginMcpPath)) {
    $pluginMcpPath = ".agents/plugins/jstudio/mcp_config.json"
}
if (Test-Path $pluginMcpPath) {
    try {
        $mcpContent = Get-Content $pluginMcpPath -Raw | ConvertFrom-Json
        $serverCount = ($mcpContent.mcpServers.PSObject.Properties | Measure-Object).Count
        Write-Host " PASS: $pluginMcpPath valid ($serverCount server aktif)." -ForegroundColor Green
    } catch {
        Write-Host " WARNING: File $pluginMcpPath memiliki format JSON tidak valid." -ForegroundColor Red
    }
} else {
    Write-Host " INFO: Plugin MCP config belum dibuat." -ForegroundColor Gray
}

# 4. Repository Symbol Map Sync
Write-Host "`n[4/4] Updating repository symbol map (.agents/REPO_MAP.md)..." -ForegroundColor Yellow
if (Test-Path "scripts/map.ps1") {
    powershell -ExecutionPolicy Bypass -File ./scripts/map.ps1
}

Write-Host "`n==========================================" -ForegroundColor Cyan
if ($CheckOnly -or $SetupOnly) {
    Write-Host " Dev environment setup / check selesai!" -ForegroundColor Green
    exit 0
}

if ($hasDevServer) {
    Write-Host " Memulai development server ($devCommand)..." -ForegroundColor Cyan
    Invoke-Expression $devCommand
} else {
    Write-Host " Setup dev selesai. Siap untuk bekerja!" -ForegroundColor Green
}
