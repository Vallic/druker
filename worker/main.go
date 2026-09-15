// Command druker runs the schedule Drupal hands it.
//
// It asks the site what this server should be running, keeps those jobs on
// their cron expressions, and asks again every refresh period so a change
// made in the admin UI takes effect without a deploy or a restart.
package main

import (
	"context"
	"flag"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"
)

const (
	// How long to wait before asking again after a failed fetch.
	retryAfterFailure = 15 * time.Second

	// How long a fetch may take before it is abandoned.
	fetchTimeout = 60 * time.Second

	// How long to let jobs finish after a shutdown signal.
	shutdownGrace = 30 * time.Second
)

func main() {
	var (
		drushPath  = flag.String("drush", "", "Path to drush. Found automatically when empty.")
		hostname   = flag.String("host", "", "Server hostname to fetch jobs for. The machine's own when empty.")
		statePath  = flag.String("state", DefaultStatePath(), "Where to remember completed one-time jobs. Empty to not remember.")
		workDir    = flag.String("dir", "", "Directory jobs run in. The project root above vendor/ when empty.")
		jobTimeout = flag.Duration("job-timeout", 0, "Abandon a job that runs longer than this. Zero for no limit.")
		dryRun     = flag.Bool("dry-run", false, "Fetch the schedule, print what would run, and exit.")
		verbose    = flag.Bool("verbose", false, "Log every tick, not only what happens.")
		logFormat  = flag.String("log-format", logFormatText, "Log format: text or json.")
	)

	flag.Parse()

	level := slog.LevelInfo
	if *verbose {
		level = slog.LevelDebug
	}

	handler, err := newLogHandler(*logFormat, level, os.Stdout)
	if err != nil {
		// Too early for a logger: the logger is what failed to build.
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}

	logger := slog.New(handler)

	drush, err := resolveDrush(*drushPath)
	if err != nil {
		logger.Error("cannot find drush", "error", err.Error())
		os.Exit(1)
	}

	host := *hostname
	if host == "" {
		if host, err = os.Hostname(); err != nil {
			logger.Error("cannot read the hostname", "error", err.Error())
			os.Exit(1)
		}
	}

	// Jobs run here rather than wherever the worker was launched from, so a
	// relative path in a command means the same thing under systemd as it
	// does when someone runs the binary by hand.
	dir := *workDir
	if dir == "" {
		dir = projectRoot(drush)
	}

	logger = logger.With("server", host)

	if *dryRun {
		os.Exit(dryRunSchedule(drush, host, logger))
	}

	// Signals stop the scheduler, then jobs are given a moment to finish. A
	// container being rolled should not tear a half-written job in two.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	runner := NewRunner(drush, dir, logger, *jobTimeout)
	state := LoadState(*statePath)

	logger.Info("worker started", "drush", drush, "dir", dir, "state", *statePath)

	fetch := func() (*Schedule, error) {
		return FetchSchedule(drush, host, fetchTimeout)
	}

	supervise(ctx, logger, runner, state, fetch)

	logger.Info("stopping, waiting for jobs to finish")

	finished := make(chan struct{})
	go func() {
		runner.Wait()
		close(finished)
	}()

	select {
	case <-finished:
		logger.Info("stopped")
	case <-time.After(shutdownGrace):
		logger.Warn("stopped with jobs still running", "waited", shutdownGrace.String())
	}
}

// supervise fetches the schedule and runs it, over and over, until asked to stop.
//
// The fetch is a function rather than a drush path so a test can drive this
// loop without a Drupal site. Its pacing is the whole point of the loop: an
// empty schedule must wait, not spin, and a site that cannot be reached must
// back off rather than hammer it.
func supervise(ctx context.Context, logger *slog.Logger, runner *Runner, state *State, fetch func() (*Schedule, error)) {
	for {
		if ctx.Err() != nil {
			return
		}

		schedule, err := fetch()

		if err != nil {
			logger.Error("cannot fetch the schedule", "error", err.Error(), "retrying_in", retryAfterFailure.String())

			if !sleep(ctx, retryAfterFailure) {
				return
			}

			continue
		}

		for _, problem := range schedule.Prepare() {
			logger.Warn("ignoring a job", "reason", problem.Error())
		}

		refresh := schedule.RefreshPeriod()

		// Nothing to do is a normal state, not an error, and it has to cost
		// nothing: wait out the refresh and ask again. Falling through here
		// would busy-loop against Drupal for as long as the schedule is
		// empty, which is exactly when nobody is watching.
		if len(schedule.Jobs) == 0 {
			logger.Info("nothing scheduled", "next_check_in", refresh.String())

			if !sleep(ctx, refresh) {
				return
			}

			continue
		}

		logger.Info("schedule loaded", "jobs", len(schedule.Jobs), "next_check_in", refresh.String())

		// Held until the refresh period is up, then the loop asks again and
		// picks up anything that changed in the admin UI.
		cycle, cancel := context.WithTimeout(ctx, refresh)
		run(cycle, logger, runner, state, schedule)
		cancel()
	}
}

// run keeps one schedule ticking until the cycle ends.
func run(ctx context.Context, logger *slog.Logger, runner *Runner, state *State, schedule *Schedule) {
	// A minute is the finest granularity cron has, but the tick is a second
	// so a one-time job lands close to the moment it was asked for.
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()

	lastMinute := -1

	// The boundary each interval job last started at. Seeded with the current
	// one so that reloading the schedule is not itself an event: without this
	// every interval job would be due on the first tick of every cycle, and a
	// short refresh would turn a 10-minute job into a 10-minute-or-sooner one.
	lastFired := make(map[int]int64, len(schedule.Jobs))
	for _, job := range schedule.Jobs {
		if job.Type == typeInterval {
			lastFired[job.ID] = job.IntervalBoundary(time.Now())
		}
	}

	for {
		select {
		case <-ctx.Done():
			return

		case now := <-ticker.C:
			minute := now.Minute()
			newMinute := minute != lastMinute
			lastMinute = minute

			due := dueJobs(schedule, state, runner, lastFired, now, newMinute)

			if newMinute {
				// The line to turn on when the question is "why did my job
				// not fire": it says what the worker thought the time was
				// and what it decided was due.
				logger.Debug("minute evaluated", "at", now.Format("15:04"), "due", len(due))
			}

			if len(due) == 0 {
				continue
			}

			// One goroutine per tick, so a job that blocks its tick holds up
			// only the jobs queued behind it rather than the whole worker.
			go startDue(ctx, logger, runner, state, schedule, due, now)
		}
	}
}

// dueJobs is everything that should start now.
//
// lastFired is read and written here rather than inside Job, because a Job is
// copied out of the schedule by value on every pass and anything recorded on
// the copy would be thrown away with it.
func dueJobs(schedule *Schedule, state *State, runner *Runner, lastFired map[int]int64, now time.Time, newMinute bool) []Job {
	var due []Job

	for _, job := range schedule.Jobs {
		switch {
		case job.Type == typeCron && newMinute && job.Due(now):
			due = append(due, job)

		case job.Type == typeInterval && job.DueInterval(now, lastFired[job.ID]):
			// Recorded on being offered, not on starting. A job still running
			// from its last turn is refused by the runner, with a warning;
			// leaving the boundary unrecorded would re-offer it every second
			// until it finished, and bury that warning under its repeats.
			lastFired[job.ID] = job.IntervalBoundary(now)
			due = append(due, job)

		case job.Type == typeOnce && job.DueOnce(now) && !state.Completed(job.ID):
			// A one-time job stays due until it finishes, because completion
			// is only recorded then. Without this it would be offered every
			// second of its run and refused every second, which says nothing
			// useful and buries the log.
			if !runner.Running(job.ID) {
				due = append(due, job)
			}
		}
	}

	return due
}

// startDue launches the jobs due in one tick, honouring order and waits.
func startDue(ctx context.Context, logger *slog.Logger, runner *Runner, state *State, schedule *Schedule, due []Job, now time.Time) {
	for _, job := range due {
		if ctx.Err() != nil {
			return
		}

		if job.DependsOn != nil && runner.Running(*job.DependsOn) {
			logger.Info("waiting for the job it depends on", "job", job.Label(), "depends_on", *job.DependsOn)
			runner.WaitFor(ctx, *job.DependsOn)
		}

		finished := make(chan struct{})
		job := job

		started := runner.Start(ctx, job, func() {
			if job.Type == typeOnce {
				// Recorded whatever the outcome. A one-time job that failed
				// is a thing to investigate, not to run again on a loop.
				if err := state.Complete(job.ID, now.Unix()); err != nil {
					logger.Error("cannot record the one-time job as done", "job", job.Label(), "error", err.Error())
				}
			}

			close(finished)
		})

		// A job that is not asynchronous holds the rest of this tick behind
		// it, which is what "do not run this alongside anything else" means.
		if started && !job.Async {
			select {
			case <-finished:
			case <-ctx.Done():
				return
			}
		}
	}
}

// dryRunSchedule prints what the worker would do, and returns an exit code.
func dryRunSchedule(drush, host string, logger *slog.Logger) int {
	schedule, err := FetchSchedule(drush, host, fetchTimeout)

	if err != nil {
		logger.Error("cannot fetch the schedule", "error", err.Error())
		return 1
	}

	problems := schedule.Prepare()

	fmt.Printf("Server:  %s\n", schedule.Server)
	fmt.Printf("Refresh: %s\n", schedule.RefreshPeriod())
	fmt.Printf("Jobs:    %d\n\n", len(schedule.Jobs))

	// Sized to the widest label rather than a guess: a real schedule has job
	// names long enough to shunt every other column out of line.
	width := 0
	for _, job := range schedule.Jobs {
		if length := len(job.Label()); length > width {
			width = length
		}
	}

	for _, job := range schedule.Jobs {
		when := job.Cron

		switch job.Type {
		case typeOnce:
			when = "once at " + time.Unix(job.RunAt, 0).Format(time.RFC3339)

		case typeInterval:
			when = fmt.Sprintf("every %ds", job.Every)
			if job.Offset > 0 {
				when = fmt.Sprintf("every %ds +%ds", job.Every, job.Offset)
			}
		}

		fmt.Printf("  %-*s  %-18s %s\n", width, job.Label(), when, job.Command)

		if job.DependsOn != nil {
			fmt.Printf("  %-*s  after job #%d\n", width, "", *job.DependsOn)
		}
	}

	for _, problem := range problems {
		fmt.Printf("\nIgnored: %s\n", problem)
	}

	if len(problems) > 0 {
		return 1
	}

	return 0
}

// projectRoot is the directory holding vendor/, given the path to drush.
//
// That is where a person stands when they run drush by hand, so it is where
// a job's relative paths should resolve.
func projectRoot(drush string) string {
	// <root>/vendor/bin/drush
	root := filepath.Dir(filepath.Dir(filepath.Dir(drush)))

	if root == "" || root == "." {
		if wd, err := os.Getwd(); err == nil {
			return wd
		}
	}

	return root
}

// sleep waits, and reports false when the wait was cut short by a shutdown.
func sleep(ctx context.Context, d time.Duration) bool {
	timer := time.NewTimer(d)
	defer timer.Stop()

	select {
	case <-timer.C:
		return true
	case <-ctx.Done():
		return false
	}
}

// resolveDrush finds the drush binary.
//
// Looked for next to the worker and upwards from the working directory, so
// the binary runs from wherever it was dropped rather than only from the
// Drupal root.
func resolveDrush(given string) (string, error) {
	if given != "" {
		if _, err := os.Stat(given); err != nil {
			return "", fmt.Errorf("%s: %w", given, err)
		}

		return given, nil
	}

	var roots []string

	if wd, err := os.Getwd(); err == nil {
		roots = append(roots, wd)
	}

	if executable, err := os.Executable(); err == nil {
		roots = append(roots, filepath.Dir(executable))
	}

	for _, root := range roots {
		for dir := root; ; dir = filepath.Dir(dir) {
			candidate := filepath.Join(dir, "vendor", "bin", "drush")

			if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
				return candidate, nil
			}

			if parent := filepath.Dir(dir); parent == dir {
				break
			}
		}
	}

	return "", fmt.Errorf("no vendor/bin/drush above %v; pass -drush", roots)
}
