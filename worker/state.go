package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"sync"
)

// State remembers which one-time jobs have already been run.
//
// Without it, a worker restart re-runs every one-time job whose moment has
// passed, which for a data migration is considerably worse than not running
// it at all. Recurring jobs need no memory: their schedule says when.
type State struct {
	path string

	mu   sync.Mutex
	Done map[int]int64 `json:"done"`
}

// LoadState reads the state file, starting empty when there is not one yet.
func LoadState(path string) *State {
	state := &State{path: path, Done: map[int]int64{}}

	if path == "" {
		return state
	}

	payload, err := os.ReadFile(path)
	if err != nil {
		return state
	}

	var stored struct {
		Done map[int]int64 `json:"done"`
	}

	if err := json.Unmarshal(payload, &stored); err == nil && stored.Done != nil {
		state.Done = stored.Done
	}

	return state
}

// Completed reports whether a one-time job has already been run.
func (s *State) Completed(id int) bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	_, done := s.Done[id]

	return done
}

// Complete records a one-time job as run, and writes the file.
func (s *State) Complete(id int, when int64) error {
	s.mu.Lock()
	s.Done[id] = when
	snapshot := make(map[int]int64, len(s.Done))
	for key, value := range s.Done {
		snapshot[key] = value
	}
	path := s.path
	s.mu.Unlock()

	if path == "" {
		return nil
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}

	payload, err := json.Marshal(struct {
		Done map[int]int64 `json:"done"`
	}{Done: snapshot})

	if err != nil {
		return err
	}

	// Written to a temporary file and moved into place, so a worker killed
	// mid-write leaves the previous state rather than an empty file that
	// would re-run every one-time job.
	temporary := path + ".tmp"

	if err := os.WriteFile(temporary, payload, 0o644); err != nil {
		return err
	}

	return os.Rename(temporary, path)
}

// DefaultStatePath is where completions are remembered unless told otherwise.
func DefaultStatePath() string {
	if dir, err := os.UserCacheDir(); err == nil {
		return filepath.Join(dir, "druker", "state.json")
	}

	return filepath.Join(os.TempDir(), "druker-state.json")
}
