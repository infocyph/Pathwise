#!/usr/bin/env python3
"""Matched, same-runner Foundation host request-equivalent comparison."""
import json
import statistics
import subprocess
import sys
from pathlib import Path

baseline, candidate, report_path = sys.argv[1:4]
runner = str(Path(__file__).with_name("FoundationHostBenchmark.php"))
trials = {"4.1": [], "4.2": []}

# Reverse the trial order to reduce warmed-cache or host-load ordering bias.
for sequence in [("4.1", "4.2"), ("4.2", "4.1"), ("4.1", "4.2")]:
    for name in sequence:
        path = baseline if name == "4.1" else candidate
        raw = subprocess.check_output(["php", "-d", "opcache.enable_cli=0", runner, path, "2000"], text=True)
        data = json.loads(raw.strip().splitlines()[-1])
        if data["successes"] != 2000:
            raise RuntimeError(f"Insufficient successful host requests: {name}")
        trials[name].append(data)
        print(f"{name} / trial {len(trials[name])}: {data['rpm']:.0f} RPM, p95={data['p95_ms']:.3f}ms, p99={data['p99_ms']:.3f}ms, RSS={data['peak_rss_kb']}kB", flush=True)

def median(key, kind):
    return statistics.median(trial[key] for trial in trials[kind])

old_rpm, new_rpm = median("rpm", "4.1"), median("rpm", "4.2")
rpm_change = (new_rpm / old_rpm - 1.0) * 100.0
report = {
    "baseline_tag": "4.1",
    "candidate": "pull-request checkout",
    "description": "Same-runner synthetic Foundation 3 host request-equivalent throughput, NOT deployed application/network RPM",
    "trial_count": 3,
    "trials": trials,
    "baseline_median_rpm": old_rpm,
    "candidate_median_rpm": new_rpm,
    "rpm_change_percent": rpm_change,
    "median_p95_ms": {key: median("p95_ms", key) for key in trials},
    "median_p99_ms": {key: median("p99_ms", key) for key in trials},
    "median_peak_rss_kb": {key: median("peak_rss_kb", key) for key in trials},
    "pass": rpm_change >= -2.0,
    "rpm_regression_limit_percent": 2.0,
}
Path(report_path).write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps({k: v for k, v in report.items() if k != "trials"}, indent=2), flush=True)
if not report["pass"]:
    raise SystemExit(f"Foundation host benchmark regression {rpm_change:.2f}% exceeds -2.0% acceptance limit")
