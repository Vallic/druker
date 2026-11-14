package main

import (
	"context"
	"errors"
	"log/slog"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// Runner executes jobs, and is the only thing that knows what is in flight.
//
// One runner lives as long as the worker, across every schedule refresh, which
// is what lets a job started under one schedule be refused a second copy by
// the next.
type Runner struct {
	drush   string
	dir     string
	logger  *slog.Logger
	timeout time.Duration

	// Every job runs under this, not under the scheduling cycle that started
	// it. A refresh ends a cycle every few minutes and must not touch a job
	// that is half way through a queue; only Stop cancels it.
	jobs  context.Context
	abort context.CancelFunc

	mu       sync.Mutex
	inflight map[int]*flight
	stopping bool

	wg sync.WaitGroup
}

// flight is one job ID in progress: one run, or several when the job allows
// overlap. done closes when the last of them finishes.
type flight struct {
	label   string
	running int
	done    chan struct{}
}

// NewRunner builds a runner that shells out to the given drush.
//
// Jobs run in dir. Without one they would inherit whatever directory the
// worker happened to be started in — which under systemd is "/" — and every
// relative path in a command would mean something different depending on how
// the worker was launched.
func NewRunner(drush string, dir string, logger *slog.Logger, timeout time.Duration) *Runner {
	jobs, abort := context.WithCancel(context.Background())

	return &Runner{
		drush:    drush,
		dir:      dir,
		logger:   logger,
		timeout:  timeout,
		jobs:     jobs,
		abort:    abort,
		inflight: map[int]*flight{},
	}
}

// Start begins a job, unless it is already running and does not allow overlap.
//
// By default a job still going when its next turn arrives is skipped rather
// than started alongside itself. Mostly this stops a job that outlives its
// own interval from accumulating copies until the machine falls over, and
// stops a job with no claim semantics of its own — an import, a report,
// anything that emails what it finds — from doing the same work twice. The
// turn may come from the same schedule or from the next one after a refresh:
// the runner outlives both, so it refuses either.
//
// A job that allows overlap is started regardless. Queue processors are the
// case for it: they lease their items, so two copies take different work.
// Keyed by job ID, so the same job on another server is another process and
// runs regardless.
//
// onFinish is told whether the job was interrupted by the worker stopping, as
// opposed to finishing, failing or timing out on its own.
func (r *Runner) Start(job Job, onFinish func(interrupted bool)) bool {
	alongside, started := r.claim(job)

	if !started {
		r.logger.Warn("skipped, still running from last time", "job", job.Label())
		return false
	}

	if alongside > 0 {
		r.logger.Info("overlapping its previous run", "job", job.Label(), "already_running", alongside)
	}

	go func() {
		defer r.wg.Done()
		defer r.release(job.ID)

		interrupted := r.execute(job)

		if onFinish != nil {
			onFinish(interrupted)
		}
	}()

	return true
}

// WaitFor blocks until a job is not running, or the context ends.
//
// Used for dependencies. A job whose dependency is not running returns
// immediately, which is the normal case: the two are usually scheduled far
// enough apart that the first finished long ago.
func (r *Runner) WaitFor(ctx context.Context, id int) {
	r.mu.Lock()
	running, ok := r.inflight[id]
	r.mu.Unlock()

	if !ok {
		return
	}

	select {
	case <-running.done:
	case <-ctx.Done():
	}
}

// Running reports whether a job is in flight.
func (r *Runner) Running(id int) bool {
	r.mu.Lock()
	defer r.mu.Unlock()

	_, running := r.inflight[id]

	return running
}

// Wait blocks until everything in flight has finished.
func (r *Runner) Wait() {
	r.wg.Wait()
}

// Stop refuses new jobs, gives running ones grace to finish, and then stops
// the rest — SIGTERM, then SIGKILL killAfter later. It returns once nothing is
// running, reporting whether everything finished within the grace.
func (r *Runner) Stop(grace time.Duration) bool {
	r.mu.Lock()
	r.stopping = true
	r.mu.Unlock()

	finished := make(chan struct{})

	go func() {
		r.wg.Wait()
		close(finished)
	}()

	timer := time.NewTimer(grace)
	defer timer.Stop()

	select {
	case <-finished:
		return true
	case <-timer.C:
	}

	r.mu.Lock()
	labels := make([]string, 0, len(r.inflight))
	for _, running := range r.inflight {
		labels = append(labels, running.label)
	}
	r.mu.Unlock()

	r.logger.Warn("stopping jobs still running after the grace period", "jobs", strings.Join(labels, ", "), "grace", grace.String())

	r.abort()
	<-finished

	return false
}

// commandFor turns a job into something to execute.
//
// A Drush job is split into arguments and handed straight to the binary. A
// shell job is handed to sh as one string, because the point of asking for a
// shell is the pipes and redirection that only a shell understands.
func (r *Runner) commandFor(job Job) (string, []string, error) {
	if job.Runner == runnerShell {
		if strings.TrimSpace(job.Command) == "" {
			return "", nil, errors.New("empty command line")
		}

		return "/bin/sh", []string{"-c", job.Command}, nil
	}

	args := SplitCommand(job.Command)

	if len(args) == 0 {
		return "", nil, errors.New("empty command")
	}

	return r.drush, args, nil
}

// claim marks a job as running. It reports how many runs of the same job were
// already going, and false when the job may not start: it is running and does
// not allow overlap, or the runner is stopping.
//
// The WaitGroup is added to here, under the lock Stop takes before it waits,
// so a job cannot slip in behind a Stop that thinks everything has finished.
func (r *Runner) claim(job Job) (int, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if r.stopping {
		return 0, false
	}

	running, ok := r.inflight[job.ID]

	if ok && !job.Overlap {
		return 0, false
	}

	if !ok {
		running = &flight{label: job.Label(), done: make(chan struct{})}
		r.inflight[job.ID] = running
	}

	running.running++
	r.wg.Add(1)

	return running.running - 1, true
}

// release marks one run of a job as finished, and wakes anything waiting on
// the job once no run of it is left.
func (r *Runner) release(id int) {
	r.mu.Lock()
	defer r.mu.Unlock()

	running := r.inflight[id]
	running.running--

	if running.running == 0 {
		delete(r.inflight, id)
		close(running.done)
	}
}

// limitFor is how long one run of a job may take, and whose limit that is.
//
// The job's own timeout where it has one, the worker's -job-timeout where it
// has not, and the shorter of the two where both are set: the schedule says
// what a job needs, and the person running the worker says what no job may
// exceed. Zero is no limit.
func (r *Runner) limitFor(job Job) (time.Duration, string) {
	own := time.Duration(job.Timeout) * time.Second

	switch {
	case own > 0 && (r.timeout <= 0 || own <= r.timeout):
		return own, "its timeout"
	case r.timeout > 0:
		return r.timeout, "-job-timeout"
	default:
		return 0, ""
	}
}

// execute runs the job and logs the outcome, reporting whether it was
// interrupted by the worker stopping.
func (r *Runner) execute(job Job) bool {
	name, args, err := r.commandFor(job)

	if err != nil {
		r.logger.Error("nothing to run", "job", job.Label(), "error", err.Error())
		return false
	}

	ctx := r.jobs
	limit, whose := r.limitFor(job)

	if limit > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, limit)
		defer cancel()
	}

	started := time.Now()
	r.logger.Info("started", "job", job.Label(), "runner", job.Runner, "command", Redact(job.Command))

	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Env = os.Environ()
	cmd.Dir = r.dir

	// Stopping the command is not enough on its own. Drush starts children,
	// and a child that outlives its parent keeps the output pipe open, so
	// CombinedOutput would sit there until the child felt like finishing —
	// which is exactly the run the timeout was meant to cut short.
	sweep := superviseProcessGroup(cmd)

	output, err := cmd.CombinedOutput()
	sweep()

	elapsed := time.Since(started).Round(time.Millisecond)

	if err != nil {
		if r.jobs.Err() != nil {
			r.logger.Warn("stopped, the worker is shutting down",
				"job", job.Label(),
				"took", elapsed.String(),
				"output", trimForLog(string(output)),
			)

			return true
		}

		attrs := []any{
			"job", job.Label(),
			"took", elapsed.String(),
			"error", err.Error(),
		}

		if errors.Is(ctx.Err(), context.DeadlineExceeded) {
			attrs = append(attrs, "reason", "ran longer than "+whose+" "+limit.String())
		}

		r.logger.Error("failed", append(attrs, "output", trimForLog(string(output)))...)

		return false
	}

	r.logger.Info("finished",
		"job", job.Label(),
		"took", elapsed.String(),
		"output", trimForLog(string(output)),
	)

	return false
}
