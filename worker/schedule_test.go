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

	if schedule.Server != "web-01" {
		t.Errorf("server: got %q", schedule.Server)
	}

	if schedule.Refresh != 600 {
		t.Errorf("refresh: got %d", schedule.Refresh)
	}

	if len(schedule.Jobs) != 8 {
		t.Fatalf("jobs: got %d, want 8", len(schedule.Jobs))
	}

	interval := schedule.Jobs[7]

	if interval.Type != typeInterval || interval.Every != 25 || interval.Offset != 4 {
		t.Errorf("interval job: got type %q every %d offset %d", interval.Type, interval.Every, interval.Offset)
	}

	first := schedule.Jobs[0]

	if first.Command != "advancedqueue:queue:process mail" {
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

// The worker does not trust the payload's refresh: a zero is a context that
// expires instantly, and the loop would ask Drupal for its schedule as fast
// as Drush can answer.
func TestRefreshPeriod(t *testing.T) {
	cases := []struct {
		name    string
		refresh int
		want    time.Duration
	}{
		{"absent", 0, DefaultRefresh},
		{"negative", -5, DefaultRefresh},
		{"below the floor", 2, MinimumRefresh},
		{"exactly the floor", 60, MinimumRefresh},
		{"a normal value", 300, 5 * time.Minute},
		{"the default", 1800, 30 * time.Minute},
	}

	for _, c := range cases {
		schedule := &Schedule{Refresh: c.refresh}

		if got := schedule.RefreshPeriod(); got != c.want {
			t.Errorf("%s: got %s, want %s", c.name, got, c.want)
		}
	}
}

// Boundaries are anchored to the epoch rather than to the worker's start, so
// the same job lands on the same seconds on every server however each booted.
func TestIntervalBoundariesAreAbsolute(t *testing.T) {
	job := Job{Type: typeInterval, Every: 30}

	for _, when := range []int64{1200, 1210, 1229} {
		if got := job.IntervalBoundary(time.Unix(when, 0)); got != 1200 {
			t.Errorf("at %d expected boundary 1200, got %d", when, got)
		}
	}

	if got := job.IntervalBoundary(time.Unix(1230, 0)); got != 1230 {
		t.Errorf("expected the next boundary at 1230, got %d", got)
	}
}

// Offset is what keeps the same queue processor on three servers out of the
// same second, so it has to move the boundaries rather than the first run.
func TestOffsetShiftsTheBoundaries(t *testing.T) {
	job := Job{Type: typeInterval, Every: 30, Offset: 4}

	if got := job.IntervalBoundary(time.Unix(1233, 0)); got != 1204 {
		t.Errorf("expected boundary 1204, got %d", got)
	}

	if got := job.IntervalBoundary(time.Unix(1234, 0)); got != 1234 {
		t.Errorf("expected boundary 1234, got %d", got)
	}

	// An offset longer than the period is the same as its remainder, rather
	// than a job that is never due.
	long := Job{Type: typeInterval, Every: 30, Offset: 64}
	if long.IntervalBoundary(time.Unix(1234, 0)) != job.IntervalBoundary(time.Unix(1234, 0)) {
		t.Error("an offset beyond the period should wrap")
	}
}

// An interval the worker cannot honour is dropped with a reason, the way an
// unreadable cron expression is, rather than failing the whole schedule.
func TestPrepareRejectsUnusableIntervals(t *testing.T) {
	schedule := &Schedule{
		Refresh: 60,
		Jobs: []Job{
			{ID: 1, Name: "too often", Command: "cron", Runner: runnerDrush, Type: typeInterval, Every: 1},
			{ID: 2, Name: "negative offset", Command: "cron", Runner: runnerDrush, Type: typeInterval, Every: 30, Offset: -1},
			{ID: 3, Name: "fine", Command: "cron", Runner: runnerDrush, Type: typeInterval, Every: 30},
		},
	}

	problems := schedule.Prepare()

	if len(problems) != 2 {
		t.Fatalf("expected two problems, got %d: %v", len(problems), problems)
	}

	if len(schedule.Jobs) != 1 || schedule.Jobs[0].ID != 3 {
		t.Errorf("expected only the usable job to survive, got %v", schedule.Jobs)
	}
}
