# Installs scripts/git-hooks/pre-commit into .git/hooks/ for this repository
# and runs the hook's self-test. Run from anywhere inside the repo:
#   powershell -ExecutionPolicy Bypass -File scripts/install-hooks.ps1
#   pwsh scripts/install-hooks.ps1
# Keep this file ASCII-only: Windows PowerShell reads a BOM-less .ps1 as ANSI,
# and a UTF-8 dash decodes to a curly quote that terminates strings early.
$ErrorActionPreference = 'Stop'

$root = (& git rev-parse --show-toplevel 2>$null)
if (-not $root) { Write-Error 'Not inside a git repository.'; exit 1 }
$root = $root.Trim()

$src    = Join-Path $root 'scripts/git-hooks/pre-commit'
$dstDir = Join-Path $root '.git/hooks'
$dst    = Join-Path $dstDir 'pre-commit'

if (-not (Test-Path $src)) { Write-Error ('Hook source not found: ' + $src); exit 1 }
if (-not (Test-Path $dstDir)) { New-Item -ItemType Directory -Path $dstDir | Out-Null }

# Copy with LF line endings and no BOM (bash refuses CRLF shebangs).
$content = [System.IO.File]::ReadAllText($src) -replace "`r`n", "`n"
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($dst, $content, $utf8NoBom)

Write-Host 'Installed pre-commit hook:'
Write-Host ('  ' + $src)
Write-Host ('  -> ' + $dst)
Write-Host ''
# Use Git for Windows' own bash, not whatever 'bash' is on PATH (on Windows
# that is often C:\Windows\System32\bash.exe, the WSL launcher).
$gitExe  = (Get-Command git).Source
$gitRoot = Split-Path (Split-Path $gitExe -Parent) -Parent
$bash = $null
foreach ($candidate in @('bin\bash.exe', 'usr\bin\bash.exe')) {
    $p = Join-Path $gitRoot $candidate
    if (Test-Path $p) { $bash = $p; break }
}
if (-not $bash) {
    $cmd = Get-Command bash -ErrorAction SilentlyContinue |
           Where-Object { $_.Source -notlike '*\System32\*' } | Select-Object -First 1
    if ($cmd) { $bash = $cmd.Source }
}
if (-not $bash) { Write-Error 'Git Bash not found (looked under the Git install and on PATH).'; exit 1 }

Write-Host ('Running self-test with ' + $bash + ' (stages nothing):')
& $bash $dst --test
if ($LASTEXITCODE -ne 0) {
    Write-Warning 'Self-test reported failures - read the output above.'
    exit $LASTEXITCODE
}
Write-Host ''
Write-Host 'Done. Bypass only with: FORCE_COMMIT=1 git commit ...'
