"""Logstash rules: no single quote inside a ruby code => '...' block (it would end the block; Logstash then refuses all).
Usage: conf-quotes.py FILE..."""
import sys
bad = 0
for path in sys.argv[1:]:
    inside = False
    for n, line in enumerate(open(path), 1):
        s = line.rstrip("\n")
        if not inside and s.rstrip().endswith("code => '"):
            inside = True
            continue
        if inside and s.strip() == "'":
            inside = False
            continue
        if inside and "'" in s:
            print("%s:%d: a single quote inside a ruby block: %s" % (path, n, s.strip()[:120]))
            bad += 1
sys.exit(1 if bad else 0)
