package main

import (
	"encoding/json"
	"fmt"
	"os/exec"
	"time"
)

// Job is one entry of the schedule, exactly as Drupal emits it.
//
// The shape is owned by \Drupal\druker\JobManager::formatJob(). Anything
// changed here has to change there, and the round trip is covered by
// TestParsesTheDrupalSample against examples/sample-output.json.
type Job struct {
	ID        int    `json:"id"`
	Name      string `json:"name"`
	Command   string `json:"command"`
	Runner    string `json:"runner"`
	Async     bool   `json:"async"`
	DependsOn *int   `json:"depends_on"`
	Type      string `json:"type"`
	Cron      string `json:"cron"`
	RunAt     int64  `json:"run_at"`
	Every     int    `json:"every"`
	Offset    int    `json:"offset"`

	// Filled in by Prepare, not by Drupal.
	schedule *CronSchedule
}

// Schedule is the whole payload for one server.
type Schedule struct {
	Server  string `json:"server"`
	Refresh int    `json:"refresh"`
	Jobs    []Job  `json:"jobs"`
}

const (
	// DefaultRefresh is used when the payload does not say how long to wait.
	// Matches CollectJobsEvent::DEFAULT_REFRESH on the Drupal side.
	DefaultRefresh = 30 * time.Minute
)

// MinimumRefresh is the shortest period the worker will honour, whatever it
// is told. Drupal clamps to the same floor, so this only catches a payload
// from an older site or one written by hand — but a zero here means a context
// that expires instantly and a loop that asks Drupal for its schedule as fast
// as Drush can answer.
//
// A variable rather than a constant only so the tests covering the supervise
// loop can shorten it; nothing at runtime writes to it.
var MinimumRefresh = time.Minute

// MinimumInterval is the shortest period an interval job may repeat at.
//
// The tick is a second, so the mechanism would allow one. Every run is a
// process and a full Drupal bootstrap, though, and a job that takes longer
// than its own interval is skipped rather than queued — so anything under
// this is a job that mostly reports being skipped. Drupal validates against
// the same floor, in CollectJobsEvent::MINIMUM_INTERVAL.
const MinimumInterval = 5

const (
	typeCron     = "cron"
	typeOnce     = "once"
	typeInterval = "interval"

	// runnerDrush passes the command to Drush as arguments.
	runnerDrush = "drush"

	// runnerShell passes the whole command line to a shell, so pipes and
	// redirection work. Drupal only ever sends these when the site has opted
	// in, in settings.php; the worker has no way to check that itself, which
	// is exactly why the gate lives on the Drupal side.
	runnerShell = "shell"
)

// Label is what the job is called in the log.
func (j *Job) Label() string {
	if j.Name != "" {
		return fmt.Sprintf("%s (#%d)", j.Name, j.ID)
	}

	return fmt.Sprintf("#%d", j.ID)
}

// Due reports whether a recurring job should start in the minute of t.
func (j *Job) Due(t time.Time) bool {
	return j.Type == typeCron && j.schedule != nil && j.schedule.Matches(t)
}

// DueOnce reports whether a one-time job has reached its moment.
func (j *Job) DueOnce(t time.Time) bool {
	return j.Type == typeOnce && j.RunAt > 0 && t.Unix() >= j.RunAt
}

// DueInterval reports whether an interval job has reached a new boundary
// since it last started, where last is the boundary it started at.
//
// Not "has `every` seconds passed since the last run": that measures from
// whenever the job happened to start, so a worker restart moves every job's
// schedule, and two servers running the same job drift into each other rather
// than staying apart. Boundaries are absolute instead — see IntervalBoundary
// — which keeps a job on the same seconds across a restart and makes Offset
// mean something between servers.
//
// A tick the worker misses under load does not lose the run. The boundary it
// belonged to is still newer than the last one recorded, so the job starts on
// the next tick, late rather than skipped.
func (j *Job) DueInterval(t time.Time, last int64) bool {
	if j.Type != typeInterval || j.Every <= 0 {
		return false
	}

	return j.IntervalBoundary(t) > last
}

// IntervalBoundary is the most recent moment this job was due, at or before t.
//
// Anchored to the Unix epoch rather than to the worker's start, so a job that
// repeats every 30 seconds runs at :00 and :30 of every minute on every
// server, whenever each of them happened to boot. Offset shifts those
// boundaries forward, which is how the same queue processor on three servers
// is kept from starting in the same second on all three.
func (j *Job) IntervalBoundary(t time.Time) int64 {
	every := int64(j.Every)

	if every <= 0 {
		return 0
	}

	now := t.Unix()
	offset := int64(j.Offset) % every

	// Go's % takes the sign of the dividend. A clock before the epoch is not
	// a real case, but a negative remainder here would put the boundary in
	// the future and the job would never be due again.
	remainder := ((now-offset)%every + every) % every

	return now - remainder
}

// Prepare validates the payload and parses the cron expressions in it.
//
// Jobs that cannot be understood are dropped rather than failing the whole
// schedule: one mistyped expression must not stop every other job on the
// server from running.
func (s *Schedule) Prepare() []error {
	var problems []error
	kept := make([]Job, 0, len(s.Jobs))

	for _, job := range s.Jobs {
		if job.Command == "" {
			problems = append(problems, fmt.Errorf("job %s has no command", job.Label()))
			continue
		}

		// An older Drupal side sends no runner at all, and meant Drush.
		if job.Runner == "" {
			job.Runner = runnerDrush
		}

		if job.Runner != runnerDrush && job.Runner != runnerShell {
			problems = append(problems, fmt.Errorf("job %s has unknown runner %q", job.Label(), job.Runner))
			continue
		}

		switch job.Type {
		case typeCron:
			schedule, err := ParseCron(job.Cron)
			if err != nil {
				problems = append(problems, fmt.Errorf("job %s: %w", job.Label(), err))
				continue
			}
			job.schedule = schedule

		case typeOnce:
			if job.RunAt <= 0 {
				problems = append(problems, fmt.Errorf("job %s is a one-time job with no run_at", job.Label()))
				continue
			}

		case typeInterval:
			if job.Every < MinimumInterval {
				problems = append(problems, fmt.Errorf("job %s repeats every %ds, and the shortest allowed is %ds", job.Label(), job.Every, MinimumInterval))
				continue
			}

			if job.Offset < 0 {
				problems = append(problems, fmt.Errorf("job %s has a negative offset of %ds", job.Label(), job.Offset))
				continue
			}

		default:
			problems = append(problems, fmt.Errorf("job %s has unknown type %q", job.Label(), job.Type))
			continue
		}

		kept = append(kept, job)
	}

	s.Jobs = kept

	if s.Refresh <= 0 {
		s.Refresh = 600
	}

	problems = append(problems, s.breakDependencyCycles()...)

	return problems
}

// breakDependencyCycles drops a dependency that would make a job wait on
// itself, directly or through a chain.
//
// A cycle would otherwise deadlock both jobs until the refresh, which looks
// exactly like a hung worker and is miserable to diagnose from a log.
func (s *Schedule) breakDependencyCycles() []error {
	var problems []error

	byID := make(map[int]*Job, len(s.Jobs))
	for i := range s.Jobs {
		byID[s.Jobs[i].ID] = &s.Jobs[i]
	}

	for i := range s.Jobs {
		job := &s.Jobs[i]
		seen := map[int]bool{job.ID: true}

		for current := job; current.DependsOn != nil; {
			next, exists := byID[*current.DependsOn]

			if !exists {
				// Depending on a job this server does not run is not an
				// error: the job may belong to another server.
				break
			}

			if seen[next.ID] {
				problems = append(problems, fmt.Errorf("job %s depends on itself through a cycle; the dependency is ignored", job.Label()))
				job.DependsOn = nil
				break
			}

			seen[next.ID] = true
			current = next
		}
	}

	return problems
}

// FetchSchedule asks Drupal what this server should be running.
func FetchSchedule(drush string, hostname string, timeout time.Duration) (*Schedule, error) {
	ctx, cancel := contextWithTimeout(timeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, drush, "druker:jobs", hostname)
	output, err := cmd.Output()

	if err != nil {
		var exitErr *exec.ExitError
		if asExitError(err, &exitErr) && len(exitErr.Stderr) > 0 {
			return nil, fmt.Errorf("drush failed: %w: %s", err, trimForLog(string(exitErr.Stderr)))
		}

		return nil, fmt.Errorf("drush failed: %w", err)
	}

	return ParseSchedule(output)
}

// ParseSchedule reads a schedule payload.
func ParseSchedule(payload []byte) (*Schedule, error) {
	var schedule Schedule

	if err := json.Unmarshal(payload, &schedule); err != nil {
		return nil, fmt.Errorf("cannot parse the schedule: %w: %s", err, trimForLog(string(payload)))
	}

	return &schedule, nil
}

// RefreshPeriod is how long to wait before asking for the schedule again.
func (s *Schedule) RefreshPeriod() time.Duration {
	if s.Refresh <= 0 {
		return DefaultRefresh
	}

	if refresh := time.Duration(s.Refresh) * time.Second; refresh > MinimumRefresh {
		return refresh
	}

	return MinimumRefresh
}
