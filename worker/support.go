package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"os/exec"
	"regexp"
	"strings"
	"time"
)

// The log formats -log-format accepts.
const (
	logFormatText = "text"
	logFormatJSON = "json"
)

// What a secret is called, in an argument name or an assignment.
//
// Deliberately broad: matching a harmless argument costs a reader one value
// they could have seen anyway, while missing one writes a live credential to
// the log of every server, once per run, for as long as the job exists.
const secretNames = `(?:pass(?:word|wd|phrase)?|secret|token|api[-_]?key|access[-_]?key|private[-_]?key|credentials?|auth)`

// redactions are applied in order to anything the log might carry.
//
// Order matters. The specific shapes go first: "Authorization: Bearer abc"
// matches the generic name/value rule on the header name, and that rule would
// hide the word "Bearer" and leave the token sitting there in the clear.
var redactions = []struct {
	pattern     *regexp.Regexp
	replacement string
}{
	// Authorization: Bearer abc123..., in output echoing a request.
	{
		regexp.MustCompile(`(?i)\b(bearer\s+)[\w.~+/=-]{8,}`),
		`${1}***`,
	},
	// mysql://user:hunter2@host. Only the password goes: which host a job
	// could not reach is the first thing anyone debugging it wants.
	{
		regexp.MustCompile(`([a-zA-Z][\w+.-]*://[^\s:/@]+):([^\s@/]+)@`),
		`${1}:***@`,
	},
	// --password=hunter2, PGPASSWORD=hunter2, "token": "hunter2".
	{
		regexp.MustCompile(`(?i)([\w.-]*` + secretNames + `[\w.-]*"?\s*[=:]\s*)("[^"]*"|'[^']*'|\S+)`),
		`${1}***`,
	},
	// --password hunter2. Only the long form: a bare -p could be anything,
	// and the value must not itself look like the next argument.
	{
		regexp.MustCompile(`(?i)(--[\w.-]*` + secretNames + `[\w.-]*\s+)("[^"]*"|'[^']*'|[^-\s]\S*)`),
		`${1}***`,
	},
}

// repeatedRedactions collapses the "*** ***" left when two rules both fire on
// the same value, as they do on "Authorization: Bearer abc".
var repeatedRedactions = regexp.MustCompile(`\*\*\*(?:\s+\*\*\*)+`)

// Redact hides anything that looks like a credential.
//
// Job commands are written by whoever can edit the schedule, and job output is
// whatever the job felt like printing, so neither can be assumed clean. The
// log is the one place both end up, so it is the one place worth filtering.
func Redact(text string) string {
	for _, rule := range redactions {
		text = rule.pattern.ReplaceAllString(text, rule.replacement)
	}

	return repeatedRedactions.ReplaceAllString(text, "***")
}

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
	text = Redact(strings.TrimSpace(strings.ReplaceAll(text, "\n", " ")))

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
