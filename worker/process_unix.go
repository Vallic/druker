//go:build unix

package main

import (
	"os/exec"
	"sync"
	"syscall"
	"time"
)

// superviseProcessGroup puts the command in its own process group and stops
// the whole group when the context ends: SIGTERM first, SIGKILL killAfter
// later for whatever is still there.
//
// Without the group, a stopped job leaves its children running and the
// worker blocked reading a pipe none of them will close. SIGTERM first because
// Drush finishes the item it is on and releases its locks when asked; SIGKILL
// leaves an Advanced Queue item in `processing` until its lease runs out.
// WaitDelay is the backstop for a child that ignores both.
//
// The returned function is called once the command has been waited for. When
// the job was stopped it sweeps the group, so a child that trapped SIGTERM
// does not outlive a leader that exited.
func superviseProcessGroup(cmd *exec.Cmd) func() {
	var (
		mu       sync.Mutex
		escalate *time.Timer
	)

	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}

		// The negative PID is the process group.
		group := -cmd.Process.Pid

		mu.Lock()
		escalate = time.AfterFunc(killAfter, func() {
			_ = syscall.Kill(group, syscall.SIGKILL)
		})
		mu.Unlock()

		return syscall.Kill(group, syscall.SIGTERM)
	}

	cmd.WaitDelay = killAfter + 5*time.Second

	return func() {
		mu.Lock()
		defer mu.Unlock()

		if escalate == nil || cmd.Process == nil {
			return
		}

		escalate.Stop()
		_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}
}
