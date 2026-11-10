# stats/ — adoption history

Auto-generated daily by [`.github/workflows/stats-snapshot.yml`](../.github/workflows/stats-snapshot.yml).
No telemetry: these are the metrics GitHub already keeps about this public repo,
snapshotted so the **14-day** traffic window doesn't lose history.

- **`traffic.csv`** — one row per day: `clones`, `clones_unique`, `views`, `views_unique`.
  `git clone` count is the best proxy for **host-side** installs (`install.sh`).
- **`totals.csv`** — cumulative counters per snapshot day: `phar_downloads`
  (the **plugin**), `stars`, `forks`, `watchers`.

Run it by hand anytime: `scripts/stats.sh` (quick print) or
`python3 scripts/snapshot.py` (writes the CSVs).
