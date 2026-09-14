package main

import (
	"bytes"
	"encoding/json"
	"io"
	"log/slog"
	"reflect"
	"strings"
	"testing"
)

// Splitting on spaces alone breaks any Drush command carrying a quoted
// argument, which is most of the interesting ones.
func TestSplitCommand(t *testing.T) {
	cases := []struct {
		command string
		want    []string
	}{
		{"core:status", []string{"core:status"}},
		{"advancedqueue:queue:process mail", []string{"advancedqueue:queue:process", "mail"}},
		{"  spaced   out  ", []string{"spaced", "out"}},
		{`sql:query "SELECT 1 FROM node"`, []string{"sql:query", "SELECT 1 FROM node"}},
		{`msg --text='hello world'`, []string{"msg", "--text=hello world"}},
		{`a "" b`, []string{"a", "", "b"}},
		{`path --dir=/tmp/a\ b`, []string{"path", "--dir=/tmp/a b"}},
		{"", nil},
	}

	for _, c := range cases {
		if got := SplitCommand(c.command); !reflect.DeepEqual(got, c.want) {
			t.Errorf("%q: got %#v, want %#v", c.command, got, c.want)
		}
	}
}

func TestTrimForLog(t *testing.T) {
	if got := trimForLog(" one\ntwo\n"); got != "one two" {
		t.Errorf("got %q", got)
	}

	long := make([]byte, 500)
	for i := range long {
		long[i] = 'x'
	}

	if got := trimForLog(string(long)); len([]rune(got)) != 300 {
		t.Errorf("got %d runes, want 300", len([]rune(got)))
	}
}

// The default, and an explicitly named text format, both log logfmt.
func TestNewLogHandlerText(t *testing.T) {
	for _, format := range []string{"", "text"} {
		var out bytes.Buffer

		handler, err := newLogHandler(format, slog.LevelInfo, &out)
		if err != nil {
			t.Fatalf("format %q: unexpected error: %v", format, err)
		}

		slog.New(handler).Info("started", "job", "Index users (#70)")
		line := out.String()

		if !strings.Contains(line, `msg=started`) || !strings.Contains(line, `job="Index users (#70)"`) {
			t.Errorf("format %q: not logfmt: %s", format, line)
		}
	}
}

// JSON is what a log shipper parses, so every line must decode on its own.
func TestNewLogHandlerJSON(t *testing.T) {
	var out bytes.Buffer

	handler, err := newLogHandler("json", slog.LevelInfo, &out)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	slog.New(handler).Info("started", "job", "Index users (#70)")

	var line map[string]any
	if err := json.Unmarshal(out.Bytes(), &line); err != nil {
		t.Fatalf("not valid JSON: %v: %s", err, out.String())
	}

	// The keys are the same as the text handler's, so a query written against
	// one format still finds things in the other.
	if line["msg"] != "started" || line["job"] != "Index users (#70)" {
		t.Errorf("keys did not survive: %v", line)
	}
}

// A misspelt format is a startup mistake worth stopping for, not something to
// quietly paper over with the default.
func TestNewLogHandlerRejectsUnknownFormat(t *testing.T) {
	if _, err := newLogHandler("logfmt", slog.LevelInfo, io.Discard); err == nil {
		t.Fatal("expected an error for an unknown format")
	}
}

// -verbose is the only thing that decides the level, in either format.
func TestNewLogHandlerRespectsLevel(t *testing.T) {
	var out bytes.Buffer

	handler, err := newLogHandler("json", slog.LevelInfo, &out)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	slog.New(handler).Debug("minute evaluated")

	if out.Len() != 0 {
		t.Errorf("debug logged below the level: %s", out.String())
	}
}
