# Caretaker2 Agent

The extension in a monitored TYPO3 instance. It collects an inventory of the
installation, `composer.json` and `composer.lock` included, and pushes it to
a [Caretaker2 hub](https://github.com/etobi/typo3-caretaker2-hub). The agent
only reads; it evaluates nothing and executes nothing on behalf of the hub.

Runs on TYPO3 v11 to v14, PHP 7.4 and up.

```
composer require caretaker2/agent
vendor/bin/typo3 caretaker2:connect https://hub.example.com ABCD-1234
vendor/bin/typo3 caretaker2:push --print   # what would leave this instance
```

Or connect in the backend under *System → Caretaker2*, where the daily
scheduler task can be created with one click. Connecting sends a first
inventory right away, without the checks TYPO3 runs on itself; those take
a while and follow with the next push. `typo3/cms-reports` is suggested:
with it, those checks are reported too.

Some of those checks look at the request they run in, the HTTPS and
`lockSSL` checks among them. The agent runs them against the address the
instance is reached under: `TYPO3_BASE_URL` when that environment variable
is set, otherwise the first site whose base names a host. Without either,
those checks are left out and the hub says so.

## Read-only mirror

This repository is a read-only release mirror. Development happens in a
private monorepo; issues and pull requests here are not monitored.

## License

GPL-2.0-or-later, see `LICENSE`.
