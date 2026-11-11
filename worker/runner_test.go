package main

import (
	"context"
	"io"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

func quietLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}

// A runner pointed at /bin/sh, so tests run real processes without Drupal.
func shellRunner(timeout time.Duration) *Runner {
	return NewRunner("/bin/sh", "", quietLogger(), timeout)
}

func shellJob(id int, script string, async bool) Job {
	return Job{ID: id, Name: "test", Command: "-c '" + script + "'", Async: async, Type: typeCron}
}

// A job that asks for a shell, which is a different execution path entirely.
func realShellJob(id int, command string) Job {
	return Job{ID: id, Name: "shell", Command: command, Runner: runnerShell, Async: true, Type: typeCron}
}

func TestRunsAJobToCompletion(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "ran")
	runner := shellRunner(0)

	done := make(chan struct{})
	started := runner.Start(shellJob(1, "touch "+marker, true), func(bool) { close(done) })

	if !started {
		t.Fatal("the job should have started")
	}

	<-done
	runner.Wait()

	if _, err := os.Stat(marker); err != nil {
		t.Fatalf("the job did not run: %v", err)
	}
}

// A job that outlives its own interval must not accumulate copies.
func TestAJobDoesNotOverlapItself(t *testing.T) {
	runner := shellRunner(0)
	release := make(chan struct{})

	first := runner.Start(shellJob(1, "sleep 5", true), nil)
	if !first {
		t.Fatal("the first run should have started")
	}

	// Wait until it is definitely in flight.
	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	if runner.Start(shellJob(1, "true", true), nil) {
		t.Error("a second run should have been refused while the first is going")
	}

	close(release)
}

// A job that depends on another must not start while that one is going.
func TestWaitForBlocksUntilTheDependencyFinishes(t *testing.T) {
	runner := shellRunner(0)
	runner.Start(shellJob(1, "sleep 0.3", true), nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	started := time.Now()
	runner.WaitFor(context.Background(), 1)
	waited := time.Since(started)

	if waited < 100*time.Millisecond {
		t.Errorf("returned after %s, so it did not wait", waited)
	}

	if runner.Running(1) {
		t.Error("the dependency should have finished")
	}
}

// Waiting on a job that is not running returns at once.
func TestWaitForReturnsImmediatelyWhenNothingIsRunning(t *testing.T) {
	runner := shellRunner(0)

	started := time.Now()
	runner.WaitFor(context.Background(), 42)

	if waited := time.Since(started); waited > 50*time.Millisecond {
		t.Errorf("waited %s for a job that was never running", waited)
	}
}

// A shutdown must not leave WaitFor blocking forever.
func TestWaitForGivesUpWhenTheContextEnds(t *testing.T) {
	runner := shellRunner(0)
	runner.Start(shellJob(1, "sleep 5", true), nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()

	started := time.Now()
	runner.WaitFor(ctx, 1)

	if waited := time.Since(started); waited > time.Second {
		t.Errorf("waited %s despite the context ending", waited)
	}
}

// A job that outlives its timeout is killed rather than left running.
func TestJobTimeoutStopsALongJob(t *testing.T) {
	runner := shellRunner(100 * time.Millisecond)

	done := make(chan struct{})
	started := time.Now()
	runner.Start(shellJob(1, "sleep 5", true), func(bool) { close(done) })

	<-done

	if elapsed := time.Since(started); elapsed > 2*time.Second {
		t.Errorf("the job ran for %s despite a 100ms timeout", elapsed)
	}
}

// The fix that matters: a job whose children outlive it must not hold the
// worker open. Killing only the parent leaves the child on the output pipe.
func TestJobTimeoutKillsChildrenToo(t *testing.T) {
	runner := shellRunner(100 * time.Millisecond)

	done := make(chan struct{})
	started := time.Now()

	// The shell forks here rather than replacing itself, so there is a
	// grandchild holding the pipe.
	runner.Start(shellJob(1, "sleep 5 & wait", true), func(bool) { close(done) })

	<-done

	if elapsed := time.Since(started); elapsed > 2*time.Second {
		t.Errorf("took %s: the child kept the pipe open", elapsed)
	}
}

// Concurrent starts and waits must not race.
func TestRunnerIsSafeUnderConcurrency(t *testing.T) {
	runner := shellRunner(0)
	var wg sync.WaitGroup

	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func(id int) {
			defer wg.Done()
			runner.Start(shellJob(id%5, "true", true), nil)
			runner.WaitFor(context.Background(), id%5)
			runner.Running(id % 5)
		}(i)
	}

	wg.Wait()
	runner.Wait()
}

// A shell job gets the whole command line, so pipes and redirection work.
// Splitting it into arguments the way a Drush job is split would break both.
func TestShellRunnerGetsTheWholeCommandLine(t *testing.T) {
	dir := t.TempDir()
	runner := NewRunner("/bin/sh", dir, quietLogger(), 0)

	done := make(chan struct{})
	runner.Start(realShellJob(1, "echo one two > out.txt && echo three >> out.txt"), func(bool) { close(done) })
	<-done
	runner.Wait()

	written, err := os.ReadFile(filepath.Join(dir, "out.txt"))
	if err != nil {
		t.Fatalf("the shell did not run: %v", err)
	}

	if got := string(written); got != "one two\nthree\n" {
		t.Errorf("got %q", got)
	}
}

// Jobs run in the directory they were given, not wherever the worker started.
func TestJobsRunInTheConfiguredDirectory(t *testing.T) {
	dir := t.TempDir()
	runner := NewRunner("/bin/sh", dir, quietLogger(), 0)

	done := make(chan struct{})
	runner.Start(realShellJob(1, "pwd > where.txt"), func(bool) { close(done) })
	<-done
	runner.Wait()

	written, err := os.ReadFile(filepath.Join(dir, "where.txt"))
	if err != nil {
		t.Fatalf("nothing was written: %v", err)
	}

	// macOS resolves the temporary directory through a symlink, so compare
	// what the shell reports against the resolved path.
	resolved, _ := filepath.EvalSymlinks(dir)
	if got := strings.TrimSpace(string(written)); got != dir && got != resolved {
		t.Errorf("ran in %q, want %q", got, dir)
	}
}

// An empty shell command line is refused rather than handed to sh.
func TestEmptyShellCommandIsRefused(t *testing.T) {
	runner := NewRunner("/bin/sh", t.TempDir(), quietLogger(), 0)

	if _, _, err := runner.commandFor(realShellJob(1, "   ")); err == nil {
		t.Error("an empty command line should not reach a shell")
	}
}

// A Drush job is still split into arguments.
func TestDrushRunnerSplitsArguments(t *testing.T) {
	runner := NewRunner("/usr/bin/drush", "", quietLogger(), 0)

	name, args, err := runner.commandFor(Job{ID: 1, Command: `sql:query 'SELECT 1'`, Runner: runnerDrush})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if name != "/usr/bin/drush" {
		t.Errorf("ran %q", name)
	}
	if len(args) != 2 || args[0] != "sql:query" {
		t.Errorf("got args %#v", args)
	}
}

// A job that allows overlap starts alongside a run of itself, and a
// dependency on it waits for every run to finish.
func TestAnOverlappingJobRunsAlongsideItself(t *testing.T) {
	runner := shellRunner(0)

	first := shellJob(1, "sleep 0.3", true)
	first.Overlap = true
	second := shellJob(1, "sleep 0.6", true)
	second.Overlap = true

	started := time.Now()

	if !runner.Start(first, nil) || !runner.Start(second, nil) {
		t.Fatal("both runs should have started")
	}

	runner.WaitFor(context.Background(), 1)

	if waited := time.Since(started); waited < 500*time.Millisecond {
		t.Errorf("WaitFor returned after %s, before the second run finished", waited)
	}

	if runner.Running(1) {
		t.Error("the job should no longer be running")
	}
}

// A shutdown lets a job that finishes within the grace finish.
func TestStopWaitsForRunningJobs(t *testing.T) {
	marker := filepath.Join(t.TempDir(), "ran")
	runner := shellRunner(0)

	interrupted := make(chan bool, 1)
	runner.Start(shellJob(1, "sleep 0.5; touch "+marker, true), func(stopped bool) { interrupted <- stopped })

	if !runner.Stop(5 * time.Second) {
		t.Fatal("the job finished within the grace, Stop should say so")
	}

	if <-interrupted {
		t.Error("a job that finished on its own was reported as interrupted")
	}

	if _, err := os.Stat(marker); err != nil {
		t.Fatalf("the job was cut short: %v", err)
	}
}

// A job still running when the grace is up gets SIGTERM, and is reported as
// interrupted rather than failed.
func TestStopTerminatesJobsThatOutliveTheGrace(t *testing.T) {
	runner := shellRunner(0)

	interrupted := make(chan bool, 1)
	runner.Start(shellJob(1, "sleep 30", true), func(stopped bool) { interrupted <- stopped })

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	started := time.Now()

	if runner.Stop(100 * time.Millisecond) {
		t.Error("the job outlived the grace, Stop should say so")
	}

	if took := time.Since(started); took > 3*time.Second {
		t.Errorf("Stop took %s: SIGTERM did not stop a plain sleep", took)
	}

	if !<-interrupted {
		t.Error("a job stopped by the shutdown should be reported as interrupted")
	}
}

// A job that ignores SIGTERM is killed killAfter later, children and all.
func TestStopEscalatesToSIGKILL(t *testing.T) {
	defer func(after time.Duration) { killAfter = after }(killAfter)
	killAfter = 200 * time.Millisecond

	runner := shellRunner(0)
	runner.Start(shellJob(1, `trap "" TERM; sleep 30 & wait`, true), nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	// Give the shell time to install the trap before the signal arrives.
	time.Sleep(100 * time.Millisecond)

	started := time.Now()
	runner.Stop(50 * time.Millisecond)

	if took := time.Since(started); took > 3*time.Second {
		t.Errorf("Stop took %s: a job ignoring SIGTERM was never killed", took)
	}
}

// Nothing starts once the runner is stopping.
func TestAStoppedRunnerRefusesNewJobs(t *testing.T) {
	runner := shellRunner(0)
	runner.Stop(time.Second)

	if runner.Start(shellJob(1, "true", true), nil) {
		t.Error("a job started after Stop")
	}
}

// A job cut short by its own timeout is a failure, not an interruption: a
// one-time job that times out is recorded, not run again on every restart.
func TestATimeoutIsNotAnInterruption(t *testing.T) {
	runner := shellRunner(100 * time.Millisecond)

	interrupted := make(chan bool, 1)
	runner.Start(shellJob(1, "sleep 5", true), func(stopped bool) { interrupted <- stopped })

	if <-interrupted {
		t.Error("a timed-out job was reported as interrupted by a shutdown")
	}

	runner.Wait()
}
