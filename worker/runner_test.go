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
	started := runner.Start(context.Background(), shellJob(1, "touch "+marker, true), func() { close(done) })

	if !started {
		t.Fatal("the job should have started")
	}

	<-done
	runner.Wait()

	if _, err := os.Stat(marker); err != nil {
		t.Fatalf("the job did not run: %v", err)
	}
}

// Two copies of a queue processor is how a queue gets worked twice.
func TestAJobDoesNotOverlapItself(t *testing.T) {
	runner := shellRunner(0)
	release := make(chan struct{})

	first := runner.Start(context.Background(), shellJob(1, "sleep 5", true), nil)
	if !first {
		t.Fatal("the first run should have started")
	}

	// Wait until it is definitely in flight.
	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	if runner.Start(context.Background(), shellJob(1, "true", true), nil) {
		t.Error("a second run should have been refused while the first is going")
	}

	close(release)
}

// A job that depends on another must not start while that one is going.
func TestWaitForBlocksUntilTheDependencyFinishes(t *testing.T) {
	runner := shellRunner(0)
	runner.Start(context.Background(), shellJob(1, "sleep 0.3", true), nil)

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
	runner.Start(context.Background(), shellJob(1, "sleep 5", true), nil)

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
	runner.Start(context.Background(), shellJob(1, "sleep 5", true), func() { close(done) })

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

	// The shell forks here rather than execing, so there is a grandchild.
	runner.Start(context.Background(), shellJob(1, "sleep 5 & wait", true), func() { close(done) })

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
			runner.Start(context.Background(), shellJob(id%5, "true", true), nil)
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
	runner.Start(context.Background(), realShellJob(1, "echo one two > out.txt && echo three >> out.txt"), func() { close(done) })
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
	runner.Start(context.Background(), realShellJob(1, "pwd > where.txt"), func() { close(done) })
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
