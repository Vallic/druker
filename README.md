# Druker

![Druker](https://www.drupal.org/files/project-images/druker.png)

Cron for a Drupal site, managed in the admin UI and run by a small Go binary
that sits beside the site rather than inside it.


## Introduction

The problem it solves is the one every hosted Drupal site has: `crontab` lives
somewhere a developer has to deploy to, the site has no idea what is in it, and
nobody can answer "what runs on this box, and did it run" without SSH. Druker
moves the schedule into Drupal, where it can be edited, reviewed and audited,
and leaves the running to a process that does nothing else.

It is not a replacement for `hook_cron`. Drupal's cron is one job among the
many this schedules, and `drush cron` is a perfectly good thing to put in it.


## Requirements

- Drupal 11
- Drush, on any server running the worker
- Go 1.23 or later, to build the worker. Only on the machine that builds it:
  the result is a static binary with no runtime dependencies


## Installation

Install as you would any Drupal module. See
[Installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules).

Then build and start the worker on each server, as described under
[Running the worker](#running-the-worker).


## Configuration

Everything lives under Configuration, in the Druker section, across three
tabs: **Dashboard**, **Cron Jobs** and **Servers**.

1. Add a **Server** for each machine that will run a worker, and give it the
   hostname that machine reports. A worker finds its own schedule by matching
   its hostname against these.
2. Add **Cron Jobs**. A job is a Drush command plus when to run it. Leave the
   server empty to run a job on every server.
3. Grant the *Administer Druker jobs and servers* permission to whoever should
   be editing the schedule. It is a restricted permission: a job is a command
   line that runs as the web user.

### The Dashboard

It answers the question the two lists cannot: what is each machine actually
going to do. One card per server showing the jobs its worker will receive —
its own, plus the ones that run everywhere — and a grid of the day, hour by
hour, which is where you notice that everything you own runs at three in the
morning.

It leads with what will *not* run, because that is the part nobody finds on
their own: a schedule the worker cannot read, a shell job on a site that has
not enabled them, a job assigned to a server that is disabled or gone. Those
are left out of the day as well, so the picture and the warnings never
disagree.

A disabled server still shows a card, because its worker does not stop — it
stops being *matched*, and falls through to the jobs that belong to no server.
The card says so, and says how many of its own jobs are now picked up by
nobody.

Check the same things from a terminal with `drush druker:check`, which tests
every job the way the worker will and exits non-zero if any would be dropped.


## The two halves

**Drupal** owns the schedule. Two entity types: a **Server**, matched to a
machine by hostname, and a **Cron Job**, which is a Drush command plus when to
run it. A job with no server runs on every server.

**The worker** (`worker/`) is a single static binary. It asks Drupal what this
machine should be running, keeps those jobs on their schedules, and asks again
every refresh period — so a change made in the UI takes effect without a deploy
and without a restart.

They meet at one command:

```
drush druker:jobs <hostname>
```

which prints the schedule as JSON. That payload is the whole contract between
the two halves. It is produced by `JobManager::formatJob()`, consumed by the
`Job` struct in `worker/schedule.go`, and `examples/sample-output.json` is a
copy of it that the worker's tests parse on every run. **If you change one
side, change the other, and update the sample** — the two drifting apart is
silent, and it is the failure this module has already had once.

## Running the worker

Build it into the project root — the directory holding `vendor/` and
`composer.json`:

```bash
cd worker
go build -o ../../../../druker .   # or wherever your project root is
cd /path/to/project
./druker
```

That is the one place it needs no arguments. Started from there it finds
`vendor/bin/drush` immediately, and everything below follows from it.

**Where jobs run.** Not where you started the worker — in the project root,
the directory above `vendor/`, worked out from the drush path. That is where
you stand when you run drush by hand, so a relative path in a command means
the same thing however the worker was launched. Override it with `-dir` if
your jobs expect somewhere else.

**Finding drush.** With no `-drush` it walks up from the working directory and
from the binary's own location looking for `vendor/bin/drush`. That covers
running it by hand; it does not cover systemd, which starts services in `/`.
Pass the path explicitly there — the worker says so plainly rather than
guessing:

```
level=ERROR msg="cannot find drush" error="no vendor/bin/drush above [/ /tmp]; pass -drush"
```

| Flag | What it does |
|---|---|
| `-dry-run` | Print what would run and exit. Exits non-zero if any job was unreadable |
| `-drush` | Path to drush. Required under systemd, optional otherwise |
| `-dir` | Directory jobs run in. The project root above `vendor/` when empty |
| `-host` | Fetch another server's schedule, for checking what it would do |
| `-state` | Where completed one-time jobs are remembered |
| `-job-timeout` | Abandon a job that runs longer than this |
| `-verbose` | Log every minute it evaluates, not only what it does |

Logs are `log/slog` text on stdout, one line per event, which is what a
container platform wants. Send `SIGTERM` and it stops scheduling and gives
running jobs 30 seconds to finish.

### Under systemd

```ini
# /etc/systemd/system/druker.service
[Unit]
Description=Druker worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data

# Both given explicitly. systemd starts services in /, so nothing is found
# by walking up, and a job's relative paths would otherwise resolve there.
WorkingDirectory=/var/www/example
ExecStart=/var/www/example/druker \
    -drush /var/www/example/vendor/bin/drush \
    -dir /var/www/example \
    -state /var/lib/druker/state.json

Restart=always
RestartSec=10

# One-time completions live here, and must outlive a deploy.
StateDirectory=druker

# stdout is already one structured line per event.
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now druker
journalctl -u druker -f
```

Run it as the same user as the site. A job runs as whoever the worker is, and
files it creates are owned by them.

## Scheduling

**Recurring jobs** take standard five-field cron, an `@`-shortcut (`@daily`),
or plain English (`every 5 minutes`). Whatever you type is resolved to cron by
`JobManager::resolveCronExpression()` before it reaches the worker.

Both halves validate the result, with the same rules, on purpose: the form
refuses an expression the worker could not read, because a job the worker drops
is one that never runs and never says why. `drush druker:check` runs the same
test over everything at once.

**One-time jobs** take a date instead, and are remembered once run so a restart
does not run them again. That memory is a small JSON file — if the worker runs
in a container, point `-state` at a volume, or a one-time job will run again
after every deploy. Completion is recorded when the job *finishes*, so a worker
killed mid-job will re-run it: at least once, rather than at most once.

**Dependencies.** A job can be set to run after another. It waits while that
job is running and starts when it finishes. A dependency chain that loops back
on itself is broken at one link, with a warning, rather than being left to
deadlock both jobs.

**Asynchronous** jobs start alongside anything else due in the same minute. A
job that is not asynchronous holds the rest of that minute behind it.

**Drush or a shell.** Every job picks a runner. Drush is the default, and it is
what most jobs want — including PHP, since `php:script path/to/file.php` is a
Drush command like any other. The shell runner hands the whole command line to
`sh -c`, so pipes, redirection and any binary on the machine work:

```
tar -czf /backups/site-$(date +%F).tgz web/sites/default/files
```

Shell jobs are **off unless the site opts in**, in `settings.php`:

```php
$settings['druker_allow_shell'] = TRUE;
```

Deliberately a setting rather than a permission. Without it, *Administer Druker
jobs and servers* means "run any Drush command as the web user" — serious, but
bounded. With shell jobs it means "run anything on this machine", and whoever
granted the permission under the first meaning never agreed to the second. A
setting puts that decision with the person who has filesystem access.

Until then the option is visible but disabled, with the line to add, and a
shell job that exists anyway is simply left out of the payload — so no worker
ever sees it. `drush druker:check` says which jobs are being withheld and why,
because a job that silently does not run is the worst way to find out.

**No job ever overlaps itself.** A job still running when its next turn comes
round is skipped, with a warning. Two copies of a queue processor is how a
queue gets worked twice and a mail gets sent twice.

## Adding jobs from code

Not every job is worth storing. Subscribe to `CollectJobsEvent` to add jobs
that are computed:

```php
public function onCollectJobs(CollectJobsEvent $event): void {
  $event->addJob([
    'id' => 900,
    'name' => 'Rebuild the index',
    'command' => 'search-api:index',
    'runner' => 'drush',
    'type' => 'cron',
    'cron' => '0 4 * * *',
    'async' => TRUE,
    'depends_on' => NULL,
  ]);
}
```

The shape is the same one `formatJob()` produces. Anything the worker cannot
read it drops, with a line in its log saying which job and why.

## Commands

| Command | What it is for |
|---|---|
| `drush druker:jobs [host]` | The payload the worker fetches. `--pretty` to read it |
| `drush druker:check [host]` | Find jobs the worker would silently drop. Exits non-zero if any |

## Working on the worker

```bash
cd worker
go test ./...            # includes parsing examples/sample-output.json
go test -race ./...      # the runner is concurrent; run this before believing it
gofmt -l .
```

No dependencies beyond the standard library, deliberately: the binary ships to
machines that may have no route to a module proxy, and the cron parser it needs
is smaller than the trust a dependency tree would ask for.


## Related projects

**[Klaxon](https://www.drupal.org/project/klaxon)** is the other half of this.
Druker runs your jobs; Klaxon is what tells you when one of them stopped —
"cron is falling behind", "queue backlog", or any threshold you care to set —
and says so in Slack, Telegram, Discord, email or a webhook.

It works in the other direction too. Klaxon's own scheduled alerts normally
ride on Drupal cron, so a wedged cron run silences them. Scheduling
`klaxon:due` and `klaxon:deliver` as Druker jobs puts the alerting on a process
that is not the one it is watching.


## Maintainers

- [Valentino Međimorec](https://www.drupal.org/u/valic)
