//go:build unix

package main

import (
	"os/exec"
	"syscall"
	"time"
)

// superviseProcessGroup puts the command in its own process group and kills
// the whole group when the context ends.
//
// Without the group, a timed-out job leaves its children running and the
// worker blocked reading a pipe none of them will close. WaitDelay is the
// backstop for a child that ignores the signal.
func superviseProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}

	cmd.Cancel = func() error {
		if cmd.Process == nil {
			return nil
		}

		// The negative PID is the process group.
		return syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL)
	}

	cmd.WaitDelay = 5 * time.Second
}
