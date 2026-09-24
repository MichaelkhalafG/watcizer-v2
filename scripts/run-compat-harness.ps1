<#
.SYNOPSIS
  Boot both hosts and run the compat harness (`compat:diff`) locally.

.DESCRIPTION
  The harness is the only thing that proves the compat contract — the promise that core answers the
  legacy endpoints byte-for-byte, which is what switch night depends on. It had become unrunnable
  because the boot recipe lived in people's heads: which PHP, which ports, which environment values,
  and which of them must be overridden rather than taken from the file.

  This script is that recipe, executable.

  WHAT IT OVERRIDES, AND WHY EXACTLY THESE

    MAIL_MAILER=log   on BOTH hosts. The harness places REAL COD checkouts against both, and a real
                      checkout sends real mail: on 2026-09-13 a run mailed four real administrators
                      about harness order 3381, because both .env files carry production SMTP and a
                      real admin list. `compat:diff` refuses to start when it can send, which is the
                      correct guard — this override is what satisfies it, set in the PROCESS, never
                      written to a file.

    DB_USERNAME       on the LEGACY host, taken from core/.env at run time. THIS IS THE THING THAT
    DB_PASSWORD       HAD MADE THE HARNESS UNRUNNABLE FOR TWO WAVES. `backend/.env` is the
    DB_HOST           production file: it names the right host, port and database — the local copy
    DB_PORT           carries the same name — but it authenticates as the PRODUCTION database user,
    DB_DATABASE       which does not exist in the local MariaDB. Every DB-touching legacy request
                      therefore answered 500:

                          SQLSTATE[HY000] [1045] Access denied for user '<production user>'@'localhost'

                      Which is exactly what a reviewer sees as "environment issues": the app boots,
                      the port answers, `artisan serve` says nothing is wrong, and then every case
                      fails for a reason that has nothing to do with the compat layer. The harness's
                      own token pre-flight caught it as "legacy 500, compat 200" and stopped — it
                      was right to, and this is the fix it was asking for.

                      The values are READ FROM core/.env AT RUN TIME and set in the process
                      environment. None is printed, none is written to a file, and the production
                      credentials in backend/.env are neither read nor used. An empty local password
                      is passed as Laravel's own `(empty)` sentinel, because a Windows environment
                      variable cannot hold an empty string — and an UNSET variable would silently
                      fall back to the production password in the file, which is the whole defect.

  WHAT IT DELIBERATELY DOES NOT OVERRIDE

    DB_CONNECTION     Each app keeps its own driver name (core `mariadb`, legacy `mysql`). They are
                      the same server; forcing one would only break whichever app did not expect it.
    PUBLIC_API_KEY    The legacy app's value already equals core's COMPAT_API_KEY, and JWT_SECRET
    JWT_SECRET        already matches too — switch-night prerequisite (f), satisfied locally. The
                      script VERIFIES this by fingerprint and refuses if it ever stops being true,
                      because a mismatch produces a run where every authenticated case fails for a
                      reason that looks like a code defect.
    APP_KEY           Each app keeps its own. Nothing here needs to decrypt the other's data.

  No value from either .env is ever printed. The parity check compares SHA-256 prefixes.

.PARAMETER SkipRun
  Boot both hosts, verify them, and stop — for debugging the environment without a full run.

.PARAMETER Pace
  Milliseconds before every legacy request. The legacy host throttles 60/min per IP; raise this if
  a run starts failing on 429s.

.EXAMPLE
  pwsh -File scripts/run-compat-harness.ps1
#>
[CmdletBinding()]
param(
    [switch] $SkipRun,
    [int]    $Pace = 0,
    [int]    $LegacyPort = 8011,
    [int]    $CorePort = 8001
)

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
$corePath   = Join-Path $root 'core'
$legacyPath = Join-Path $root 'backend'
$php     = 'C:\tools\php83\php.exe'

function Fail([string] $message) {
    Write-Host "  FAIL  $message" -ForegroundColor Red
    exit 1
}

function Read-EnvValue([string] $file, [string] $key) {
    if (-not (Test-Path $file)) { return $null }
    foreach ($line in Get-Content $file) {
        $trimmed = $line.Trim()
        if ($trimmed.StartsWith("$key=")) {
            return $trimmed.Substring($key.Length + 1).Trim().Trim('"').Trim("'")
        }
    }
    return $null
}

function Fingerprint([string] $value) {
    if ([string]::IsNullOrEmpty($value)) { return '(unset)' }
    $sha = [System.Security.Cryptography.SHA256]::Create()
    $hash = $sha.ComputeHash([System.Text.Encoding]::UTF8.GetBytes($value))
    return ([System.BitConverter]::ToString($hash) -replace '-', '').Substring(0, 10).ToLower()
}

Write-Host ''
Write-Host '== preflight ==' -ForegroundColor Cyan

if (-not (Test-Path $php))                          { Fail "PHP not found at $php" }
if (-not (Test-Path (Join-Path $legacyPath 'artisan'))) { Fail "the legacy app is not at $legacyPath" }
if (-not (Test-Path (Join-Path $legacyPath 'vendor')))  { Fail "legacy vendor/ is missing — run composer install in backend/" }
if (-not (Test-Path (Join-Path $corePath 'vendor')))    { Fail "core vendor/ is missing — run composer install in core/" }

$phpVersion = (& $php -r 'echo PHP_VERSION;')
Write-Host "  php            $phpVersion  ($php)"

# ── the database must be up, or every case fails for the wrong reason ────────────────────────
$dbUp = Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue
if (-not $dbUp) { Fail 'MariaDB is not listening on 3306. Start XAMPP MySQL first.' }
Write-Host '  database       up on 3306'

# ── prerequisite (f): the shared secrets must MATCH, or the run is meaningless ───────────────
$coreEnv   = Join-Path $corePath '.env'
$legacyEnv = Join-Path $legacyPath '.env'

$coreKey = Read-EnvValue $coreEnv   'COMPAT_API_KEY'
$legKey  = Read-EnvValue $legacyEnv 'PUBLIC_API_KEY'
$coreJwt = Read-EnvValue $coreEnv   'JWT_SECRET'
$legJwt  = Read-EnvValue $legacyEnv 'JWT_SECRET'

Write-Host "  api key        core $(Fingerprint $coreKey)  legacy $(Fingerprint $legKey)"
Write-Host "  jwt secret     core $(Fingerprint $coreJwt)  legacy $(Fingerprint $legJwt)"

if ([string]::IsNullOrEmpty($coreKey) -or $coreKey -ne $legKey) {
    Fail 'COMPAT_API_KEY (core) != PUBLIC_API_KEY (legacy). Every case would fail on auth, looking like a code defect. This is switch-night prerequisite (f).'
}
if ([string]::IsNullOrEmpty($coreJwt) -or $coreJwt -ne $legJwt) {
    Fail 'JWT_SECRET differs between the two apps. The authenticated cases would fail for the wrong reason.'
}

# ── a CACHED config makes every override below inert, mail guard included ────────────────────
<#
  `config:cache` bakes env() into bootstrap/cache/config.php and Laravel then never reads the
  process environment again. That would not merely un-fix the database — it would quietly restore
  `MAIL_MAILER=smtp`, which is the guard standing between a harness run and four real people
  receiving mail about a test order. So this refuses rather than clearing the cache for you: on a
  machine where somebody cached a config on purpose, deleting it behind their back is its own bug.
#>
foreach ($app in @(@{ name = 'core'; path = $corePath }, @{ name = 'legacy'; path = $legacyPath })) {
    $cached = Join-Path $app.path 'bootstrap/cache/config.php'
    if (Test-Path $cached) {
        Fail "$($app.name) has a CACHED config ($cached). Every environment override this script sets would be ignored — including MAIL_MAILER=log. Run `php artisan config:clear` in $($app.path) and try again."
    }
}
Write-Host '  config cache   neither app has one (process-env overrides take effect)'

# ── the legacy host must be pointed at the LOCAL database, not the production one ─────────────
$localDb = [ordered]@{
    DB_HOST     = Read-EnvValue $coreEnv 'DB_HOST'
    DB_PORT     = Read-EnvValue $coreEnv 'DB_PORT'
    DB_DATABASE = Read-EnvValue $coreEnv 'DB_DATABASE'
    DB_USERNAME = Read-EnvValue $coreEnv 'DB_USERNAME'
    DB_PASSWORD = Read-EnvValue $coreEnv 'DB_PASSWORD'
}

foreach ($key in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME')) {
    if ([string]::IsNullOrEmpty($localDb[$key])) {
        Fail "core/.env has no $key. This script takes the LOCAL database credentials from there; it will not guess them and it will not read backend/.env, which holds production values."
    }
}

if ($localDb['DB_HOST'] -notin @('127.0.0.1', 'localhost', '::1')) {
    # The standing rule: never touch a non-local database. A harness placing real COD orders against
    # a remote host is the worst possible way to discover that core/.env had been re-pointed.
    Fail "core/.env DB_HOST is not local. The harness PLACES REAL ORDERS — it runs against a local database only."
}

$legacyDbName = Read-EnvValue $legacyEnv 'DB_DATABASE'
Write-Host "  database name  core $(Fingerprint $localDb['DB_DATABASE'])  legacy $(Fingerprint $legacyDbName)"
if ($legacyDbName -ne $localDb['DB_DATABASE']) {
    # Not fatal: the override below puts both hosts on core's database regardless, which is what the
    # compat contract needs (same data, two implementations). Worth saying out loud all the same.
    Write-Host "  note           backend/.env names a DIFFERENT database; this run puts both hosts on core's." -ForegroundColor Yellow
}

$credsDiffer = (Read-EnvValue $legacyEnv 'DB_USERNAME') -ne $localDb['DB_USERNAME']
Write-Host "  db credentials $(if ($credsDiffer) { 'legacy .env differs — OVERRIDDEN to core''s local values for this run' } else { 'already identical' })"

# ── the ports must be free, or we would diff against somebody else's server ──────────────────
foreach ($port in @($LegacyPort, $CorePort)) {
    if (Test-NetConnection -ComputerName 127.0.0.1 -Port $port -InformationLevel Quiet -WarningAction SilentlyContinue) {
        Fail "port $port is already in use. Stop whatever is listening — diffing against an unknown server proves nothing."
    }
}
Write-Host "  ports          $LegacyPort and $CorePort free"

# ── boot ─────────────────────────────────────────────────────────────────────────────────────
Write-Host ''
Write-Host '== booting both hosts ==' -ForegroundColor Cyan

<#
  MAIL_MAILER=log in the PROCESS environment of each server. Laravel's env() reads the process
  environment first, so this wins over the .env file without touching it — the wave-2 precedent
  ("process-env overrides only, never a file edit") and the thing that stops a harness run mailing
  real people.
#>
$env:MAIL_MAILER = 'log'

<#
  CORE_MAIL_PARK=1 — the other half of the same guard (review 🟠-5, 2026-09-17).

  MAIL_MAILER=log stops mail going out DURING the run. It does nothing about what the run leaves
  BEHIND: every COD checkout the harness places writes `pending` rows into `integration_outbox`,
  addressed to the real ORDER_ADMIN_EMAILS, and they sit there until somebody runs
  `php artisan mail:drain` on a host with real SMTP. Then four real people are told about harness
  order 3381 — days later, with nothing on screen connecting the two.

  With this set, every outbox row the run writes is PARKED: a resting state no path ever claims.
  `mail:drain` additionally refuses any row belonging to an order that has a parked row, which
  covers the ones written by a retry or by a dashboard status change afterwards.
#>
$env:CORE_MAIL_PARK = '1'

<#
  The local database credentials, into the process environment both servers inherit.

  For CORE these are the values it already reads from its own .env, so they change nothing. For
  LEGACY they replace the production credentials in backend/.env, which is the fix.

  `(empty)` is Laravel's own sentinel for an empty value (Illuminate\Support\Env resolves it to '').
  It is needed because a Windows environment variable cannot BE an empty string: assigning '' to
  $env:DB_PASSWORD deletes the variable, Laravel then falls through to the .env file, and the legacy
  host quietly goes back to trying the production password.
#>
foreach ($entry in $localDb.GetEnumerator()) {
    $value = if ([string]::IsNullOrEmpty($entry.Value)) { '(empty)' } else { $entry.Value }
    Set-Item -Path "Env:\$($entry.Key)" -Value $value
}
Write-Host '  database       both hosts pointed at the local copy (credentials from core/.env, never printed)'

$servers = @()

$servers += Start-Process -FilePath $php -WorkingDirectory $legacyPath -PassThru -WindowStyle Hidden `
    -ArgumentList @('artisan', 'serve', '--port', $LegacyPort) `
    -RedirectStandardOutput (Join-Path $env:TEMP 'harness-legacy.log') `
    -RedirectStandardError  (Join-Path $env:TEMP 'harness-legacy.err')

$servers += Start-Process -FilePath $php -WorkingDirectory $corePath -PassThru -WindowStyle Hidden `
    -ArgumentList @('artisan', 'serve', '--port', $CorePort) `
    -RedirectStandardOutput (Join-Path $env:TEMP 'harness-core.log') `
    -RedirectStandardError  (Join-Path $env:TEMP 'harness-core.err')

function Stop-Servers {
    foreach ($server in $servers) {
        if ($server -and -not $server.HasExited) {
            Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
        }
    }
    # `artisan serve` spawns the built-in server as a CHILD; killing the parent leaves it holding
    # the port, which is how a "port already in use" mystery starts.
    Get-CimInstance Win32_Process -Filter "Name like '%php%'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "-S 127\.0\.0\.1:($LegacyPort|$CorePort)" } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

try {
    <#
      Both hosts must ANSWER, and answer WELL.

      The earlier version accepted any HTTP response as "live", which is how a host with no working
      database passed as healthy: it was listening, it replied, and it replied 500 to everything.
      `/api/catalog/meta` reads the catalogue, so a 5xx here means the process is up and its
      database is not — the single most likely local failure, and the one worth naming precisely
      instead of letting it surface 127 cases later.
    #>
    function Probe-Host([int] $port) {
        try {
            $r = Invoke-WebRequest "http://127.0.0.1:$port/api/catalog/meta" -Headers @{ 'Api-Code' = $coreKey } -TimeoutSec 5 -UseBasicParsing
            return [int] $r.StatusCode
        } catch {
            if ($_.Exception.Response -ne $null) { return [int] $_.Exception.Response.StatusCode }
            return 0                          # not listening yet
        }
    }

    $deadline = (Get-Date).AddSeconds(45)
    $legacyStatus = 0
    $coreStatus   = 0

    while ((Get-Date) -lt $deadline -and -not ($legacyStatus -ne 0 -and $coreStatus -ne 0)) {
        Start-Sleep -Milliseconds 700
        if ($legacyStatus -eq 0) { $legacyStatus = Probe-Host $LegacyPort }
        if ($coreStatus   -eq 0) { $coreStatus   = Probe-Host $CorePort }
    }

    if ($legacyStatus -eq 0) { Fail "the legacy host never answered on $LegacyPort — see $env:TEMP\harness-legacy.err" }
    if ($coreStatus   -eq 0) { Fail "the core host never answered on $CorePort — see $env:TEMP\harness-core.err" }

    foreach ($host_ in @(@{ name = 'legacy'; status = $legacyStatus; log = 'harness-legacy' }, @{ name = 'core'; status = $coreStatus; log = 'harness-core' })) {
        if ($host_.status -ge 500) {
            Fail ("the $($host_.name) host answered $($host_.status) on /api/catalog/meta — it is running but cannot serve the catalogue. " +
                  "Almost always the database: check that MariaDB holds the local copy and that core/.env's credentials open it. " +
                  "The exception is at the end of $(if ($host_.name -eq 'legacy') { "$legacyPath\storage\logs\laravel.log" } else { "$corePath\storage\logs\laravel.log" }).")
        }
    }

    Write-Host "  legacy         LIVE on http://127.0.0.1:$LegacyPort  (catalogue answered $legacyStatus)"
    Write-Host "  core           LIVE on http://127.0.0.1:$CorePort  (catalogue answered $coreStatus)"

    if ($SkipRun) {
        Write-Host ''
        Write-Host 'Both hosts are live. -SkipRun was given, so nothing was diffed.' -ForegroundColor Yellow
        Write-Host 'Press Enter to stop them.'
        [void](Read-Host)
        exit 0
    }

    # ── the run ──────────────────────────────────────────────────────────────────────────────
    Write-Host ''
    Write-Host '== compat:diff ==' -ForegroundColor Cyan

    Push-Location $corePath
    & $php artisan compat:diff --legacy="http://127.0.0.1:$LegacyPort" --compat="http://127.0.0.1:$CorePort" --pace=$Pace
    $exit = $LASTEXITCODE
    Pop-Location

    Write-Host ''
    if ($exit -eq 0) {
        Write-Host 'HARNESS PASSED — zero unexplained differences.' -ForegroundColor Green
    } else {
        Write-Host "HARNESS FAILED (exit $exit). The report directory is named above." -ForegroundColor Red
    }
    exit $exit
}
finally {
    Write-Host ''
    Write-Host '== stopping both hosts ==' -ForegroundColor Cyan
    Stop-Servers
    Start-Sleep -Seconds 2
    foreach ($port in @($LegacyPort, $CorePort)) {
        $stillUp = Test-NetConnection -ComputerName 127.0.0.1 -Port $port -InformationLevel Quiet -WarningAction SilentlyContinue
        Write-Host "  port $port     $(if ($stillUp) { 'STILL LISTENING — kill it by hand' } else { 'free' })"
    }
    # Leave no override behind in this shell: the next thing run here must see the real files.
    Remove-Item Env:\MAIL_MAILER -ErrorAction SilentlyContinue
    Remove-Item Env:\CORE_MAIL_PARK -ErrorAction SilentlyContinue
    foreach ($key in @('DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD')) {
        Remove-Item "Env:\$key" -ErrorAction SilentlyContinue
    }
}
