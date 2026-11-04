# Integration run

Plays the flow simulation (`dev/sim/sim.mjs`, see `dev/sim/README.md`) through
on a freshly installed throwaway Nextcloud with Pulse from this checkout, on
one of the four databases Nextcloud supports, or on all four at once:

```sh
dev/it/run.sh            # SQLite
DB=pgsql dev/it/run.sh   # PostgreSQL (also: mariadb, mysql)
DB=all dev/it/run.sh     # the four in parallel, summed result
KEEP=1 dev/it/run.sh     # keep containers and outputs for a look afterwards
```

It never touches a running instance: every run starts its own containers and
removes exactly these at the end (also on Ctrl-C):

| Container | Image |
|---|---|
| `pulse-it-<pid>` | Nextcloud (`IMAGE`, default `nextcloud:34-apache`), no published port |
| `pulse-it-<pid>-db` | `postgres:17-alpine`, `mariadb:11.8` or `mysql:8.4` (not with SQLite) |
| `pulse-it-<pid>-redis` | `redis:7-alpine`, cache and file locking as on a usual installation |

They talk through `--link` on Docker's default bridge (no network of their
own); the simulation reaches Nextcloud on its container address with
`Host: localhost`. Passwords are random per run and never written to a file.
With `DB=all` each database runs as a child of the script with the prefix
`pulse-it-<pid>-<db>-`.

Steps per database: start the database and Redis, install Nextcloud (cron
background jobs, no app store, language English), copy the app as the release
archive would ship it (without `src/`, `dev/`, `tests/`, source maps) and
enable it, add the user `alice`, run `sim.mjs` as `alice`, and count the
warnings and errors the simulation's requests (user agent `pulse-sim`) wrote to
the Nextcloud log — reported on stderr, not counted as a failure. Without built
bundles in `js/` the script runs `npm run build` first.

## Output

On stdout, one line per failed simulation check, then the totals:

```
FAIL <db>: <check label>
Integration <db> <ok>/<failed>
Integration <ok>/<failed>          (DB=all only: the sum)
```

A run that ended without the simulation's totals (it crashed, or Nextcloud
did not come up) counts as `Integration <db> 0/1` with a `FAIL` line naming the
last output line. Exit code 0 without failures, 1 with at least one, 2 when the
run could not be set up (unknown `DB`, build failed, Nextcloud or the database
did not come up, the app could not be enabled). Progress goes to stderr; the
outputs of a failed run stay in the work directory it names.

A database takes about seven minutes (one to two for the installation, about
five for the simulation); `DB=all` takes about as long as the slowest one.
It is the check `integration` of the factory (`factory.json`).

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `DB` | `sqlite` | `sqlite`, `pgsql`, `mariadb`, `mysql` or `all` |
| `IMAGE` | `nextcloud:34-apache` | Nextcloud image |
| `APP_SRC` | this checkout | source of the app |
| `KEEP` | `0` | `1` keeps containers and the work directory |
| `PULSE_IT_PREFIX` | `pulse-it-` | container prefix (the process id is appended) |
| `PULSE_IT_SIM` | `dev/sim/sim.mjs` | simulation script; only to test the runner itself |
