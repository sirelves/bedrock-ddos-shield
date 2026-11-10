#!/usr/bin/env python3
"""
bedrock-ddos-shield — render stats/adoption.svg from the CSVs.

Pure stdlib: hand-draws an SVG line chart, so it runs in the daily cron with
no matplotlib/no pip, and GitHub renders the result inline in the README.

Series (all counts, one shared axis — legend states the semantics):
  - clones/day (unique)   from traffic.csv  → best proxy for host-side installs
  - views/day  (unique)   from traffic.csv
  - downloads (total)     from totals.csv   → the .phar plugin, cumulative
  - stars (total)         from totals.csv

Usage: scripts/plot.py   (writes stats/adoption.svg)
"""
import csv
import math
import os
from datetime import datetime

STATS = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "stats")

W, H = 820, 360
PAD_L, PAD_R, PAD_T, PAD_B = 48, 20, 50, 74
PLOT_W = W - PAD_L - PAD_R
PLOT_H = H - PAD_T - PAD_B

SERIES = [
    ("clones/dia", "clones_unique",  "traffic", "#0969da"),
    ("views/dia",  "views_unique",   "traffic", "#1a7f37"),
    ("downloads",  "phar_downloads", "totals",  "#8250df"),
    ("stars",      "stars",          "totals",  "#bf8700"),
]


def load(name):
    path = os.path.join(STATS, name + ".csv")
    if not os.path.exists(path):
        return []
    with open(path, newline="") as f:
        return list(csv.DictReader(f))


def to_int(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def esc(s):
    return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def nice_top(ymax):
    """Axis top as an integer so every gridline is a distinct whole number."""
    if ymax <= 4:
        return max(ymax, 1), max(ymax, 1)          # (top, nticks) → 1-unit steps
    raw = ymax / 4.0
    mag = 10 ** math.floor(math.log10(raw))
    step = next(m * mag for m in (1, 2, 5, 10) if m * mag >= raw)
    step = int(step)
    nticks = math.ceil(ymax / step)
    return step * nticks, nticks


def main():
    traffic = {r["date"]: r for r in load("traffic")}
    totals = {r["date"]: r for r in load("totals")}

    dates = sorted(set(traffic) | set(totals))
    if not dates:
        dates = [datetime.utcnow().strftime("%Y-%m-%d")]

    xs = {d: i for i, d in enumerate(dates)}
    n = max(len(dates) - 1, 1)

    data, ymax = {}, 1
    for label, col, src, color in SERIES:
        rows = traffic if src == "traffic" else totals
        pts = {}
        for d in dates:
            r = rows.get(d)
            if r is None:
                continue
            v = to_int(r.get(col))
            if v is None:
                continue
            pts[d] = v
            ymax = max(ymax, v)
        data[label] = (pts, color)

    top, nticks = nice_top(ymax)

    def X(d):
        return PAD_L + (xs[d] / n) * PLOT_W
    def Y(v):
        return PAD_T + PLOT_H - (v / top) * PLOT_H

    out = [
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" '
        f'viewBox="0 0 {W} {H}" font-family="-apple-system,Segoe UI,Roboto,sans-serif">',
        f'<rect x="0.5" y="0.5" width="{W-1}" height="{H-1}" rx="10" fill="#ffffff" stroke="#d0d7de"/>',
        f'<text x="{PAD_L}" y="30" font-size="16" font-weight="700" fill="#1f2328">'
        f'bedrock-ddos-shield — adoção</text>',
        f'<text x="{W-16}" y="30" font-size="11" fill="#8c959f" text-anchor="end">'
        f'atualizado {esc(dates[-1])}</text>',
    ]

    # y gridlines + integer labels
    for i in range(nticks + 1):
        v = top * i / nticks
        y = Y(v)
        out.append(f'<line x1="{PAD_L}" y1="{y:.1f}" x2="{PAD_L+PLOT_W}" y2="{y:.1f}" stroke="#eaeef2"/>')
        out.append(f'<text x="{PAD_L-8}" y="{y+4:.1f}" font-size="11" fill="#8c959f" '
                   f'text-anchor="end">{round(v)}</text>')

    # x labels: first, middle, last
    for i in sorted(set([0, len(dates)//2, len(dates)-1])):
        d = dates[i]
        anchor = "middle" if 0 < i < len(dates)-1 else ("start" if i == 0 else "end")
        out.append(f'<text x="{X(d):.1f}" y="{PAD_T+PLOT_H+20:.1f}" font-size="11" '
                   f'fill="#8c959f" text-anchor="{anchor}">{esc(d[5:])}</text>')

    # series lines + dots
    for label, (pts, color) in data.items():
        ordered = [(d, pts[d]) for d in dates if d in pts]
        if not ordered:
            continue
        if len(ordered) > 1:
            poly = " ".join(f"{X(d):.1f},{Y(v):.1f}" for d, v in ordered)
            out.append(f'<polyline points="{poly}" fill="none" stroke="{color}" '
                       f'stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>')
        for d, v in ordered:
            out.append(f'<circle cx="{X(d):.1f}" cy="{Y(v):.1f}" r="3" fill="{color}"/>')

    # horizontal legend row (bottom) — swatch + label + latest value
    ly = PAD_T + PLOT_H + 46
    colw = PLOT_W / len(SERIES)
    for i, (label, (pts, color)) in enumerate(data.items()):
        cx = PAD_L + i * colw
        last = next((pts[d] for d in reversed(dates) if d in pts), 0)
        out.append(f'<rect x="{cx:.1f}" y="{ly-10:.1f}" width="11" height="11" rx="3" fill="{color}"/>')
        out.append(f'<text x="{cx+17:.1f}" y="{ly:.1f}" font-size="12" fill="#57606a">'
                   f'{esc(label)} <tspan font-weight="700" fill="{color}">{last}</tspan></text>')

    out.append(f'<text x="{PAD_L}" y="{H-12}" font-size="10" fill="#afb8c1">'
               f'sem telemetria — métricas públicas do GitHub, atualizadas diariamente</text>')
    out.append("</svg>")

    path = os.path.join(STATS, "adoption.svg")
    with open(path, "w") as f:
        f.write("\n".join(out) + "\n")
    print(f"wrote {path} ({len(dates)} dias, top={top})")


if __name__ == "__main__":
    main()
