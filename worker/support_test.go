package main

import (
	"reflect"
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
		{"advancedqueue:queue:process wa_sendgrid", []string{"advancedqueue:queue:process", "wa_sendgrid"}},
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
