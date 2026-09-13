package main

import (
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Schedule is a parsed five-field cron expression.
//
// Hand-written rather than pulled from a library on purpose: the worker ships
// as a single static binary onto servers that may have no proxy to a module
// proxy, and one small matcher is cheaper to trust than a dependency tree.
type CronSchedule struct {
	minutes  [60]bool
	hours    [24]bool
	days     [32]bool
	months   [13]bool
	weekdays [7]bool

	// A cron expression restricted on both day-of-month and day-of-week
	// matches either, not both. This is the rule everyone gets wrong.
	dayRestricted     bool
	weekdayRestricted bool
}

// ParseCron reads a five-field cron expression.
//
// Each field takes "*", a number, a range "a-b", a step "*/n" or "a-b/n", and
// any comma-separated mixture of those. Day-of-week accepts 0-7 with both 0
// and 7 meaning Sunday.
func ParseCron(expression string) (*CronSchedule, error) {
	fields := strings.Fields(strings.TrimSpace(expression))

	if len(fields) != 5 {
		return nil, fmt.Errorf("expected 5 fields, got %d in %q", len(fields), expression)
	}

	schedule := &CronSchedule{}

	specs := []struct {
		field string
		min   int
		max   int
		into  []bool
	}{
		{fields[0], 0, 59, schedule.minutes[:]},
		{fields[1], 0, 23, schedule.hours[:]},
		{fields[2], 1, 31, schedule.days[:]},
		{fields[3], 1, 12, schedule.months[:]},
		{fields[4], 0, 7, schedule.weekdays[:]},
	}

	for i, spec := range specs {
		if err := parseField(spec.field, spec.min, spec.max, spec.into, i == 4); err != nil {
			return nil, fmt.Errorf("field %d (%q): %w", i+1, spec.field, err)
		}
	}

	schedule.dayRestricted = fields[2] != "*"
	schedule.weekdayRestricted = fields[4] != "*"

	return schedule, nil
}

// Matches reports whether the schedule fires in the minute of t.
func (s *CronSchedule) Matches(t time.Time) bool {
	if !s.minutes[t.Minute()] || !s.hours[t.Hour()] || !s.months[int(t.Month())] {
		return false
	}

	day := s.days[t.Day()]
	weekday := s.weekdays[int(t.Weekday())]

	// Both restricted means either may match, which is what cron does and is
	// the one rule worth writing a comment about: "0 0 13 * 5" is the 13th and
	// every Friday, not Friday the 13th.
	if s.dayRestricted && s.weekdayRestricted {
		return day || weekday
	}

	return day && weekday
}

// parseField fills the allowed values for one comma-separated cron field.
func parseField(field string, min, max int, into []bool, isWeekday bool) error {
	for _, part := range strings.Split(field, ",") {
		part = strings.TrimSpace(part)

		if part == "" {
			return fmt.Errorf("empty value")
		}

		step := 1
		if slash := strings.Index(part, "/"); slash != -1 {
			parsed, err := strconv.Atoi(part[slash+1:])
			if err != nil || parsed < 1 {
				return fmt.Errorf("bad step %q", part[slash+1:])
			}
			step = parsed
			part = part[:slash]
		}

		start, end := min, max

		switch {
		case part == "*":
			// The whole range, already set above.
		case strings.Contains(part, "-"):
			bounds := strings.SplitN(part, "-", 2)
			var err error
			if start, err = strconv.Atoi(strings.TrimSpace(bounds[0])); err != nil {
				return fmt.Errorf("bad range start %q", bounds[0])
			}
			if end, err = strconv.Atoi(strings.TrimSpace(bounds[1])); err != nil {
				return fmt.Errorf("bad range end %q", bounds[1])
			}
		default:
			value, err := strconv.Atoi(part)
			if err != nil {
				return fmt.Errorf("bad value %q", part)
			}
			start, end = value, value
		}

		if start < min || end > max || start > end {
			return fmt.Errorf("%d-%d is outside %d-%d", start, end, min, max)
		}

		for value := start; value <= end; value += step {
			index := value
			if isWeekday && index == 7 {
				// Sunday is both 0 and 7.
				index = 0
			}
			into[index] = true
		}
	}

	return nil
}
