<#
.SYNOPSIS
    Starts every process needed to run the Social CRM demo locally on XAMPP/Windows.

.DESCRIPTION
    Opens four separate PowerShell windows, each running one long-lived process:
      1. php artisan serve       -> the web app / API              (http://localhost:8000)
      2. php artisan queue:work  -> processes outbound/webhooks/bot/commerce/default/analytics queues
      3. php artisan reverb:start -> the realtime (websocket) server (ws://localhost:8080)
      4. npm run dev             -> Vite dev server with hot module reload

    Run this from the repository root:
        powershell -ExecutionPolicy Bypass -File backend\start-dev.ps1
    (or from inside backend\ with -File start-dev.ps1 — it cd's to backend\ itself).

    Stop everything by closing the four windows (or Ctrl+C in each).
    After changing PHP code, restart the queue window (queue:work keeps the code in memory).
#>

$ErrorActionPreference = 'Stop'
$backendPath = $PSScriptRoot

function Start-DevWindow {
    param(
        [string]$Title,
        [string]$Command
    )

    Start-Process powershell -ArgumentList @(
        '-NoExit',
        '-Command',
        "`$host.UI.RawUI.WindowTitle = '$Title'; Set-Location '$backendPath'; $Command"
    ) | Out-Null
}

Write-Host "Starting Social CRM dev environment..." -ForegroundColor Cyan

Start-DevWindow -Title 'CRM: web (serve :8000)' -Command 'php artisan serve --port=8000'
Start-Sleep -Seconds 1

Start-DevWindow -Title 'CRM: queue:work' -Command 'php artisan queue:work --queue=outbound,webhooks,bot,commerce,default,analytics --tries=3 --sleep=1'
Start-Sleep -Seconds 1

Start-DevWindow -Title 'CRM: reverb (:8080)' -Command 'php artisan reverb:start --port=8080'
Start-Sleep -Seconds 1

Start-DevWindow -Title 'CRM: vite (npm run dev)' -Command 'npm run dev'

Write-Host ""
Write-Host "Four windows opened:" -ForegroundColor Green
Write-Host "  - php artisan serve            (web + API)"
Write-Host "  - php artisan queue:work        (outbound, webhooks, bot, commerce, default, analytics)"
Write-Host "  - php artisan reverb:start      (realtime websocket server)"
Write-Host "  - npm run dev                   (Vite / hot reload)"
Write-Host ""
Write-Host "App URL:      http://localhost:8000" -ForegroundColor Yellow
Write-Host "Reverb:       ws://localhost:8080" -ForegroundColor Yellow
Write-Host ""
Write-Host "Demo login (password for all: password):" -ForegroundColor Yellow
Write-Host "  admin@crm.test        (Admin — all platforms, settings, simulator)"
Write-Host "  supervisor@crm.test   (Supervisor — all platforms, discounts, reports)"
Write-Host "  mod1@crm.test .. mod6@crm.test  (Moderators — see README.md for each one's platforms)"
Write-Host ""
Write-Host "Simulator (admin only): open the app -> Simulator screen, or run:" -ForegroundColor Yellow
Write-Host "  php artisan crm:simulate --count=300 --seconds=60"
Write-Host ""
