# The dev database moves to MariaDB 11.8.9 on 127.0.0.1:3307 — the record and the steps

**Decided 2026-10-17 (Michael).** The deciding reason is measurement validity, not stability: the local
engine was MariaDB 10.4.32 and production runs 11.8.9. Query COUNTS carry across versions; plans and wall
times do not. A facet-count budget measured on 10.4 is a budget for an engine we do not run — measuring on
10.4 would mean measuring twice. After this move every measurement in the project is taken on production's
engine. Stability is the second reason: XAMPP's instance carries the Aria fault on its own `mysql.*` tables
(MYSQLD-HYPOTHESES), and once this project stops using it, that fault stops being ours.

**Scope.** This project only, on a second server. XAMPP's 10.4 keeps 3306 for the other nine databases
(dumped to `C:\temp\dbbackup` on 2026-10-14). Moving those is a separate item (DEV-DB-OTHER-NINE), not done
here. Trade-off stated plainly: two database servers run on this machine, and XAMPP's keeps its Aria fault.

**Rollback.** XAMPP's 10.4 is untouched on 3306 with its data intact. Reverting is changing `DB_PORT` back to
3306 in `core/.env`. Nothing in this move destroys the old path — deliberately.

---

## 1. The dump — the thing the migration is reversible against

Taken BEFORE anything else, from the live dev database on 10.4.32 (XAMPP's `mysqldump` 10.19), application
database only (`u591083448_watchizer`; no `mysql.*` — the system tables belong to the engine and 11.8.9 builds
its own; importing 10.4's grant tables into 11.8.9 is how a server becomes unreachable):

| | |
|---|---|
| file | `D:\dumps\u591083448_watchizer-20261017-premove.sql` |
| size | **29,929,123 bytes** |
| sha256 | `92a27bc3e26b6b3f3fcf1a866db2cff5b29bbbb01c8a18685b2b1ee9007ea57d` |
| last statement | `-- Dump completed on 2026-10-17  2:32:55` |
| options | `--single-transaction --quick --routines --triggers --events --default-character-set=utf8mb4 --hex-blob` |
| holds | 139 tables; the three local adjustments (paymob 8963 credentials NULL, the mailbox row re-encrypted under the LOCAL key with the dummy, the three `bf_bak_20261013_*` tables dropped) and the 205 `DEMO-` variant rows on 33 products — the only copy of those |

`eleganceeg-20261016.sql` (the raw production export, sha256 `7e35897c…`) is NOT sufficient for this: it
predates all of the above.

**APP_KEY was not touched** and must not be: the mailbox row's ciphertext depends on it. Fingerprint of the
`APP_KEY=` line in `core/.env` before the move (sha256 of the line, first 16 hex): `2f746273167bf856` — the
same fingerprint must be read after.

Beside the dump: `D:\dumps\u591083448_watchizer-20261017-premove.content-checksum.txt` — the gate's
engine-independent content checksum of every table (COUNT and CRC32 sum over stored columns), and
`D:\dumps\verify-figures.sql` — the figures below as one statement.

## 2. The figures before the move (10.4.32, 2026-10-17 02:3x)

| tables | products | translations | links (`storefront_product`) | visible sf1 / sf2 | mailboxes | `settings.identity` set | DEMO rows / products | variants | movements | paymob 8963 credentials NULL | `bf_bak*` |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **139** | 8,041 | 16,082 | 9,067 | 1,014 / 1,014 | 1 | 2 | 205 / 33 | 315 | 6,536 | yes | 0 |

(The brief's "142 tables" is the raw import's count; the three `bf_bak` tables were dropped on 10-15 — 139 is
the live figure.) Pending migrations before the move: 0.

## 3. The install method — the ZIP, and why

**ZIP build (`mariadb-11.8.9-winx64.zip`), registered with `mariadb-install-db.exe --service`.** No MSI, no
registry writes beyond the service entry, no uninstaller; undoing it is `net stop` + `sc delete` + deleting one
folder. The MSI would also offer to take port 3306 and to register itself as the machine's MariaDB, which is
exactly what must not happen beside XAMPP.

| | |
|---|---|
| downloaded to | `C:\temp\mariadb-11.8.9\mariadb-11.8.9-winx64.zip` |
| size | 97,016,251 bytes |
| sha256, local | `830c46727d9278eae212ae3eca44eeb9e71b2a68704e95f344a64fba7b1963f5` |
| sha256, published | the same, from `https://archive.mariadb.org/mariadb-11.8.9/winx64-packages/sha256sums.txt` (`./mariadb-11.8.9-winx64.zip`) — **match** |
| contents | one root folder `mariadb-11.8.9-winx64/` with `bin/mariadbd.exe`, `bin/mariadb-install-db.exe`, `bin/mariadb.exe`, `bin/mariadb-dump.exe` |

Nothing was unpacked, installed or registered by the agent. From here it is Michael's machine change.

## 3a. Production's settings, measured (Michael, 2026-10-17, supplied and dated) — and what they change

| setting | production 11.8.9 | local 10.4.32 today | the new server |
|---|---|---|---|
| `character_set_server` / `collation_server` | utf8mb4 / **utf8mb4_unicode_ci** (every one of its 142 tables too) | utf8mb4 / utf8mb4_general_ci (every table carries unicode_ci itself) | **utf8mb4 / utf8mb4_unicode_ci** — the choice above, confirmed by the measurement, not by 11.x's newer default |
| `sql_mode` (global) | **`NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`** — NON-strict | `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` | stage 1: the local value; **stage 2: production's two flags** |
| `time_zone` | `SYSTEM`, which there resolves to **UTC** (the build manifest read 11:09 against 14:09 on this workstation) | `SYSTEM` = Africa/Cairo, +03:00 now | stage 1: SYSTEM (Cairo); **stage 3: `default-time-zone='+00:00'`** — production's effective value; the literal `SYSTEM` cannot be matched on a Cairo machine |
| `lower_case_table_names` | **0** | 1 | **1 — cannot be matched on Windows.** This move buys ENGINE parity, not full parity; this one divergence survives it. |
| tables | 142 | 139 | the difference is the three `bf_bak_20261013_*` tables still on production — a deliberate backup or debris, Michael's question (PROD-BF-BAK-TABLES), not tonight's work |

So "leave it at the engine's default" was wrong for two settings: 11.8.9's own default `sql_mode` IS strict and
production's is not, and SYSTEM means two different clocks on the two machines. Both are set explicitly, in stages.

**What governs the suite — measured through the app, not assumed.** `config/database.php` has `strict => true`
and no `modes` / `timezone` keys for the `mariadb` connection, so on every connect Laravel issues its own
session `sql_mode` — read back through the app:
`ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`
— while the global stays the server's. The session `time_zone` is NOT set by Laravel; it inherits the
server's (`SYSTEM`, +03:00 today). Therefore:

- **Stage 2 cannot move the suite, by construction.** The application and the suite never run under the
  server's global `sql_mode`; they are strict on 10.4, on 11.8.9 and on production alike. Stage 2 governs
  the gate's own `mariadb.exe` client (reads only), imports, phpMyAdmin and any raw SQL — the places where
  production is non-strict and a bad write passes with a warning. It is still set, for parity of those
  paths, and a flat result at stage 2 is the expected result, known in advance.
- **Stage 3 can move things.** 184 of the schema's columns are `TIMESTAMP` (converted on read through the
  session time zone; 10 are `DATETIME`), and `NOW()` in raw SQL shifts by three hours. The pre-move dump is
  `--tz-utc` (`SET TIME_ZONE='+00:00'` in its header), so the import stores every TIMESTAMP correctly under
  either server clock; what changes at stage 3 is what a session READS and what `NOW()` says — on production's
  clock, as production reads it.

## 4. Michael's steps — exact, in an ELEVATED PowerShell (stage 1 settings)

```powershell
# 1. unpack, and give it a short home: binaries AND data under one folder, so removal is one delete
Expand-Archive -Path 'C:\temp\mariadb-11.8.9\mariadb-11.8.9-winx64.zip' -DestinationPath 'C:\temp\mariadb-11.8.9\unpacked'
Move-Item 'C:\temp\mariadb-11.8.9\unpacked\mariadb-11.8.9-winx64' 'C:\mariadb-11.8.9'

# 2. create the data directory and register the service — STOPPED, so my.ini can be completed first.
#    No --password: root with an empty password, exactly as the app's .env expects and as XAMPP ran;
#    the server is bound to 127.0.0.1 below, so it is reachable from this machine only.
& 'C:\mariadb-11.8.9\bin\mariadb-install-db.exe' --datadir='C:\mariadb-11.8.9\data' --service=MariaDB1189 --port=3307
```

The tool writes `C:\mariadb-11.8.9\data\my.ini` with `[mysqld]`, `datadir`, `port` (and `tmpdir`). **Keep
those lines and add the block below to the same `[mysqld]` section before the first start.** Three of these
must be right at creation and are painful afterwards: `port` (the whole point), `lower_case_table_names`
(fixed at datadir creation; 1 is the Windows default and what XAMPP and the dump use), and the server
character set / collation (new objects without an explicit collation take them; every table in the dump
carries its own, `utf8mb4_unicode_ci`).

```ini
# C:\mariadb-11.8.9\data\my.ini — [mysqld] additions (dev database, this project only; 2026-10-17)
bind-address=127.0.0.1
skip-name-resolve
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
max_allowed_packet=64M
innodb_buffer_pool_size=512M
innodb_flush_log_at_trx_commit=1
log_error=C:/mariadb-11.8.9/data/mariadb-error.log
log_warnings=2
# STAGE 1 — the engine only: the local 10.4 instance's own sql_mode and clock, so that whatever the first
# gate run moves is attributable to the engine version and nothing else (§3a).
sql_mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION
# time_zone: not set = SYSTEM = Africa/Cairo, as the local 10.4 runs today
# NOT set on purpose: innodb_ft_min_token_size — the default 3 is what the search code relies on
```

Stages 2 and 3 are one-line edits to this same file, each followed by `net stop MariaDB1189` / `net start
MariaDB1189` and a gate run (the agent's part; each restart also exercises the three stop signatures):

```ini
# STAGE 2 — production's sql_mode, exactly its two flags (replaces the stage-1 line)
sql_mode=NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION
# STAGE 3 — production's effective clock (added)
default-time-zone='+00:00'
```

```powershell
# 3. start it, and prove the five things that must be right
net start MariaDB1189
& 'C:\mariadb-11.8.9\bin\mariadb.exe' -u root --protocol=tcp -h 127.0.0.1 -P 3307 -e "SELECT VERSION() v, @@port p, @@datadir d, @@character_set_server cs, @@collation_server co, @@lower_case_table_names lc, @@sql_mode sm"
# expected: 11.8.9-MariaDB · 3307 · C:\mariadb-11.8.9\data\ · utf8mb4 · utf8mb4_unicode_ci · 1 · NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION (stage 1)
Get-Service MariaDB1189 | Format-List Name, Status, StartType
```

XAMPP's 10.4 on 3306 is not touched by any of this.

**Optional, Michael's:** a Defender exclusion for `C:\mariadb-11.8.9\data`. The 10-16 exclusion on XAMPP's
data directory was a half-success (in-run Errcode 22 went 14 → 0; the shutdown EBADF survived), so it is not
assumed to be needed; the new server's log is what decides.

**Reversal of the install:** `net stop MariaDB1189; sc.exe delete MariaDB1189; Remove-Item -Recurse
'C:\mariadb-11.8.9'`.

## 5. After it runs — the agent's part (recorded in RUN_20261017.md as it happens)

1. `CREATE DATABASE u591083448_watchizer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` on 3307.
2. Import `D:\dumps\u591083448_watchizer-20261017-premove.sql` with the 11.8.9 client; record wall time.
3. `verify-figures.sql` on 3307 against §2, and the content checksum of every table against the pre-move
   file — any difference is a finding, not a rounding.
4. `core/.env`: `DB_PORT=3307` (the one line that moves the app; the rollback line). APP_KEY fingerprint
   re-read, must be `2f746273167bf856`.
5. `php artisan migrate --force` — nothing expected to migrate; `migrate:status` pending count 0.
6. The gate (`bash tests/baseline/suite-baseline.sh`, demo state ON), **three staged runs so every move is
   attributable** (§3a): stage 1 engine only (local sql_mode, local clock) → stage 2 production's `sql_mode`
   (expected flat for the suite: Laravel's sessions are strict regardless) → stage 3 production's clock
   (`+00:00`). Each stage reports the setting changed, the number, the wall time, line 4, and which baseline
   entries moved AT THAT STAGE. **The 59 is expected to move; the stage-3 result on 11.8.9 is the TRUE
   baseline** — `suite-baseline.txt` is rewritten with the engine version, the `sql_mode` and the `time_zone`
   in its header beside the import's sha256 (none of the three was there before). Every query budget
   (`QueryBudgetTest`, `CardColoursTest`, the PDP's 14) that changes is reported old → new, none widened
   quietly.
7. The watch on a service: see §6 once the server exists.

## 6. The watch, from the first start on (reference: 2026-10-17 02:57:59)

- **Log:** `C:\mariadb-11.8.9\data\mariadb-error.log` (`log_error`), and the Windows Application log under
  provider `MariaDB` — **shared with XAMPP's 10.4**, both binaries are `mysqld.exe` and messages carry no pid,
  so the per-server file is the record for the 11.8.9 instance; the Application log stays the record for
  XAMPP's, whose own file has missed lines before.
- **Process:** `mysqld.exe` (the 11.8.9 ZIP keeps that name), pid 20812 at the time of writing, the service
  `MariaDB1189` (Running, Automatic); the gate's line 0 finds it by the port it listens on, never by name.
- **The reference start, read from the file:** `Starting MariaDB 11.8.9-MariaDB … as process 2`, no
  `InnoDB: Starting crash recovery from checkpoint LSN=`, no `Aria engine: starting recovery`,
  `InnoDB: Buffer pool(s) load completed at 261017 2:57:59`, `Server socket created on IP: '127.0.0.1', port:
  '3307'`, `ready for connections`. Two warnings, expected and harmless: install-db's host-named grant
  `root@michael` and its `proxies_priv` entry are ignored under `skip-name-resolve` (the application connects as
  `root@127.0.0.1`, proved through Laravel). Also in that start: `option --ssl-verify-server-cert is
  disabled, because of an insecure passwordless login` — expected, root has no password as `.env` requires and
  the server is loopback-only.
- **The four signatures.** The three of the 10.4 criterion keep their meaning on a service (a service stop is a
  normal shutdown, not a kill): on the next start, (1) no InnoDB crash-recovery line, (2) `Buffer pool(s) load
  completed`, (3) no `Aria engine: starting recovery`. The fourth is what this server gives that XAMPP's never
  did: (4) **its own `Shutdown complete` line in `mariadb-error.log`**, so a STOP is read, not inferred from the
  next start. The first real stop/start of this service (Michael's, when the stage-2/3 settings are persisted
  in my.ini) is the first test of all four.
- **The first stop/start, read (Michael's, 03:42:28, persisting stages 2 and 3 in my.ini).** All four
  signatures, in the file: `Windows service "MariaDB1189": received SERVICE_CONTROL_STOP` → `Normal shutdown` →
  `Dumping buffer pool(s)` → `Shutdown completed; log sequence number 216097636` → **(4) `Shutdown complete`**;
  then `Starting … as process 1404`, **(1)** no crash-recovery line (`End of log at LSN=216097636` — the same
  number the stop wrote, so nothing was replayed), **(2)** `Buffer pool(s) load completed at 261017 3:42:29`,
  **(3)** no `Aria engine: starting recovery`; `ready for connections`. The Application log carries the same
  sequence under provider MariaDB, no Error or Warning beyond the two expected grant lines. `CHECK TABLE
  mysql.db` on this instance: OK before and after the V2-READ-HALF test runs (04:0x–04:3x). **The experiment's
  first reading is clean** (no Defender exclusion on the datadir; one service restart; one full suite run under
  pid 1404 — see RUN_20261017.md).
- **The `%%%%%%` junk bytes — attribution CORRECTED.** They were put down to XAMPP's batch scripts. They
  appeared in THIS server's log too, at the seam between pid 20812's last line (`Shutdown complete`) and pid
  1404's first (`Starting MariaDB … as process 1404`), on a service restart with no batch file anywhere near
  it. So the attribution was wrong; what is known: six bytes at exactly the point where one process closes
  the file and the next opens it for append, on both servers, both installs, harmless to every line around
  them. Recorded as **unexplained**, not as a symptom; it is not one of the four signatures and does not move
  the experiment either way.
- **Line 5 of the gate** (`CHECK TABLE mysql.db`) now watches the new instance's own Aria system table, which
  started clean; the growing-datafile observation belonged to XAMPP's copy.
- **The experiment (decision 4 in RUN_20261017.md row 7):** `C:\mariadb-11.8.9\data` has NO Defender
  exclusion, on purpose. Clean here answers H3 (the engine); the Aria symptoms following it here answers H1
  (something outside mysqld). Nobody adds that exclusion as a tidy-up.

**Stages 2 and 3 were applied at runtime** and **persisted by Michael in my.ini at 03:42 (service restarted once,
the four signatures read above; `@@GLOBAL.sql_mode` and `time_zone` re-read after the restart by the gate's line 0).**
The paragraph below is kept as the record of how it was done. **Michael persists them:** add the
two stage lines of §4 to `C:\mariadb-11.8.9\data\my.ini` (replacing the stage-1 `sql_mode` line), then
`net stop MariaDB1189` / `net start MariaDB1189`, and read the four signatures. Until then a restart would
silently return the server to stage 1's values.

## 7. Hours (the real figure, for the record)

**1 h 08 min wall clock, 02:32 → 03:40, against the 2–3 h guess.** Agent 02:32 → 02:50 (dump, figures, checksum, download and
verification, this document; 18 min) · Michael's install 02:50 → 02:57:59 (8 min from handover to first start) · import,
verification, switch, migrate 02:58 → 03:04 (6 min) · three staged gate runs 03:02 → 03:38 (36 min; the suite itself 649 +
499 + 510 s, six 34-second checksums). Result of the stages: no baseline entry moved at any stage; the warm 11.8.9 run sits
inside 10.4's 507–576 s band; no query budget moved.
