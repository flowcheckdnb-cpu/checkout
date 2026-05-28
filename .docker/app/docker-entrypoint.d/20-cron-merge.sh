#!/bin/bash
# Merge all .cron files into a single supercronic-readable file.
merged="/etc/crontabs/merged"
: > "$merged"
for f in /etc/crontabs/*.cron; do
    [ -f "$f" ] || continue
    cat "$f" >> "$merged"
    echo "" >> "$merged"
done
