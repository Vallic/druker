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

	due := dueJobs(schedule, state, runner, now, true)
	if len(due) != 1 {
		t.Fatalf("expected the job to be due, got %d", len(due))
	}

	runner.Start(context.Background(), due[0], nil)

	for !runner.Running(1) {
		time.Sleep(time.Millisecond)
	}

	if again := dueJobs(schedule, state, runner, now, false); len(again) != 0 {
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

	if due := dueJobs(schedule, LoadState(""), runner, time.Now(), true); len(due) != 1 {
		t.Error("an overrunning cron job should still be offered, and refused loudly")
	}

	runner.Wait()
}
