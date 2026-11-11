//go:build !unix

package main

import (
	"os/exec"
	"time"
)

// superviseProcessGroup is the fallback for platforms without process groups:
// the command is killed outright, and its children are left to themselves.
//
// The worker is meant for Linux servers; this exists so the package still
// builds and vets everywhere.
func superviseProcessGroup(cmd *exec.Cmd) func() {
	cmd.WaitDelay = 5 * time.Second

	return func() {}
}
