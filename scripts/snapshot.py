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
about the public repo. Pure stdlib (urllib) — no `gh` needed, so it runs the
same in GitHub Actions and in a plain cron on a server.

Auth: a token in $GH_TOKEN or $GITHUB_TOKEN is used if present. Downloads and
stars are public (work with no token); the traffic API needs push access, so
without a token the clone/view columns are simply left unchanged.

Usage: scripts/snapshot.py  [owner/repo]   (defaults to sirelves/bedrock-ddos-shield)
"""
import csv
import json
import os
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone

REPO = sys.argv[1] if len(sys.argv) > 1 else "sirelves/bedrock-ddos-shield"
STATS_DIR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "stats")
TOKEN = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")


def gh_api(path):
    """GET https://api.github.com/<path> as JSON, or None on failure."""
    req = urllib.request.Request(
        "https://api.github.com/" + path,
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": "bedrock-ddos-shield-stats",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    if TOKEN:
        req.add_header("Authorization", "Bearer " + TOKEN)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.load(r)
    except urllib.error.HTTPError as e:
        sys.stderr.write(f"warn: GET {path} -> HTTP {e.code}\n")
    except Exception as e:  # noqa: BLE001 — never fail the whole snapshot on one call
        sys.stderr.write(f"warn: GET {path} failed: {e}\n")
    return None


def load_csv(path):
    if not os.path.exists(path):
        return {}
    with open(path, newline="") as f:
        return {r["date"]: r for r in csv.DictReader(f)}


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
    traffic = load_csv(traffic_path)
    tr_header = ["date", "clones", "clones_unique", "views", "views_unique"]

    def upsert(payload_key, count_col, uniq_col):
        data = gh_api(f"repos/{REPO}/traffic/{payload_key}") or {}
        for day in data.get(payload_key, []):
            d = day["timestamp"][:10]
            row = traffic.get(d) or {k: "" for k in tr_header}
            row["date"] = d
            row[count_col] = day["count"]
            row[uniq_col] = day["uniques"]
            traffic[d] = row

    upsert("clones", "clones", "clones_unique")
    upsert("views", "views", "views_unique")

    for row in traffic.values():  # normalize any missing cells
        for k in tr_header:
            row.setdefault(k, "")
    write_csv(traffic_path, tr_header, traffic)

    # ---- totals.csv (cumulative snapshot, one row per run day) ----
    totals_path = os.path.join(STATS_DIR, "totals.csv")
    totals = load_csv(totals_path)
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
          f"stars={repo.get('stargazers_count')} traffic_days={len(traffic)} "
          f"token={'yes' if TOKEN else 'no'}")


if __name__ == "__main__":
    main()
