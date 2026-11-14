package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

// touchWake touches a job's wake file, as \Drupal\druker\Wake does, at a time.
func touchWake(t *testing.T, dir string, id string, at time.Time) string {
	t.Helper()

	path := filepath.Join(dir, id)

	if err := os.WriteFile(path, nil, 0o664); err != nil {
		t.Fatal(err)
	}

	if err := os.Chtimes(path, at, at); err != nil {
		t.Fatal(err)
	}

	return path
}

func exists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}

func wakeSchedule(jobs ...Job) *Schedule {
	return &Schedule{Jobs: jobs}
}

func TestAWakeRunsTheJobAndIsClaimedOnce(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	path := touchWake(t, dir, "7", now)
	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 3600, Command: "x"}, Job{ID: 8, Type: typeInterval, Every: 60, Command: "y"})
	runner := shellRunner(0)

	woken := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now)

	if len(woken) != 1 || woken[0].ID != 7 {
		t.Fatalf("woken: got %+v, want job 7 alone", woken)
	}

	if exists(path) {
		t.Error("the signal is still there after being claimed")
	}

	if again := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now); len(again) != 0 {
		t.Errorf("a claimed signal woke the job again: %+v", again)
	}
}

func TestAWakeForAnotherWorkersJobIsLeftForIt(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	fresh := touchWake(t, dir, "9", now.Add(-time.Minute))
	stale := touchWake(t, dir, "10", now.Add(-wakeExpiry-time.Minute))
	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 60, Command: "x"})

	if woken := claimWakes(dir, schedule, shellRunner(0), LoadState(""), quietLogger(), now); len(woken) != 0 {
		t.Fatalf("woken: got %+v, want none", woken)
	}

	if !exists(fresh) {
		t.Error("a recent signal for a job this worker does not run was taken")
	}

	if exists(stale) {
		t.Error("a signal nobody claimed for over the expiry was left behind")
	}
}

func TestARunningJobKeepsItsWakeUntilItFinishes(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	path := touchWake(t, dir, "7", now)
	job := Job{ID: 7, Type: typeInterval, Every: 60, Command: "x"}
	schedule := wakeSchedule(job)
	runner := shellRunner(0)

	if _, started := runner.claim(job); !started {
		t.Fatal("could not mark the job running")
	}

	if woken := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now); len(woken) != 0 {
		t.Fatalf("a running job was woken alongside itself: %+v", woken)
	}

	if !exists(path) {
		t.Fatal("the signal of a running job was dropped")
	}

	runner.release(job.ID)
	runner.wg.Done()

	if woken := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now); len(woken) != 1 {
		t.Fatalf("after its run the job was not woken: %+v", woken)
	}
}

func TestAJobAllowedToOverlapIsWokenWhileRunning(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	touchWake(t, dir, "7", now)
	job := Job{ID: 7, Type: typeInterval, Every: 60, Command: "x", Overlap: true}
	runner := shellRunner(0)
	runner.claim(job)
	defer func() {
		runner.release(job.ID)
		runner.wg.Done()
	}()

	if woken := claimWakes(dir, wakeSchedule(job), runner, LoadState(""), quietLogger(), now); len(woken) != 1 {
		t.Fatalf("woken: got %+v, want the job", woken)
	}
}

func TestACompletedOneTimeJobIsNotWokenAgain(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	path := touchWake(t, dir, "3", now)
	state := LoadState("")

	if err := state.Complete(3, now.Unix()); err != nil {
		t.Fatal(err)
	}

	schedule := wakeSchedule(Job{ID: 3, Type: typeOnce, RunAt: now.Unix() - 60, Command: "x"})

	if woken := claimWakes(dir, schedule, shellRunner(0), state, quietLogger(), now); len(woken) != 0 {
		t.Fatalf("woken: got %+v, want none", woken)
	}

	if exists(path) {
		t.Error("the signal for a job that will never run again was left")
	}
}

func TestOnlyJobIdsAreSignals(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	other := touchWake(t, dir, "README", now.Add(-2*wakeExpiry))
	zero := touchWake(t, dir, "0", now)

	if err := os.Mkdir(filepath.Join(dir, "7"), 0o775); err != nil {
		t.Fatal(err)
	}

	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 60, Command: "x"})

	if woken := claimWakes(dir, schedule, shellRunner(0), LoadState(""), quietLogger(), now); len(woken) != 0 {
		t.Fatalf("woken: got %+v, want none", woken)
	}

	if !exists(other) || !exists(zero) {
		t.Error("a file that is not a signal was removed")
	}
}

func TestNoWakeDirectoryWakesNothing(t *testing.T) {
	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 60, Command: "x"})

	if woken := claimWakes("", schedule, shellRunner(0), LoadState(""), quietLogger(), time.Now()); woken != nil {
		t.Errorf("waking is off, got %+v", woken)
	}

	missing := filepath.Join(t.TempDir(), "not-yet")

	if woken := claimWakes(missing, schedule, shellRunner(0), LoadState(""), quietLogger(), time.Now()); woken != nil {
		t.Errorf("a directory that does not exist yet woke %+v", woken)
	}
}

func TestWokenJobsJoinTheDueOnce(t *testing.T) {
	due := []Job{{ID: 1}, {ID: 2}}
	woken := []Job{{ID: 2}, {ID: 3}, {ID: 3}}

	got := withWoken(due, woken)

	if len(got) != 3 || got[0].ID != 1 || got[1].ID != 2 || got[2].ID != 3 {
		t.Errorf("got %+v, want jobs 1, 2 and 3 once each", got)
	}
}

// A signal dated in the future waits for its time, and is claimed once due.
func TestADelayedWakeWaitsForItsTime(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	path := touchWake(t, dir, "7", now.Add(5*time.Second))
	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 3600, Command: "x"})
	runner := shellRunner(0)

	if woken := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now); len(woken) != 0 {
		t.Fatalf("a signal due in 5s woke the job at once: %+v", woken)
	}

	if !exists(path) {
		t.Fatal("a signal not yet due was removed")
	}

	if woken := claimWakes(dir, schedule, runner, LoadState(""), quietLogger(), now.Add(6*time.Second)); len(woken) != 1 {
		t.Errorf("a signal past its time did not wake the job: %+v", woken)
	}
}

// A date beyond any delay a site can ask for is a wrong clock: the signal
// runs now rather than waiting for it.
func TestAWakeDatedBeyondAnyDelayIsNow(t *testing.T) {
	dir := t.TempDir()
	now := time.Now()
	touchWake(t, dir, "7", now.Add(maximumWakeDelay+time.Minute))
	schedule := wakeSchedule(Job{ID: 7, Type: typeInterval, Every: 3600, Command: "x"})

	if woken := claimWakes(dir, schedule, shellRunner(0), LoadState(""), quietLogger(), now); len(woken) != 1 {
		t.Errorf("a signal dated by a wrong clock did not wake the job: %+v", woken)
	}
}
