package main

import (
	"os"
	"path/filepath"
	"testing"
)

// A worker restart must not re-run a one-time job. For a data migration that
// is considerably worse than never having run it.
func TestCompletionsSurviveARestart(t *testing.T) {
	path := filepath.Join(t.TempDir(), "nested", "state.json")

	state := LoadState(path)

	if state.Completed(7) {
		t.Error("nothing should be complete yet")
	}

	if err := state.Complete(7, 1747392000); err != nil {
		t.Fatalf("cannot write state: %v", err)
	}

	// What a restart sees.
	reloaded := LoadState(path)

	if !reloaded.Completed(7) {
		t.Error("the completion should have survived")
	}
	if reloaded.Completed(8) {
		t.Error("an unrelated job should not be marked complete")
	}
}

func TestMissingStateFileStartsEmpty(t *testing.T) {
	state := LoadState(filepath.Join(t.TempDir(), "absent.json"))

	if state.Completed(1) {
		t.Error("an absent file means nothing has run")
	}
}

// A truncated or hand-edited file must not stop the worker starting.
func TestCorruptStateIsIgnored(t *testing.T) {
	path := filepath.Join(t.TempDir(), "state.json")

	if err := os.WriteFile(path, []byte("{not json"), 0o644); err != nil {
		t.Fatal(err)
	}

	state := LoadState(path)

	if state.Completed(1) {
		t.Error("a corrupt file should read as empty")
	}

	if err := state.Complete(1, 1); err != nil {
		t.Errorf("and should still be writable: %v", err)
	}
}

// Without a path the worker still runs, it just forgets.
func TestStateCanBeDisabled(t *testing.T) {
	state := LoadState("")

	if err := state.Complete(1, 1); err != nil {
		t.Errorf("unexpected error: %v", err)
	}

	if !state.Completed(1) {
		t.Error("it should still remember within the process")
	}
}
