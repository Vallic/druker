package main

import (
	"log/slog"
	"os"
	"path/filepath"
	"strconv"
	"time"
)

// wakeExpiry is how long a signal for a job this worker does not run is left
// for the worker that does, before it is taken as nobody's and removed. A
// disabled or deleted job, or one whose server is gone, would otherwise leave
// its signal in the directory for good.
const wakeExpiry = time.Hour

// maximumWakeDelay is the longest delay a signal can ask for. Matches
// \Drupal\druker\Wake::MAXIMUM_DELAY.
const maximumWakeDelay = time.Hour

// claimWakes is the jobs the site has asked to run now, since the last tick.
//
// The site asks by touching a file named after the job's id in the wake
// directory (\Drupal\druker\Wake). Each tick lists the directory, which costs
// a directory read and nothing else: no Drush, no bootstrap, so a woken job
// starts within a second.
//
// A signal is claimed by removing its file, and only the worker whose remove
// succeeds runs the job. Several workers can share a directory, then, and a
// job they all run is run once per signal, by whichever got there first. A
// signal for a job this worker does not run is left for the one that does.
//
// A woken job still running from before keeps its signal: what woke it may
// have arrived after that run looked, so it runs again once the run ends,
// rather than being refused as an overlap and the signal lost. Several
// signals before then are one run, as they would be one file.
func claimWakes(dir string, schedule *Schedule, runner *Runner, state *State, logger *slog.Logger, now time.Time) []Job {
	if dir == "" {
		return nil
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		// Most often the directory does not exist yet: the site creates it
		// with the first signal.
		return nil
	}

	jobs := make(map[int]Job, len(schedule.Jobs))
	for _, job := range schedule.Jobs {
		jobs[job.ID] = job
	}

	var woken []Job

	for _, entry := range entries {
		id, err := strconv.Atoi(entry.Name())
		if err != nil || id <= 0 || entry.IsDir() {
			continue
		}

		path := filepath.Join(dir, entry.Name())
		job, ours := jobs[id]

		if !ours {
			if info, err := entry.Info(); err == nil && now.Sub(info.ModTime()) > wakeExpiry {
				if os.Remove(path) == nil {
					logger.Info("removed a wake signal no worker claimed", "job", id)
				}
			}

			continue
		}

		if job.Type == typeOnce && state.Completed(job.ID) {
			_ = os.Remove(path)
			continue
		}

		// A signal dated in the future asked for a delay (\Drupal\druker\Wake),
		// and waits for its time. One dated further ahead than any delay
		// can ask for was dated by a clock that is wrong, and is now.
		if info, err := entry.Info(); err == nil && info.ModTime().After(now) && info.ModTime().Sub(now) <= maximumWakeDelay {
			continue
		}

		if runner.Running(job.ID) && !job.Overlap {
			continue
		}

		if os.Remove(path) != nil {
			// Another worker sharing the directory claimed it first.
			continue
		}

		logger.Info("woken", "job", job.Label())
		woken = append(woken, job)
	}

	return woken
}

// withWoken adds the woken jobs to those already due, once each.
func withWoken(due []Job, woken []Job) []Job {
	if len(woken) == 0 {
		return due
	}

	seen := make(map[int]bool, len(due))
	for _, job := range due {
		seen[job.ID] = true
	}

	for _, job := range woken {
		if !seen[job.ID] {
			seen[job.ID] = true
			due = append(due, job)
		}
	}

	return due
}
