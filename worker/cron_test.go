package main

import (
	"testing"
	"time"
)

func at(value string) time.Time {
	t, err := time.Parse("2006-01-02 15:04", value)
	if err != nil {
		panic(err)
	}
	return t
}

func TestCronMatching(t *testing.T) {
	cases := []struct {
		expression string
		moment     string
		want       bool
	}{
		{"* * * * *", "2026-09-13 12:34", true},
		{"0 2 * * *", "2026-09-13 02:00", true},
		{"0 2 * * *", "2026-09-13 02:01", false},
		{"0 2 * * *", "2026-09-13 03:00", false},
		{"*/5 * * * *", "2026-09-13 12:35", true},
		{"*/5 * * * *", "2026-09-13 12:36", false},
		{"0 */3 * * *", "2026-09-13 06:00", true},
		{"0 */3 * * *", "2026-09-13 07:00", false},
		{"0 0 * * 0", "2026-09-13 00:00", true},  // A Sunday.
		{"0 0 * * 7", "2026-09-13 00:00", true},  // Sunday is 0 and 7 both.
		{"0 0 * * 1", "2026-09-13 00:00", false}, // Not a Monday.
		{"15,45 * * * *", "2026-09-13 09:45", true},
		{"15,45 * * * *", "2026-09-13 09:30", false},
		{"0 9-17 * * *", "2026-09-13 09:00", true},
		{"0 9-17 * * *", "2026-09-13 18:00", false},
		{"0 0 1 * *", "2026-09-01 00:00", true},
		{"0 0 1 * *", "2026-09-02 00:00", false},
		{"0 0 1 1 *", "2026-01-01 00:00", true},
		{"0 0 1 1 *", "2026-02-01 00:00", false},
	}

	for _, c := range cases {
		schedule, err := ParseCron(c.expression)
		if err != nil {
			t.Fatalf("%q: unexpected error: %v", c.expression, err)
		}

		if got := schedule.Matches(at(c.moment)); got != c.want {
			t.Errorf("%q at %s: got %v, want %v", c.expression, c.moment, got, c.want)
		}
	}
}

// Restricting both day-of-month and day-of-week is an OR, not an AND. Cron has
// worked this way since the 1970s and almost every reimplementation gets it
// backwards, which turns "the 13th, and Fridays" into "Friday the 13th".
func TestDayAndWeekdayAreOredTogether(t *testing.T) {
	schedule, err := ParseCron("0 0 13 * 5")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if !schedule.Matches(at("2026-11-13 00:00")) {
		t.Error("the 13th should match even though it is a Friday")
	}
	if !schedule.Matches(at("2026-09-11 00:00")) {
		t.Error("a Friday should match even though it is not the 13th")
	}
	if !schedule.Matches(at("2026-09-04 00:00")) {
		t.Error("another Friday should match")
	}
	if schedule.Matches(at("2026-09-10 00:00")) {
		t.Error("a Thursday that is not the 13th should not match")
	}
}

func TestRejectsNonsense(t *testing.T) {
	for _, expression := range []string{
		"",
		"* * * *",
		"* * * * * *",
		"60 * * * *",
		"* 24 * * *",
		"* * 0 * *",
		"* * * 13 *",
		"* * * * 8",
		"abc * * * *",
		"*/0 * * * *",
		"5-1 * * * *",
	} {
		if _, err := ParseCron(expression); err == nil {
			t.Errorf("%q should not have parsed", expression)
		}
	}
}
