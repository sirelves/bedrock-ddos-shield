#!/usr/bin/env python3
"""
bedrock-ddos-shield — adoption snapshot.

Pulls the free GitHub metrics (release downloads, clone/view traffic, stars)
and merges them into CSVs under stats/, keyed by date so history is never lost.

- stats/traffic.csv : one row per DAY, upserted from GitHub's 14-day traffic
                      window (clones + views, total and unique). Running daily
                      means each day is captured before it falls out of the window.
- stats/totals.csv  : one row per RUN DAY with cumulative counters
                      (phar downloads, stars, forks).

No telemetry, no phone-home: everything here is data GitHub already collects
about the public repo. Reads the repo via `gh api` (token from the environment).

Usage: scripts/snapshot.py  [owner/repo]   (defaults to sirelves/bedrock-ddos-shield)
"""
import csv
import json
import os
import subprocess
import sys
from datetime import datetime, timezone

REPO = sys.argv[1] if len(sys.argv) > 1 else "sirelves/bedrock-ddos-shield"
STATS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "stats")


def gh_api(path):
    """Call `gh api <path>` and return parsed JSON, or None on failure."""
    try:
        out = subprocess.run(
            ["gh", "api", path, "-H", "Accept: application/vnd.github+json"],
            capture_output=True, text=True, check=True,
        ).stdout
        return json.loads(out) if out.strip() else None
    except subprocess.CalledProcessError as e:
        sys.stderr.write(f"warn: gh api {path} failed: {e.stderr.strip()[:200]}\n")
        return None


def load_csv(path):
    if not os.path.exists(path):
        return {}, []
    with open(path, newline="") as f:
        rows = list(csv.DictReader(f))
    header = rows[0].keys() if rows else []
    return {r["date"]: r for r in rows}, list(header)


def write_csv(path, header, rows_by_date):
    with open(path, "w", newline="") as f:
        w = csv.DictWriter(f, fieldnames=header)
        w.writeheader()
        for date in sorted(rows_by_date):
            w.writerow(rows_by_date[date])


def main():
    os.makedirs(STATS_DIR, exist_ok=True)
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")

    # ---- traffic.csv (per-day, upsert from the rolling 14-day window) ----
    traffic_path = os.path.join(STATS_DIR, "traffic.csv")
    traffic, _ = load_csv(traffic_path)
    tr_header = ["date", "clones", "clones_unique", "views", "views_unique"]

    clones = gh_api(f"repos/{REPO}/traffic/clones") or {}
    views = gh_api(f"repos/{REPO}/traffic/views") or {}

    # fill clones then views, keeping any field already set
    for day in clones.get("clones", []):
        d = day["timestamp"][:10]
        row = traffic.get(d, {k: "" for k in tr_header})
        row["date"] = d
        row["clones"] = day["count"]
        row["clones_unique"] = day["uniques"]
        traffic[d] = row
    for day in views.get("views", []):
        d = day["timestamp"][:10]
        row = traffic.get(d, {k: "" for k in tr_header})
        row["date"] = d
        row["views"] = day["count"]
        row["views_unique"] = day["uniques"]
        traffic[d] = row

    # normalize missing cells
    for d, row in traffic.items():
        for k in tr_header:
            row.setdefault(k, "")
    write_csv(traffic_path, tr_header, traffic)

    # ---- totals.csv (cumulative snapshot, one row per run day) ----
    totals_path = os.path.join(STATS_DIR, "totals.csv")
    totals, _ = load_csv(totals_path)
    to_header = ["date", "phar_downloads", "stars", "forks", "watchers"]

    releases = gh_api(f"repos/{REPO}/releases") or []
    phar_dl = sum(
        a.get("download_count", 0)
        for rel in releases
        for a in rel.get("assets", [])
        if a.get("name", "").endswith(".phar")
    )
    repo = gh_api(f"repos/{REPO}") or {}

    totals[today] = {
        "date": today,
        "phar_downloads": phar_dl,
        "stars": repo.get("stargazers_count", ""),
        "forks": repo.get("forks_count", ""),
        "watchers": repo.get("subscribers_count", ""),
    }
    write_csv(totals_path, to_header, totals)

    print(f"{today}: phar_downloads={phar_dl} "
          f"stars={repo.get('stargazers_count')} "
          f"traffic_days={len(traffic)}")


if __name__ == "__main__":
    main()
