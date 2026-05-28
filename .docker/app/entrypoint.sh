#!/bin/bash
set -e

trap 'exit 0' SIGTERM SIGINT

for script in /docker-entrypoint.d/*.sh; do
    [ -x "$script" ] && "$script"
done

exec "$@"
