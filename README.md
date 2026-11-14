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

Jobs can be switched off without being deleted, from the `Disable` operation
on the job list. A disabled job keeps its command, schedule and server
assignment, and no worker is told about it until it is enabled again. For
differences between environments, see [Several environments](#several-environments).

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
It also warns about the two that are nobody's fault and easy to miss: a shell
job on a site that has not opted in, and a job left naming only servers that
are missing or disabled, which no worker anywhere is running.


## The two halves

**Drupal** owns the schedule. Two entity types: a **Server**, matched to a
machine by hostname, and a **Cron Job**, which is a Drush command plus when to
run it. A job with no server runs on every server.

**The worker** (`worker/`) is a single static binary. It asks Drupal what this
machine should be running, keeps those jobs on their schedules, and asks again
every refresh period — so a change made in the UI takes effect without a deploy
and without a restart.

The refresh is set per server and defaults to **30 minutes**. A schedule is
edited by a person, so it changes on the timescale people work at; half an
hour is soon enough for an edit to land and rare enough that the asking costs
nothing. **One minute is the floor**, enforced on both sides — the site clamps
what it sends and the worker clamps what it is told, so neither a subscriber
asking for two seconds nor a hand-edited config YAML can put a server into a
loop that spends more time asking what to do than doing it. A payload with no
refresh at all falls back to the 30-minute default rather than to zero.

A refresh never touches a running job. It ends the worker's pass over the old
schedule, not the jobs that pass started: a job that began at 22:57 with a
refresh at 22:59 runs until it is done, and the new schedule will not start a
second copy of it alongside unless the job allows overlap (see below).

They meet at one command:

```
drush druker:jobs <hostname>
```

which prints the schedule as JSON: the server, its refresh, the jobs, and the
directory where the site asks for a job to run now (`wake_dir`, see
[Waking a job](#waking-a-job)). That payload is the whole contract between
the two halves. It is produced by `JobManager::formatJob()`, consumed by the
`Job` struct in `worker/schedule.go`, and `examples/sample-output.json` is a
copy of it that the worker's tests parse on every run. **If you change one
side, change the other, and update the sample** — the two drifting apart is
silent, and it is the failure this module has already had once.

## Running the worker

Build it into the project root — the directory holding `vendor/` and
`composer.json`:

```bash
cd web/modules/contrib/druker/worker
go build -o /path/to/project/druker .
cd /path/to/project
./druker
```

Give `-o` an absolute path. A relative one is counted from `worker/`, and how
far up the project root sits depends on whether the site has a `web/` docroot,
so the same `../../../..` lands somewhere different on two installs.

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
| `-job-timeout` | Abandon a job that runs longer than this. A job's own `timeout` is used instead where it is shorter |
| `-shutdown-grace` | On `SIGTERM`, how long running jobs get to finish. Default `30s`; `0` waits however long they take |
| `-verbose` | Log every minute it evaluates, not only what it does |
| `-log-format` | `text` (default) or `json` |

Logs go to stdout, one line per event, which is what a container platform
wants. The default is `log/slog` text — logfmt, for someone reading
`journalctl`:

```
time=2026-09-14T10:45:14.214+02:00 level=INFO msg="schedule loaded" server=web13 jobs=7 next_check_in=5m0s
```

`-log-format=json` emits the same events and the same keys as one JSON object
per line, for a shipper that would otherwise have to parse them back out:

```json
{"time":"2026-09-14T10:45:14.214+02:00","level":"INFO","msg":"schedule loaded","server":"web13","jobs":7,"next_check_in":"5m0s"}
```

The keys do not change between the two, so a query written against one format
still finds things in the other. Every line carries `server`, and job lines
carry `job`, so one collector can hold several servers and still separate
them.

**Stopping.** On `SIGTERM` or `SIGINT` the worker stops starting jobs and gives
the running ones `-shutdown-grace` (30 seconds by default) to finish. Whatever
is still running after that gets `SIGTERM`, and `SIGKILL` ten seconds later,
children included. The jobs never get the worker's own signal: each runs in
its own process group, so a Ctrl-C in a terminal or a signal to the worker
reaches only the worker. A one-time job stopped this way is not recorded as
done and runs again when the worker comes back.

`-shutdown-grace 0` makes a restart wait for every running job, however long
it takes. Whatever supervises the worker has to wait as long: systemd's
`TimeoutStopSec`, Docker's `--stop-timeout` / `stop_grace_period`,
Kubernetes' `terminationGracePeriodSeconds`. When that runs out first, it kills
the worker and its jobs together, without the `SIGTERM` step.

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

# Stop signals the worker only; it stops its jobs itself, after the grace.
# The default, control-group, SIGTERMs every job the moment a restart begins.
KillMode=mixed
# Longer than -shutdown-grace plus the ten seconds between SIGTERM and
# SIGKILL, or systemd kills the jobs before the worker does. "infinity" with
# -shutdown-grace 0.
TimeoutStopSec=60

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

A job is one of three kinds, and the form asks which before it asks anything
else — **Recurring**, **Interval** or **One-time**. Only the fields belonging
to the kind you pick are shown, and only those are saved: the other two are
cleared on the way in, so switching a job from one kind to another leaves
nothing of the old schedule behind it. The same three names are what the
payload calls them, so a job reads the same way in the UI, in
`drush druker:jobs` and in the worker's log.

**Recurring jobs** take standard five-field cron, an `@`-shortcut (`@daily`),
or plain English (`every 5 minutes`). Whatever you type is resolved to cron by
`JobManager::resolveCronExpression()` before it reaches the worker.

Both halves validate the result, with the same rules, on purpose: the form
refuses an expression the worker could not read, because a job the worker drops
is one that never runs and never says why. `drush druker:check` runs the same
test over everything at once.

**Interval jobs** repeat every *n* seconds, and exist for the periods cron
cannot say: every 25 seconds, every 75, every 90. Queue processors are what
they are mostly for — `* * * * *` is the finest cron can manage, and a queue
that matters is usually worth draining more often than once a minute. Pick
**Interval** on the job form, or add one from `CollectJobsEvent` when the
period itself has to be computed.

Boundaries are anchored to the epoch rather than to when the worker started,
so a job set to every 30 seconds runs on :00 and :30 of every minute on every
server, whatever time each of them booted, and a restart does not move it. An
`offset` shifts those boundaries, which is how the same queue processor on
three servers is kept out of the same second. A tick missed under load delays
a run rather than losing it. The floor is **5 seconds**: the tick is one, but
every run is a process and a Drupal bootstrap, and a job that outlives its own
period is skipped rather than queued.

**One-time jobs** take a date instead, and are remembered once run so a restart
does not run them again. That memory is a small JSON file — if the worker runs
in a container, point `-state` at a volume, or a one-time job will run again
after every deploy. Completion is recorded when the job *finishes*, so a worker
killed mid-job, or one that stops it at shutdown, will re-run it: at least
once, rather than at most once.

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

**By default no job overlaps itself.** A job still running when its next turn
comes round is skipped for that turn, with a warning in the log. That holds
across a refresh too: the turn may come from the schedule fetched after the
job started, and it is still skipped.

The failure this prevents is rarely the one people expect. It is a job that
takes seven minutes on a five-minute schedule, quietly accumulating another
copy every five minutes until the machine runs out of memory or database
connections — silent until it is not.

After that it is the jobs that are *not* queue processors. A command that
emails everyone who abandoned a cart in the last hour has no idea another copy
of itself is making the same selection, and the customer gets the mail twice.
The same goes for an import, a report, or anything that tags the rows it finds.

Queue processors are the safest case rather than the worst. Advanced Queue and
core's queue both lease the items they hand out, so two processors normally
take different work — which is why running one queue on several servers is
throughput and not duplication. What overlap costs you there is narrower: a
lease expiring while the first copy is still mid-item, after which a second can
claim it and do it again.

The guard is per job, per server. The same job assigned to three servers still
runs on all three at once; that is what assigning it to three is for.

**Allow overlapping runs** on the job form turns the guard off for that job
(`overlap` in the payload): its next turn starts even while the last is still
going. Use it for a job that is safe to run twice at once and is falling behind
on one copy — a queue processor whose queue fills faster than one run drains
it. A job that runs after it (`depends_on`) waits until every running copy has
finished.

## Several environments

Servers are config and jobs are content, and that split is what makes one
schedule work across production, staging and a laptop.

A **Server** is a config entity, so it is exported to `config/sync` and
deployed everywhere — and, being config, its `hostname` and `status` can be
overridden per environment in `settings.php`. A **Cron Job** is a content
entity, living in each environment's database. A job assignment made by hand
on a laptop is gone the next time that database is replaced by a copy of
production, which is why per-environment differences belong on the servers
rather than on the jobs.

### One box standing in for several

The usual shape: production splits the work across three machines, and every
other environment runs the lot on one. Add a fourth server for that one, and
have each job name **its production worker and the fallback** — the `Servers`
field takes as many as you like:

| Job | Servers |
|---|---|
| Live bid queue | `worker_1`, `fallback` |
| Pre-auction lots | `worker_2`, `fallback` |
| Mail and marketing | `worker_3`, `fallback` |
| Drupal cron | *none — runs on every server* |

Then let each environment say which of the two is real. On production,
nothing: the three workers are enabled as stored, and `fallback` is switched
off.

```php
// settings.php, production.
$config['druker.server.fallback']['status'] = FALSE;
```

Everywhere else, the opposite:

```php
// settings.local.php, or the staging environment's settings.
$config['druker.server.worker_1']['status'] = FALSE;
$config['druker.server.worker_2']['status'] = FALSE;
$config['druker.server.worker_3']['status'] = FALSE;
```

Exactly one of the two servers a job names is live in any given environment,
so production splits the work three ways and everywhere else takes all of it:

```
production        prod-1     Drupal cron, Live bid queue
                  prod-2     Drupal cron, Pre-auction lots
                  prod-3     Drupal cron, Mail and marketing

everywhere else   local-box  Drupal cron, Live bid queue,
                             Pre-auction lots, Mail and marketing
```

Give `fallback` a hostname the local machine actually reports. In a container
that is usually the container's own name rather than anything memorable, and
the worker uses `os.Hostname()` — `drush druker:jobs` with no argument prints
what it believes this machine is called.

### Standing in for one machine rather than all of them

To have a staging box behave as one particular production worker, override
that worker's hostname instead of its status:

```php
$config['druker.server.worker_2']['hostname'] = 'staging-box';
```

That machine now gets exactly `worker_2`'s schedule. Only one server can be
mapped this way per environment — a hostname resolves to a single server — so
for all three at once, either use the fallback above, or run three workers on
the one box with `-host`:

```bash
./druker -host worker-1 -state /var/lib/druker/worker-1.json &
./druker -host worker-2 -state /var/lib/druker/worker-2.json &
./druker -host worker-3 -state /var/lib/druker/worker-3.json &
```

Give each its own `-state`. One-time completions are recorded by job id, and a
shared file would let one worker mark another's job as already done.

### What the flags actually mean

**Disabling a server** does not move its jobs anywhere. It stops that server
being matched by hostname, so its machine falls through to the jobs that name
no server at all. A job left naming only disabled or missing servers runs
nowhere — the dashboard says so, per job and on the server's card, and
`drush druker:check` warns about it wherever you run it.

**Disabling a job** switches it off everywhere, unconditionally. That is the
`Enable`/`Disable` operation on the job list, and it is what to reach for
instead of deleting something you may want back: the command, the schedule and
the assignment are all kept, and no worker is told about the job until it is
switched on again.

Job status is content, not config, so it cannot be overridden per environment
and does not survive a database copy. Environment differences go on servers.

### One rule

**Never save an overridden entity from the UI in the environment that
overrides it.** Editing and saving `worker_2` on the box where `settings.php`
has rewritten its hostname writes that hostname into the stored config, and
the next `drush cex` carries a local value into production. The job list's
`Enable`/`Disable` operation is deliberately not offered for servers for this
reason; changing a server's flag for real is a trip to its form, on an
environment that does not override it.

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
    'overlap' => FALSE,
    'depends_on' => NULL,
  ]);
}
```

An interval job is the same shape with `every` in place of `cron`, and an
optional `offset`:

```php
$event->addJob([
  'id' => 901,
  'name' => 'Process the bid queue',
  'command' => 'advancedqueue:queue:process bid_queue',
  'runner' => 'drush',
  'type' => 'interval',
  'every' => 25,
  'offset' => 4,
  'async' => TRUE,
  'overlap' => TRUE,
  'depends_on' => NULL,
]);
```

Any job may also carry a `timeout`, in seconds: how long one run may take
before the worker ends it, with whatever it started. Absent or `0` leaves it
to `-job-timeout`; where both are set the shorter wins, so a schedule cannot
give a job longer than the worker allows. A job stored in the admin UI has the
same setting as its **Timeout** field.

The shape is the same one `formatJob()` produces. Anything the worker cannot
read it drops, with a line in its log saying which job and why.

## Waking a job

A schedule says when a job runs at the latest. Sometimes the site knows
sooner that there is work: a webhook stored an event, someone sent an
invitation, a queue got its first item. Rather than run the job every few
seconds for the sake of those moments, the site can ask the worker to run it
now:

```php
\Drupal::service('druker.wake')->wake($job);  // a Cron Job, or its id
```

or from a terminal, by id or by name:

```
drush druker:wake 12
drush druker:wake "Process the mail queue"
```

The job starts within a second or two, on every worker that runs it: the
signal is a file named after the job's id, in a directory the site and the
worker share, and the worker lists that directory on every tick (its
one-second clock), which costs a directory read rather than a Drush call.

- **Once per signal.** A worker claims a signal by removing its file, so a job
  run by several workers that share the directory runs once, on whichever got
  there first. Waking a job again before the worker gets to it is the same
  file, so the same one run.
- **After a run in progress.** A woken job that is running keeps its signal,
  and runs again as soon as it finishes: what woke it may have arrived after
  the running copy looked. A job that allows overlap starts alongside instead.
- **Its schedule stands.** A woken interval or recurring job still runs at its
  next turn; a one-time job already done is not run again.
- **Never in the way.** A signal that cannot be written is logged and the job
  runs at its next turn, so code that wakes a job keeps the job's schedule as
  the floor and never depends on the wake. A signal for a job no worker runs
  (disabled, or its server gone) is removed after an hour.

**Where the signals go.** `$settings['druker_wake_dir']`, a path or a stream
URI. Unset, it is `private://druker/wake` on a site with a private file
system — the directory a web server and a worker in another container or on
another machine most often share — and otherwise a directory of the site's
own under `temporary://`, which serves only a worker on the same machine.
`FALSE` switches waking off. The worker is told the directory in the schedule
(`wake_dir`), so it is set in one place; run it as the site's user, so it can
remove what the site writes. `drush druker:check` says when waking is off, or
when the directory cannot be written.

## Commands

| Command | What it is for |
|---|---|
| `drush druker:jobs [host]` | The payload the worker fetches. `--pretty` to read it |
| `drush druker:check [host]` | Find jobs the worker would silently drop. Exits non-zero if any |
| `drush druker:wake <job>` | Run a job now, by id or by name (see [Waking a job](#waking-a-job)) |

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
