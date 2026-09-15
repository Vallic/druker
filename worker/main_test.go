package main

import (
	"context"
	"testing"
	"time"
)

// A one-time job stays due until it finishes, so it must not be offered again
// while it is running: that produced a "still running" warning every second.
func TestARunningOneTimeJobIsNotOfferedAgain(t *testing.T) {
	schedule := &Schedule{
		Refresh: 60,
		Jobs: []Job{
			{ID: 1, Name: "once", Command: "-c 'sleep 2'", Type: typeOnce, RunAt: 1, Async: true},
		},
	}

	state := LoadState("")
	runner := shellRunner(0)
	now := time.Unix(1000, 0)

	due := dueJobs(schedule, state, runner, map[int]int64{}, now, true)
	if len(due) != 1 {
		t.Fatalf("expected the job to be due, got %d", len(due))
	}

	runner.Start(context.Background(), due[0], nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	if again := dueJobs(schedule, state, runner, map[int]int64{}, now, false); len(again) != 0 {
		t.Errorf("a running one-time job should not be due again, got %d", len(again))
	}

	runner.Wait()

	// Still not recorded as complete here: that is startDue's job. What
	// matters is that it was not offered twice while running.
	if state.Completed(1) {
		t.Error("dueJobs should not be recording completions")
	}
}

// A cron job that overruns its schedule is a different story: that one should
// still be offered, so the runner can refuse it and say so.
func TestAnOverrunningCronJobIsStillOffered(t *testing.T) {
	cron, err := ParseCron("* * * * *")
	if err != nil {
		t.Fatal(err)
	}

	schedule := &Schedule{
		Refresh: 60,
		Jobs:    []Job{{ID: 1, Name: "busy", Command: "-c 'sleep 2'", Type: typeCron, schedule: cron, Async: true}},
	}

	runner := shellRunner(0)
	runner.Start(context.Background(), schedule.Jobs[0], nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	if due := dueJobs(schedule, LoadState(""), runner, map[int]int64{}, time.Now(), true); len(due) != 1 {
		t.Error("an overrunning cron job should still be offered, and refused loudly")
	}

	runner.Wait()
}

// An interval job is offered once per boundary, not on every tick between
// them. The bug this guards is a queue processor asked for every 30 seconds
// starting a new Drush process every second.
func TestAnIntervalJobIsOfferedOncePerBoundary(t *testing.T) {
	schedule := &Schedule{
		Refresh: 60,
		Jobs:    []Job{{ID: 1, Name: "queue", Command: "-c true", Type: typeInterval, Every: 30, Async: true}},
	}

	lastFired := map[int]int64{1: schedule.Jobs[0].IntervalBoundary(time.Unix(1200, 0))}
	runner := shellRunner(0)
	state := LoadState("")

	offers := 0
	for second := int64(1200); second < 1260; second++ {
		offers += len(dueJobs(schedule, state, runner, lastFired, time.Unix(second, 0), false))
	}

	// 1200 is a boundary and was seeded as already fired, so the minute that
	// follows it holds exactly two more: 1230 and 1260 is outside the range.
	if offers != 1 {
		t.Errorf("expected one run in the 59 seconds after a seeded boundary, got %d", offers)
	}
}

// A tick the worker misses under load must delay the run, not lose it.
func TestAMissedTickStillRunsTheIntervalJob(t *testing.T) {
	schedule := &Schedule{
		Refresh: 60,
		Jobs:    []Job{{ID: 1, Name: "queue", Command: "-c true", Type: typeInterval, Every: 30, Async: true}},
	}

	// Seeded at 1200, then nothing is evaluated until 1247 — well past the
	// 1230 boundary, and not itself a boundary.
	lastFired := map[int]int64{1: 1200}

	due := dueJobs(schedule, LoadState(""), shellRunner(0), lastFired, time.Unix(1247, 0), false)
	if len(due) != 1 {
		t.Fatalf("a missed boundary should still run, got %d", len(due))
	}

	if lastFired[1] != 1230 {
		t.Errorf("expected the missed boundary 1230 to be recorded, got %d", lastFired[1])
	}
}
