package main

import (
	"os"
	"testing"
	"time"
)

// The payload Drupal emits has to be the payload the worker reads. This is the
// test that was missing: the two halves used to disagree completely, and
// nothing anywhere would have said so.
func TestParsesTheDrupalSample(t *testing.T) {
	payload, err := os.ReadFile("../examples/sample-output.json")
	if err != nil {
		t.Fatalf("cannot read the sample: %v", err)
	}

	schedule, err := ParseSchedule(payload)
	if err != nil {
		t.Fatalf("cannot parse the sample Drupal emits: %v", err)
	}

	if problems := schedule.Prepare(); len(problems) != 0 {
		t.Fatalf("the sample should be entirely valid, got %v", problems)
	}

	if schedule.Server != "i-b433895b" {
		t.Errorf("server: got %q", schedule.Server)
	}

	if schedule.Refresh != 600 {
		t.Errorf("refresh: got %d", schedule.Refresh)
	}

	if len(schedule.Jobs) != 7 {
		t.Fatalf("jobs: got %d, want 7", len(schedule.Jobs))
	}

	first := schedule.Jobs[0]

	if first.Command != "advancedqueue:queue:process wa_sendgrid" {
		t.Errorf("command: got %q", first.Command)
	}
	if !first.Async {
		t.Error("the first job is asynchronous in the sample")
	}
	if !first.Due(at("2026-09-13 12:34")) {
		t.Error("a every-minute job should be due every minute")
	}

	third := schedule.Jobs[2]

	if third.DependsOn == nil || *third.DependsOn != 2 {
		t.Errorf("depends_on: got %v, want 2", third.DependsOn)
	}

	if first.Runner != runnerDrush {
		t.Errorf("runner: got %q, want drush", first.Runner)
	}

	last := schedule.Jobs[6]

	if last.Runner != runnerShell {
		t.Errorf("the sample should carry a shell job, got runner %q", last.Runner)
	}

	fifth := schedule.Jobs[4]

	if fifth.Type != typeOnce || fifth.RunAt != 1747392000 {
		t.Errorf("one-time job: got type %q run_at %d", fifth.Type, fifth.RunAt)
	}
	if !fifth.DueOnce(time.Unix(1747392001, 0)) {
		t.Error("a one-time job is due once its moment has passed")
	}
	if fifth.DueOnce(time.Unix(1747391999, 0)) {
		t.Error("and not before")
	}
}

// One bad job must not take the rest of the server down with it.
func TestBadJobsAreDroppedNotFatal(t *testing.T) {
	schedule, err := ParseSchedule([]byte(`{
		"server": "web1",
		"refresh": 60,
		"jobs": [
			{"id": 1, "name": "Good", "command": "core:status", "type": "cron", "cron": "*/5 * * * *"},
			{"id": 2, "name": "Bad expression", "command": "core:status", "type": "cron", "cron": "not a cron"},
			{"id": 3, "name": "No command", "command": "", "type": "cron", "cron": "* * * * *"},
			{"id": 4, "name": "No moment", "command": "core:status", "type": "once"},
			{"id": 5, "name": "Unknown", "command": "core:status", "type": "whenever"}
		]
	}`))

	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	problems := schedule.Prepare()

	if len(problems) != 4 {
		t.Errorf("expected 4 complaints, got %d: %v", len(problems), problems)
	}

	if len(schedule.Jobs) != 1 || schedule.Jobs[0].ID != 1 {
		t.Errorf("only the good job should survive, got %v", schedule.Jobs)
	}
}

// A dependency cycle would otherwise wedge both jobs until the next refresh,
// which reads in a log exactly like a hung worker.
func TestDependencyCyclesAreBroken(t *testing.T) {
	schedule, err := ParseSchedule([]byte(`{
		"refresh": 60,
		"jobs": [
			{"id": 1, "command": "a", "type": "cron", "cron": "* * * * *", "depends_on": 2},
			{"id": 2, "command": "b", "type": "cron", "cron": "* * * * *", "depends_on": 1}
		]
	}`))

	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if problems := schedule.Prepare(); len(problems) == 0 {
		t.Fatal("a cycle should be complained about")
	}

	// Only as much is broken as the cycle needs: once one link is cut the
	// rest is a valid ordering and is left alone. What must be true is that
	// following the chain from anywhere now terminates.
	assertNoCycles(t, schedule)
}

// Following depends_on from every job must end rather than go round.
func assertNoCycles(t *testing.T, schedule *Schedule) {
	t.Helper()

	byID := map[int]*Job{}
	for i := range schedule.Jobs {
		byID[schedule.Jobs[i].ID] = &schedule.Jobs[i]
	}

	for i := range schedule.Jobs {
		job := &schedule.Jobs[i]
		seen := map[int]bool{job.ID: true}

		for current := job; current.DependsOn != nil; {
			next, exists := byID[*current.DependsOn]
			if !exists {
				break
			}
			if seen[next.ID] {
				t.Fatalf("job %d is still in a dependency cycle", job.ID)
			}
			seen[next.ID] = true
			current = next
		}
	}
}

// A longer cycle is broken too, not just a pair.
func TestLongerDependencyCyclesAreBroken(t *testing.T) {
	schedule, err := ParseSchedule([]byte(`{
		"refresh": 60,
		"jobs": [
			{"id": 1, "command": "a", "type": "cron", "cron": "* * * * *", "depends_on": 2},
			{"id": 2, "command": "b", "type": "cron", "cron": "* * * * *", "depends_on": 3},
			{"id": 3, "command": "c", "type": "cron", "cron": "* * * * *", "depends_on": 1}
		]
	}`))

	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if problems := schedule.Prepare(); len(problems) == 0 {
		t.Fatal("a cycle should be complained about")
	}

	assertNoCycles(t, schedule)
}

// Depending on a job that belongs to another server is normal, not an error.
func TestDependencyOnAnUnknownJobIsKept(t *testing.T) {
	schedule, _ := ParseSchedule([]byte(`{
		"refresh": 60,
		"jobs": [{"id": 1, "command": "a", "type": "cron", "cron": "* * * * *", "depends_on": 99}]
	}`))

	if problems := schedule.Prepare(); len(problems) != 0 {
		t.Errorf("unexpected complaints: %v", problems)
	}

	if schedule.Jobs[0].DependsOn == nil {
		t.Error("the dependency should be left alone")
	}
}

func TestMissingRefreshGetsADefault(t *testing.T) {
	schedule, _ := ParseSchedule([]byte(`{"jobs": []}`))
	schedule.Prepare()

	if schedule.Refresh != 600 {
		t.Errorf("got %d, want 600", schedule.Refresh)
	}
}

func TestRejectsRubbish(t *testing.T) {
	if _, err := ParseSchedule([]byte("not json")); err == nil {
		t.Error("should not have parsed")
	}
}
