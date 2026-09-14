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

// A command or a line of output must not carry a live credential into the log.
func TestRedact(t *testing.T) {
	cases := []struct {
		name string
		text string
		want string
	}{
		{
			"long flag with equals",
			"user:password admin --password=hunter2",
			"user:password admin --password=***",
		},
		{
			"quoted value",
			`migrate:import --token="ab cd" --limit=5`,
			`migrate:import --token=*** --limit=5`,
		},
		{
			"separate argument",
			"remote:sync --api-key hunter2 --verbose",
			"remote:sync --api-key *** --verbose",
		},
		{
			"environment assignment in output",
			"PGPASSWORD=hunter2 psql -c 'select 1'",
			"PGPASSWORD=*** psql -c 'select 1'",
		},
		{
			"json in output",
			`{"access_token": "abc123", "expires": 3600}`,
			`{"access_token": ***, "expires": 3600}`,
		},
		{
			// --uri is not a secret name, so only the password goes. Which
			// host the job was talking to stays readable, which is the point.
			"credentials in a url",
			"sql:query --uri=mysql://drupal:hunter2@db:3306/site",
			"sql:query --uri=mysql://drupal:***@db:3306/site",
		},
		{
			"bare url in output",
			"connecting to mysql://drupal:hunter2@db:3306/site",
			"connecting to mysql://drupal:***@db:3306/site",
		},
		{
			// The header name matches too, so the whole value goes. Better
			// that than the ordering bug where "Bearer" is hidden and the
			// token it introduces is not.
			"authorization header echoed by a job",
			"Authorization: Bearer eyJhbGciOiJIUzI1NiJ9",
			"Authorization: ***",
		},
		{
			"bearer token with no header name around it",
			"retrying with bearer eyJhbGciOiJIUzI1NiJ9 now",
			"retrying with bearer *** now",
		},
		{
			"nothing to hide is left alone",
			"advancedqueue:queue:process feeds --items-limit=100",
			"advancedqueue:queue:process feeds --items-limit=100",
		},
		{
			"a flag that merely sounds alarming keeps its value",
			"sapi-i product_registrations --batch-size=50",
			"sapi-i product_registrations --batch-size=50",
		},
	}

	for _, c := range cases {
		if got := Redact(c.text); got != c.want {
			t.Errorf("%s:\n got %q\nwant %q", c.name, got, c.want)
		}
	}
}

// Output reaches the log through trimForLog, so the filter has to sit there
// too and not only on the command.
func TestTrimForLogRedacts(t *testing.T) {
	got := trimForLog("connecting\nwith --password=hunter2 now")

	if strings.Contains(got, "hunter2") {
		t.Errorf("secret survived: %s", got)
	}
}
