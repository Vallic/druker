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
type Runner struct {
	drush   string
	dir     string
	logger  *slog.Logger
	timeout time.Duration

	mu       sync.Mutex
	inflight map[int]chan struct{}

	wg sync.WaitGroup
}

// NewRunner builds a runner that shells out to the given drush.
//
// Jobs run in dir. Without one they would inherit whatever directory the
// worker happened to be started in — which under systemd is "/" — and every
// relative path in a command would mean something different depending on how
// the worker was launched.
func NewRunner(drush string, dir string, logger *slog.Logger, timeout time.Duration) *Runner {
	return &Runner{
		drush:    drush,
		dir:      dir,
		logger:   logger,
		timeout:  timeout,
		inflight: map[int]chan struct{}{},
	}
}

// Start begins a job, unless it is already running.
//
// A job still going when its next tick arrives is skipped rather than started
// alongside itself. Mostly this stops a job that outlives its own interval
// from accumulating copies until the machine falls over, and stops a job with
// no claim semantics of its own — an import, a report, anything that emails
// what it finds — from doing the same work twice.
//
// Queue processors are the case that needs it least: they lease their items,
// so two copies normally take different work. Keyed by job ID, so the same
// job on another server is another process and runs regardless.
func (r *Runner) Start(ctx context.Context, job Job, onFinish func()) bool {
	done, started := r.claim(job.ID)

	if !started {
		r.logger.Warn("skipped, still running from last time", "job", job.Label())
		return false
	}

	r.wg.Add(1)

	go func() {
		defer r.wg.Done()
		defer r.release(job.ID, done)

		r.execute(ctx, job)

		if onFinish != nil {
			onFinish()
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
	done, running := r.inflight[id]
	r.mu.Unlock()

	if !running {
		return
	}

	select {
	case <-done:
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

// claim marks a job as running, reporting false when it already was.
func (r *Runner) claim(id int) (chan struct{}, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if _, running := r.inflight[id]; running {
		return nil, false
	}

	done := make(chan struct{})
	r.inflight[id] = done

	return done, true
}

// release marks a job as finished and wakes anything waiting on it.
func (r *Runner) release(id int, done chan struct{}) {
	r.mu.Lock()
	delete(r.inflight, id)
	r.mu.Unlock()

	close(done)
}

// execute runs the job and logs the outcome.
func (r *Runner) execute(ctx context.Context, job Job) {
	name, args, err := r.commandFor(job)

	if err != nil {
		r.logger.Error("nothing to run", "job", job.Label(), "error", err.Error())
		return
	}

	if r.timeout > 0 {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, r.timeout)
		defer cancel()
	}

	started := time.Now()
	r.logger.Info("started", "job", job.Label(), "runner", job.Runner, "command", job.Command)

	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Env = os.Environ()
	cmd.Dir = r.dir

	// Killing the command is not enough on its own. Drush starts children,
	// and a child that outlives its parent keeps the output pipe open, so
	// CombinedOutput would sit there until the child felt like finishing —
	// which is exactly the run the timeout was meant to cut short.
	superviseProcessGroup(cmd)

	output, err := cmd.CombinedOutput()
	elapsed := time.Since(started).Round(time.Millisecond)

	if err != nil {
		r.logger.Error("failed",
			"job", job.Label(),
			"took", elapsed.String(),
			"error", err.Error(),
			"output", trimForLog(string(output)),
		)

		return
	}

	r.logger.Info("finished",
		"job", job.Label(),
		"took", elapsed.String(),
		"output", trimForLog(string(output)),
	)
}
