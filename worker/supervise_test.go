package main

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"
)

// A fetch function that counts how often it was called.
type countingFetch struct {
	mu       sync.Mutex
	calls    int
	schedule *Schedule
	err      error
}

func (c *countingFetch) fetch() (*Schedule, error) {
	c.mu.Lock()
	defer c.mu.Unlock()

	c.calls++

	if c.err != nil {
		return nil, c.err
	}

	// A fresh copy each time: Prepare() mutates what it is given, and a real
	// fetch would return a new object every call.
	copied := *c.schedule
	copied.Jobs = append([]Job(nil), c.schedule.Jobs...)

	return &copied, nil
}

func (c *countingFetch) count() int {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.calls
}

// An empty schedule must wait out the refresh, not spin.
//
// This is the loop's worst failure: nothing to run is precisely when nobody
// is watching, and a worker that busy-loops against Drupal in that state
// would sit there hammering it until someone noticed the load.
func TestAnEmptyScheduleWaitsRatherThanSpinning(t *testing.T) {
	// The production floor is a minute, which no test can wait out.
	defer func(floor time.Duration) { MinimumRefresh = floor }(MinimumRefresh)
	MinimumRefresh = 10 * time.Millisecond

	fetcher := &countingFetch{schedule: &Schedule{Refresh: 1, Jobs: nil}}

	ctx, cancel := context.WithTimeout(context.Background(), 2500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), shellRunner(0), LoadState(""), fetcher.fetch)

	// A one second refresh over two and a half seconds: three or so fetches.
	// A spin would be thousands.
	calls := fetcher.count()

	if calls > 6 {
		t.Errorf("fetched %d times in 2.5s with a 1s refresh: it is spinning", calls)
	}
	if calls < 2 {
		t.Errorf("fetched %d times: it is not coming back at all", calls)
	}
}

// A schedule whose every job was dropped as unreadable is just as empty.
func TestAScheduleOfOnlyBadJobsAlsoWaits(t *testing.T) {
	// The production floor is a minute, which no test can wait out.
	defer func(floor time.Duration) { MinimumRefresh = floor }(MinimumRefresh)
	MinimumRefresh = 10 * time.Millisecond

	fetcher := &countingFetch{
		schedule: &Schedule{
			Refresh: 1,
			Jobs: []Job{
				{ID: 1, Command: "a", Type: typeCron, Cron: "not a cron"},
				{ID: 2, Command: "", Type: typeCron, Cron: "* * * * *"},
			},
		},
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), shellRunner(0), LoadState(""), fetcher.fetch)

	if calls := fetcher.count(); calls > 6 {
		t.Errorf("fetched %d times: dropping every job left it spinning", calls)
	}
}

// A site that cannot be reached is backed off from, not hammered.
func TestAFailedFetchBacksOff(t *testing.T) {
	fetcher := &countingFetch{err: context.DeadlineExceeded}

	ctx, cancel := context.WithTimeout(context.Background(), 1500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), shellRunner(0), LoadState(""), fetcher.fetch)

	// retryAfterFailure is 15s, so within 1.5s it should have tried once.
	if calls := fetcher.count(); calls != 1 {
		t.Errorf("fetched %d times while failing, want 1", calls)
	}
}

// A refresh the site changed is honoured on the next cycle.
//
// This is how a subscriber altering the refresh in Drupal reaches the worker:
// the cycle is only ever as long as the schedule it was given said.
func TestTheRefreshFromDrupalIsWhatTheCycleUses(t *testing.T) {
	// The production floor is a minute, which no test can wait out.
	defer func(floor time.Duration) { MinimumRefresh = floor }(MinimumRefresh)
	MinimumRefresh = 10 * time.Millisecond

	// One job, never due, so the cycle runs its full length doing nothing.
	cron, err := ParseCron("0 0 1 1 *")
	if err != nil {
		t.Fatal(err)
	}

	fetcher := &countingFetch{
		schedule: &Schedule{
			Refresh: 1,
			Jobs:    []Job{{ID: 1, Command: "true", Type: typeCron, Cron: "0 0 1 1 *", schedule: cron}},
		},
	}

	ctx, cancel := context.WithTimeout(context.Background(), 2500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), shellRunner(0), LoadState(""), fetcher.fetch)

	// A one second refresh means it comes back about twice in that window.
	// If it ignored the payload and used its own idea of a period, it would
	// have fetched once and sat there.
	if calls := fetcher.count(); calls < 2 {
		t.Errorf("fetched %d times with a 1s refresh: the payload's refresh is being ignored", calls)
	}
}

// A refresh of zero is not taken literally.
func TestAMissingRefreshDoesNotBecomeABusyLoop(t *testing.T) {
	fetcher := &countingFetch{schedule: &Schedule{Refresh: 0, Jobs: nil}}

	ctx, cancel := context.WithTimeout(context.Background(), 1200*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), shellRunner(0), LoadState(""), fetcher.fetch)

	// RefreshPeriod turns 0 into the half-hour default, so one fetch and a wait.
	if calls := fetcher.count(); calls != 1 {
		t.Errorf("fetched %d times, want 1: a zero refresh was taken literally", calls)
	}
}

// A refresh stops the schedule, not the jobs it started.
//
// The bug this guards: jobs ran under the refresh cycle's context, so every
// refresh SIGKILLed whatever was still running — a queue processor that
// started a minute before the refresh got a minute.
func TestARunningJobOutlivesTheRefresh(t *testing.T) {
	defer func(floor time.Duration) { MinimumRefresh = floor }(MinimumRefresh)
	MinimumRefresh = 10 * time.Millisecond

	marker := filepath.Join(t.TempDir(), "ran")

	// Started in the first one-second cycle, finishes in the third.
	fetcher := &countingFetch{
		schedule: &Schedule{
			Refresh: 1,
			Jobs: []Job{{
				ID:      1,
				Name:    "long",
				Command: "-c 'sleep 2; echo done >> " + marker + "'",
				Type:    typeOnce,
				RunAt:   time.Now().Unix() - 1,
				Async:   true,
			}},
		},
	}

	runner := shellRunner(0)
	state := LoadState("")

	ctx, cancel := context.WithTimeout(context.Background(), 3500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), runner, state, fetcher.fetch)
	runner.Stop(5 * time.Second)

	if calls := fetcher.count(); calls < 3 {
		t.Fatalf("fetched %d times: the job never lived through a refresh", calls)
	}

	output, err := os.ReadFile(marker)
	if err != nil {
		t.Fatalf("the job was killed by a refresh: %v", err)
	}

	if runs := strings.Count(string(output), "done"); runs != 1 {
		t.Errorf("the job ran %d times, want 1", runs)
	}

	if !state.Completed(1) {
		t.Error("the job finished but was not recorded as done")
	}
}

// A wake starts a job within a tick or two, far from its interval.
func TestAWokenJobRunsAtOnce(t *testing.T) {
	defer func(floor time.Duration) { MinimumRefresh = floor }(MinimumRefresh)
	MinimumRefresh = 10 * time.Millisecond

	wakeDir := t.TempDir()
	marker := filepath.Join(t.TempDir(), "ran")

	fetcher := &countingFetch{
		schedule: &Schedule{
			Refresh: 60,
			WakeDir: wakeDir,
			Jobs: []Job{{
				ID:      4,
				Name:    "hourly",
				Command: "-c 'echo done >> " + marker + "'",
				Type:    typeInterval,
				Every:   3600,
				Async:   true,
			}},
		},
	}

	if err := os.WriteFile(filepath.Join(wakeDir, "4"), nil, 0o664); err != nil {
		t.Fatal(err)
	}

	runner := shellRunner(0)
	ctx, cancel := context.WithTimeout(context.Background(), 2500*time.Millisecond)
	defer cancel()

	supervise(ctx, quietLogger(), runner, LoadState(""), fetcher.fetch)
	runner.Stop(5 * time.Second)

	output, err := os.ReadFile(marker)
	if err != nil {
		t.Fatalf("the woken job did not run: %v", err)
	}

	if runs := strings.Count(string(output), "done"); runs != 1 {
		t.Errorf("the job ran %d times, want 1", runs)
	}

	if _, err := os.Stat(filepath.Join(wakeDir, "4")); err == nil {
		t.Error("the signal was not claimed")
	}
}
