#!/usr/bin/env python3
"""BluPin daily signal engine — the rung-3 Survivor configuration, headless.

Reproduces the production Pine engine on fresh hourly gold data:
  prior-day 20:00-00:00 SAST body range -> 00:00-03:00 latest-sweep fade
  (contrarian fallback) -> four day-filters (NFP Friday, prior-day bias,
  noise floor 0.25x, 2-touch level) -> survivor checkpoint at 05:00.

Each run (weekdays ~05:15 SAST):
  1. computes TODAY's signal (or the skip/suppress reason),
  2. grades YESTERDAY's signal (entry 05:00 close, SL 1.1 ATR, 0.5 ATR
     cancel, day-end exit),
  3. appends both to signals/journal.jsonl,
  4. emits observation/decision/outcome envelopes to Dot.Memory's
     intelligence loop (ADR-0015) when DOT_MEMORY_URL/TOKEN are set.

Stateless: everything is derived from the 60-day fetch each run.
"""
import json, os, sys, urllib.request
from datetime import datetime, timezone, timedelta

SAST = timezone(timedelta(hours=2))
SYMBOL = os.environ.get("BLUPIN_SYMBOL", "GC=F")
JOURNAL = os.path.join(os.path.dirname(__file__), "..", "signals", "journal.jsonl")


def fetch_bars():
    url = (f"https://query1.finance.yahoo.com/v8/finance/chart/{SYMBOL}"
           "?interval=1h&range=60d")
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    r = json.load(urllib.request.urlopen(req, timeout=30))["chart"]["result"][0]
    ts, q = r["timestamp"], r["indicators"]["quote"][0]
    bars = []
    for i, t in enumerate(ts):
        o, h, l, c = q["open"][i], q["high"][i], q["low"][i], q["close"][i]
        if None in (o, h, l, c):
            continue
        dt = datetime.fromtimestamp(t, SAST)
        bars.append(dict(t=t, day=dt.date(), hr=dt.hour, o=o, h=h, l=l, c=c))
    return bars


def atr_series(bars, n=14):
    atr = [None] * len(bars)
    trs = []
    for i, b in enumerate(bars):
        pc = bars[i - 1]["c"] if i else b["c"]
        trs.append(max(b["h"] - b["l"], abs(b["h"] - pc), abs(b["l"] - pc)))
        if i == n - 1:
            atr[i] = sum(trs) / n
        elif i >= n:
            atr[i] = (atr[i - 1] * (n - 1) + trs[-1]) / n
    return atr


def analyze(bars, atr):
    """Per-day engine state. Returns {date: record}."""
    byday = {}
    for i, b in enumerate(bars):
        byday.setdefault(b["day"], []).append(i)
    days = sorted(byday)
    out = {}
    prev_range = None          # (body_hi, body_lo, late_start_idx)
    prev_day_dir = 0
    pen_hist = []              # (day, hr, pen_hi, pen_lo) for the noise floor
    for d in days:
        idxs = byday[d]
        obs = [i for i in idxs if bars[i]["hr"] in (0, 1, 2)]
        late = [i for i in idxs if bars[i]["hr"] in (20, 21, 22, 23)]
        rec = None
        if obs and prev_range is not None:
            rhi, rlo, lstart = prev_range
            for i in obs:
                b = bars[i]
                pen_hist.append((d, b["hr"], max(0.0, b["h"] - rhi),
                                 max(0.0, rlo - b["l"])))
            sw_dir, sw_lvl, sw_i, sw_hr = 0, None, None, None
            for i in obs:
                b = bars[i]
                hp, lp = b["h"] > rhi, b["l"] < rlo
                if hp and lp:
                    if abs(b["h"] - b["o"]) >= abs(b["o"] - b["l"]):
                        sw_dir, sw_lvl = -1, rhi
                    else:
                        sw_dir, sw_lvl = 1, rlo
                    sw_i, sw_hr = i, b["hr"]
                elif hp:
                    sw_dir, sw_lvl, sw_i, sw_hr = -1, rhi, i, b["hr"]
                elif lp:
                    sw_dir, sw_lvl, sw_i, sw_hr = 1, rlo, i, b["hr"]
            ei = obs[-1]
            a = atr[ei]
            day_open = bars[idxs[0]]["o"]
            obs_close = bars[ei]["c"]
            if sw_dir != 0:
                dirn, src, lvl = sw_dir, "sweep", sw_lvl
            elif obs_close != day_open:
                dirn, src, lvl = (-1 if obs_close > day_open else 1), "fallback", None
            else:
                dirn, src, lvl = 0, "flat", None
            skip = None
            if dirn != 0 and a:
                if d.weekday() == 4 and d.day <= 7:
                    skip = "nfp"
                elif src == "sweep" and prev_day_dir != 0 and not (
                        (dirn == 1 and prev_day_dir == -1) or
                        (dirn == -1 and prev_day_dir == 1)):
                    skip = "bias"
                elif src == "sweep":
                    hist = [p for p in pen_hist if p[0] < d and p[1] == sw_hr]
                    hdays = sorted({p[0] for p in hist})[-14:]
                    vals = [(p[2] if dirn == -1 else p[3]) for p in hist
                            if p[0] in hdays]
                    if len(hdays) >= 10:
                        pen = (bars[sw_i]["h"] - lvl) if dirn == -1 else (lvl - bars[sw_i]["l"])
                        if pen <= (sum(vals) / len(vals)) * 0.25:
                            skip = "noise"
                    if skip is None:
                        tch = sum(1 for i in range(lstart, sw_i)
                                  if abs((bars[i]["h"] if dirn == -1 else bars[i]["l"]) - lvl)
                                  <= 0.5 * a)
                        if tch < 2:
                            skip = "thin"
                # survivor: invalidation before the 05:00 checkpoint
                surv = None
                if skip is None:
                    hb = [i for i in idxs if i > ei and bars[i]["hr"] < 5]
                    hidx = hb[-1] if hb else ei
                    if lvl is not None:
                        for i in hb:
                            b = bars[i]
                            if (b["c"] > lvl + 0.5 * a) if dirn == -1 else (b["c"] < lvl - 0.5 * a):
                                skip = "suppressed"
                                break
                    if skip is None:
                        entry = bars[hidx]["c"]
                        pnl, exitr = None, "open"
                        sl = entry - dirn * 1.1 * atr[hidx]
                        for i in [j for j in idxs if j > hidx]:
                            b = bars[i]
                            if (b["l"] <= sl) if dirn == 1 else (b["h"] >= sl):
                                pnl, exitr = dirn * (sl - entry), "sl"
                                break
                            if lvl is not None and (
                                    (b["c"] > lvl + 0.5 * a) if dirn == -1 else
                                    (b["c"] < lvl - 0.5 * a)):
                                pnl, exitr = dirn * (b["c"] - entry), "cancel"
                                break
                        rest = [j for j in idxs if j > hidx]
                        if pnl is None and rest:
                            pnl, exitr = dirn * (bars[rest[-1]]["c"] - entry), "dayend"
                        surv = dict(entry=round(entry, 2), atr=round(atr[hidx], 2),
                                    pnl=round(pnl, 2) if pnl is not None else None,
                                    pnl_atr=round(pnl / atr[hidx], 3) if pnl is not None else None,
                                    exit=exitr)
                rec = dict(date=str(d), dir=dirn, src=src, skip=skip,
                           lvl=round(lvl, 2) if lvl else None,
                           prev_day=prev_day_dir, sweep_hr=sw_hr,
                           obs_close=round(obs_close, 2), **(surv or {}))
        if rec:
            out[str(d)] = rec
        if late:
            bhi = max(max(bars[i]["o"], bars[i]["c"]) for i in late)
            blo = min(min(bars[i]["o"], bars[i]["c"]) for i in late)
            prev_range = (bhi, blo, late[0])
        if idxs:
            o0, c1 = bars[idxs[0]]["o"], bars[idxs[-1]]["c"]
            prev_day_dir = 1 if c1 > o0 else -1 if c1 < o0 else 0
    return out


def emit_memory(envelope):
    base = os.environ.get("DOT_MEMORY_URL", "").rstrip("/")
    token = os.environ.get("DOT_MEMORY_TOKEN", "")
    if not base or not token:
        return "skipped (no DOT_MEMORY_URL/TOKEN)"
    path = {"observation": "events", "decision": "decisions",
            "outcome": "outcomes"}[envelope["stage"]]
    req = urllib.request.Request(
        f"{base}/api/intelligence/{path}",
        data=json.dumps(envelope).encode(),
        headers={"Authorization": f"Bearer {token}",
                 "Content-Type": "application/json",
                 "Accept": "application/json"},
        method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return f"HTTP {r.status}"
    except Exception as e:
        return f"error: {e}"


def envelope(stage, day, payload):
    return {
        "loop_id": f"blupin-gold-{day}",
        "event_id": f"blupin-gold-{day}-{stage}",
        "stage": stage,
        "platform": "blupin",
        "subject": {"type": "trading-signal", "id": f"gold-{day}",
                    "label": "BluPin ORD+ULT daily signal (TVC:GOLD)"},
        "source": "blupin-daily-engine",
        "occurred_at": datetime.now(timezone.utc).isoformat(),
        "x-blupin": payload,
    }


def main():
    bars = fetch_bars()
    atr = atr_series(bars)
    days = analyze(bars, atr)
    today = datetime.now(SAST).date()
    keys = sorted(days)
    rec_today = days.get(str(today))
    rec_prev = days[keys[-2]] if len(keys) >= 2 and keys[-1] == str(today) else \
        (days[keys[-1]] if keys and keys[-1] != str(today) else None)

    lines, emits = [], []
    if rec_today:
        sig = ("NONE (" + rec_today["skip"] + ")") if rec_today.get("skip") else \
            ("BUY" if rec_today["dir"] == 1 else "SELL")
        lines.append(dict(kind="signal", **rec_today))
        emits.append(("observation", rec_today["date"],
                      {k: rec_today[k] for k in ("src", "lvl", "prev_day", "sweep_hr", "obs_close") if k in rec_today}))
        emits.append(("decision", rec_today["date"],
                      dict(signal=sig, dir=rec_today["dir"], skip=rec_today.get("skip"),
                           entry=rec_today.get("entry"))))
        print(f"TODAY {rec_today['date']}: {sig}  src={rec_today['src']}")
    if rec_prev and rec_prev.get("pnl_atr") is not None:
        graded = dict(kind="outcome", **rec_prev)
        lines.append(graded)
        emits.append(("outcome", rec_prev["date"],
                      dict(win=rec_prev["pnl_atr"] > 0, pnl_atr=rec_prev["pnl_atr"],
                           exit=rec_prev["exit"])))
        print(f"PREV  {rec_prev['date']}: pnl {rec_prev['pnl_atr']} ATR ({rec_prev['exit']})")

    os.makedirs(os.path.dirname(JOURNAL), exist_ok=True)
    seen = set()
    if os.path.exists(JOURNAL):
        for ln in open(JOURNAL):
            try:
                j = json.loads(ln)
                seen.add((j.get("kind"), j.get("date")))
            except Exception:
                pass
    with open(JOURNAL, "a") as f:
        for ln in lines:
            if (ln["kind"], ln["date"]) not in seen:
                f.write(json.dumps(ln) + "\n")

    for stage, day, payload in emits:
        print(f"memory {stage} {day}: {emit_memory(envelope(stage, day, payload))}")


if __name__ == "__main__":
    sys.exit(main())
