# Editor browser tests

Run `npm run test:editor` to exercise the authenticated editor through the
browser. The runner reads `EDITOR_BASE_URL`, or the worktree's `APP_URL`, and
creates an idempotent `editor-browser-smoke@example.test` fixture in that
environment only.

The smoke test verifies that **Add layer** receives keyboard focus, creates a
text layer through the rendered UI, and checks that its inspector retains a
changed value. Subsequent editor slices extend this runner with focused
keyboard-navigation scenarios for the controls they add.

Run `npm run test:editor-v2-save` to check the message that the editor shows
when the API refuses a save with 409. The fixture adds a template with a v2
editor document for this test. The API reads the session cookie only from a
stateful domain, so serve the app on `localhost` or set
`SANCTUM_STATEFUL_DOMAINS` to the host and port of `EDITOR_BASE_URL`.

The browser sandbox remains enabled by default. A trusted local root container
can opt in to Chromium's `--no-sandbox` flag with `EDITOR_BROWSER_NO_SANDBOX=1`.
