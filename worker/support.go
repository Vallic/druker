package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os/exec"
	"strings"
	"time"
)

// The log formats -log-format accepts.
const (
	logFormatText = "text"
	logFormatJSON = "json"
)

// contextWithTimeout is a timeout context, or a plain one when timeout is 0.
func contextWithTimeout(timeout time.Duration) (context.Context, context.CancelFunc) {
	if timeout <= 0 {
		return context.WithCancel(context.Background())
	}

	return context.WithTimeout(context.Background(), timeout)
}

// asExitError is errors.As, named for readability at the call site.
func asExitError(err error, target **exec.ExitError) bool {
	return errors.As(err, target)
}

// trimForLog collapses output to something that fits on a log line.
func trimForLog(text string) string {
	text = strings.TrimSpace(strings.ReplaceAll(text, "\n", " "))

	if len(text) <= 300 {
		return text
	}

	return text[:299] + "…"
}

// SplitCommand splits a command line, respecting single and double quotes.
//
// Splitting on spaces alone breaks the moment an argument contains one, which
// a Drush command with a --filter or a message very easily does.
func SplitCommand(command string) []string {
	var (
		args    []string
		current strings.Builder
		quote   rune
		escaped bool
		started bool
	)

	for _, r := range command {
		switch {
		case escaped:
			current.WriteRune(r)
			escaped = false
		case r == '\\' && quote != '\'':
			escaped = true
		case quote != 0:
			if r == quote {
				quote = 0
			} else {
				current.WriteRune(r)
			}
		case r == '\'' || r == '"':
			quote = r
			started = true
		case r == ' ' || r == '\t':
			if started || current.Len() > 0 {
				args = append(args, current.String())
				current.Reset()
				started = false
			}
		default:
			current.WriteRune(r)
		}
	}

	if started || current.Len() > 0 {
		args = append(args, current.String())
	}

	return args
}

// newLogHandler builds the log handler for a format name.
//
// Text is what someone tailing journalctl wants to read; JSON is what a log
// shipper wants to parse. Both carry the same keys, so a query written against
// one holds for the other.
func newLogHandler(format string, level slog.Level, out io.Writer) (slog.Handler, error) {
	options := &slog.HandlerOptions{Level: level}

	switch format {
	case "", logFormatText:
		return slog.NewTextHandler(out, options), nil

	case logFormatJSON:
		return slog.NewJSONHandler(out, options), nil
	}

	return nil, fmt.Errorf("unknown log format %q, expected %s or %s", format, logFormatText, logFormatJSON)
}
