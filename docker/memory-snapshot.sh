#!/bin/sh
set -eu

CGROUP_DIR=/sys/fs/cgroup
STATUS_URL='http://127.0.0.1/_internal-apache-status?auto'

echo '== cgroup totals (bytes) =='
for metric in memory.current memory.peak memory.max; do
	if [ -r "$CGROUP_DIR/$metric" ]; then
		printf '%s=' "$metric"
		cat "$CGROUP_DIR/$metric"
	fi
done

echo '== cgroup composition (bytes) =='
if [ -r "$CGROUP_DIR/memory.stat" ]; then
	awk '$1 ~ /^(anon|file|kernel|shmem|slab)$/ { print $1 "=" $2 }' "$CGROUP_DIR/memory.stat"
fi

echo '== cgroup pressure/OOM events =='
if [ -r "$CGROUP_DIR/memory.events" ]; then
	cat "$CGROUP_DIR/memory.events"
fi

echo '== Apache processes =='
ps -eo pid,ppid,rss,etime,cmd | awk 'NR == 1 || /apache2 -DFOREGROUND/'

echo '== Apache scoreboard (localhost only) =='
curl --fail --silent --show-error "$STATUS_URL" || echo 'Apache status endpoint unavailable'
