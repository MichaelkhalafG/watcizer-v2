<#
.SYNOPSIS
    Replace the LOCAL copy of the legacy tables with a fresh phpMyAdmin export from production.

.DESCRIPTION
    Core and legacy share one local database: 65 legacy tables the production export owns, and the
    core-owned tables (catalog_*, storefront_*, core_*, promotion_*, inventory_movements,
    integration_outbox, payment_reconciliation_findings) which the export knows nothing about and
    which must survive untouched.

    ── Why this is a script and not `mysql < dump.sql` ─────────────────────────────────────────

    Three things make the naive pipe wrong, and all three were hit on 2026-09-17:

      1. The export carries no `DROP TABLE`, so a straight import dies on "table already exists"
         for all 65 tables.

      2. phpMyAdmin writes `CREATE TABLE` WITHOUT the primary key and adds it in a later
         `ALTER TABLE … ADD PRIMARY KEY`. Six core-owned foreign keys point at `users.id`, so when
         `users` is recreated InnoDB validates those pending child constraints against a table that
         has no index on `id` yet and refuses the whole CREATE with
         `errno: 150 "Foreign key constraint is incorrectly formed"`. The 64 other tables import,
         `users` does not, and the failure is four lines deep in several thousand of output.
         `SET FOREIGN_KEY_CHECKS = 0` does NOT prevent this: it suppresses row checks, not the
         structural validation of an orphaned constraint's parent.

      3. Dropping those six keys is therefore required, and re-adding them afterwards is the half
         that must not be forgotten — a database whose core tables have quietly lost their keys
         into `users` looks fine until someone deletes a user.

    ── What it does NOT do ──────────────────────────────────────────────────────────────────────

    It does not touch a non-local database, it does not read `backend/.env`, and it does not run
    the transform. It leaves the database in the state switch night starts from: fresh legacy data,
    core tables preserved. `php artisan core:drop-clean` comes next, by hand.

.PARAMETER Export
    Path to the phpMyAdmin .sql export. Quote it — these filenames contain spaces.

.PARAMETER Database
    Local schema to replace into. Defaults to the `DB_DATABASE` in core/.env.

.PARAMETER SkipBackup
    Skip the mysqldump safety copy. Only for a database you are willing to lose.

.EXAMPLE
    pwsh scripts/refresh-legacy-from-export.ps1 -Export "D:\coding\_private-data\u591083448_watchizer 9-17.sql"
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string] $Export,
    [string] $Database,
    [string] $MysqlBin = 'C:\xampp\mysql\bin',
    [switch] $SkipBackup
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$core = Join-Path $root 'core'
$envFile = Join-Path $core '.env'

function Get-EnvValue([string] $key, [string] $default) {
    $line = Get-Content $envFile | Select-String "^$key=" | Select-Object -First 1
    if ($null -eq $line) { return $default }
    return ($line.Line -replace "^$key=", '').Trim().Trim('"')
}

if (-not (Test-Path -LiteralPath $Export)) { throw "export not found: $Export" }

if ([string]::IsNullOrWhiteSpace($Database)) { $Database = Get-EnvValue 'DB_DATABASE' 'u591083448_watchizer' }
$dbHost = Get-EnvValue 'DB_HOST' '127.0.0.1'
$dbPort = Get-EnvValue 'DB_PORT' '3306'
$dbUser = Get-EnvValue 'DB_USERNAME' 'root'

# The password reaches the client through MYSQL_PWD, never the command line: an argument is visible
# in the process list to every user on the machine (AGENTS §3 — no secret inline in a helper script).
$env:MYSQL_PWD = Get-EnvValue 'DB_PASSWORD' ''

$mysql = Join-Path $MysqlBin 'mysql.exe'
$mysqldump = Join-Path $MysqlBin 'mysqldump.exe'
foreach ($bin in @($mysql, $mysqldump)) { if (-not (Test-Path $bin)) { throw "missing client: $bin" } }

$conn = @("--host=$dbHost", "--port=$dbPort", "--user=$dbUser", '--default-character-set=utf8mb4')

function Invoke-Sql([string] $sql) { & $mysql @conn $Database -N -B -e $sql }

<#
 The six core-owned foreign keys into `users`.

 Listed rather than discovered, deliberately: a discovered list would silently shrink if a key had
 already been lost, and this script would then "succeed" at restoring five of six. The re-add is
 the authority on what the schema should look like, and a key missing from the database is a
 finding, not a thing to work around.

 `core_user_preferences` is re-added as `cup_user_fk` — the §2.14 name. Databases built before
 2026-09-17 carry Laravel's generated `core_user_preferences_user_id_foreign`; since this script
 drops and re-adds the key anyway, it is the one moment the rename costs nothing.
#>
$userKeys = @(
    @{ Table = 'catalog_products';      Name = 'catalog_products_created_by_foreign'; Column = 'created_by'; OnDelete = 'SET NULL' }
    @{ Table = 'catalog_products';      Name = 'catalog_products_updated_by_foreign'; Column = 'updated_by'; OnDelete = 'SET NULL' }
    @{ Table = 'core_activity_log';     Name = 'activity_user_fk';                    Column = 'user_id';    OnDelete = 'CASCADE'  }
    @{ Table = 'core_user_preferences'; Name = 'cup_user_fk';                         Column = 'user_id';    OnDelete = 'CASCADE'  }
    @{ Table = 'core_user_roles';       Name = 'cur_user_id_foreign';                 Column = 'user_id';    OnDelete = 'CASCADE'  }
    @{ Table = 'core_user_roles';       Name = 'cur_granted_by_foreign';              Column = 'granted_by'; OnDelete = 'SET NULL' }
)

Write-Host "database : $Database on $dbHost`:$dbPort" -ForegroundColor Cyan
Write-Host "export   : $Export" -ForegroundColor Cyan

# ── 1. the tables the export owns ────────────────────────────────────────────────────────────
$exportTables = Select-String -LiteralPath $Export -Pattern '^CREATE TABLE `([^`]+)`' |
    ForEach-Object { $_.Matches[0].Groups[1].Value }
if ($exportTables.Count -eq 0) { throw "no CREATE TABLE statements in $Export — is it an export?" }
Write-Host "export defines $($exportTables.Count) tables" -ForegroundColor Cyan

$present = Invoke-Sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$Database'"
$preserved = @($present | Where-Object { $exportTables -notcontains $_ })
Write-Host "preserving $($preserved.Count) core-owned tables" -ForegroundColor Cyan

# ── 2. the safety copy ───────────────────────────────────────────────────────────────────────
if (-not $SkipBackup) {
    # Beside the export, which is already outside the repository. A dump of this database carries
    # customer e-mails, phone numbers and password hashes and must never land in the tree.
    $backup = Join-Path (Split-Path -Parent $Export) ("local-before-" + (Get-Date -Format 'yyyy-MM-dd-HHmm') + ".sql")
    Write-Host "backing up to $backup" -ForegroundColor Yellow
    & $mysqldump @conn --single-transaction --routines --events $Database > $backup
    if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed; refusing to import over a database with no backup' }
    Write-Host ("backup {0:N1} MB" -f ((Get-Item $backup).Length / 1MB)) -ForegroundColor Yellow
}

# ── 3. row counts before, so the delta can be reported ───────────────────────────────────────
$before = @{}
foreach ($t in $exportTables) {
    if ($present -contains $t) { $before[$t] = [int](Invoke-Sql "SELECT COUNT(*) FROM ``$t``") } else { $before[$t] = 0 }
}

# ── 4. drop the six keys, drop the 65 tables, import, restore the six keys ───────────────────
foreach ($k in $userKeys) {
    if ($present -contains $k.Table) {
        $held = Invoke-Sql @"
SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = '$Database' AND TABLE_NAME = '$($k.Table)' AND REFERENCED_TABLE_NAME = 'users'
"@
        foreach ($name in @($held)) {
            if ([string]::IsNullOrWhiteSpace($name)) { continue }
            Invoke-Sql "ALTER TABLE ``$($k.Table)`` DROP FOREIGN KEY ``$name``" | Out-Null
            Write-Host "dropped FK $($k.Table).$name" -ForegroundColor DarkGray
        }
    }
}

$staged = Join-Path ([IO.Path]::GetTempPath()) ("legacy-refresh-" + [guid]::NewGuid().ToString('N') + '.sql')
$utf8 = New-Object Text.UTF8Encoding $false
$drops = '`' + ($exportTables -join '`, `') + '`'
[IO.File]::WriteAllText($staged, "SET FOREIGN_KEY_CHECKS = 0;`nSET UNIQUE_CHECKS = 0;`nDROP TABLE IF EXISTS $drops;`n", $utf8)
[IO.File]::AppendAllText($staged, [IO.File]::ReadAllText($Export), $utf8)
[IO.File]::AppendAllText($staged, "`nSET FOREIGN_KEY_CHECKS = 1;`nSET UNIQUE_CHECKS = 1;`n", $utf8)

Write-Host 'importing…' -ForegroundColor Yellow
$log = & $mysql @conn $Database -e "source $($staged -replace '\\', '/')" 2>&1
Remove-Item $staged -Force
$errors = @($log | Where-Object { $_ -match '^ERROR' })
if ($errors.Count -gt 0) {
    $errors | ForEach-Object { Write-Host $_ -ForegroundColor Red }
    throw "import reported $($errors.Count) errors — the database is half-replaced; restore the backup"
}

foreach ($k in $userKeys) {
    Invoke-Sql @"
ALTER TABLE ``$($k.Table)`` ADD CONSTRAINT ``$($k.Name)``
FOREIGN KEY (``$($k.Column)``) REFERENCES ``users`` (``id``) ON DELETE $($k.OnDelete)
"@ | Out-Null
    Write-Host "restored FK $($k.Table).$($k.Name)" -ForegroundColor DarkGray
}

# ── 5. prove it ──────────────────────────────────────────────────────────────────────────────
$after = Invoke-Sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$Database'"
$lost = @($preserved | Where-Object { $after -notcontains $_ })
if ($lost.Count -gt 0) { throw "core-owned tables did not survive the import: $($lost -join ', ')" }
$missing = @($exportTables | Where-Object { $after -notcontains $_ })
if ($missing.Count -gt 0) { throw "tables the export defines are absent after the import: $($missing -join ', ')" }

Write-Host ''
Write-Host ('{0,-34} {1,10} {2,10} {3,10}' -f 'table', 'before', 'after', 'delta')
Write-Host ('-' * 68)
$moved = 0
foreach ($t in $exportTables) {
    $n = [int](Invoke-Sql "SELECT COUNT(*) FROM ``$t``")
    $d = $n - $before[$t]
    if ($d -ne 0) { $moved++ }
    $mark = if ($d -gt 0) { "+$d" } elseif ($d -lt 0) { "$d" } else { '·' }
    Write-Host ('{0,-34} {1,10} {2,10} {3,10}' -f $t, $before[$t], $n, $mark)
}
Write-Host ''
Write-Host "$moved of $($exportTables.Count) tables changed; $($preserved.Count) core-owned tables preserved." -ForegroundColor Green
Write-Host 'Next: php artisan core:drop-clean, then migrate, then transform.' -ForegroundColor Green
