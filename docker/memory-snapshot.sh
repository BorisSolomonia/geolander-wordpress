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

echo '== all processes by CPU (miner/rogue-process visibility) =='
ps -eo pid,ppid,user,%cpu,%mem,rss,etime,cmd --sort=-%cpu | head -n 25

echo '== writable PHP-family files outside uploads =='
find /var/www/html -xdev -path /var/www/html/wp-content/uploads -prune -o \
	-type f \( -name '*.php' -o -name '*.phtml' -o -name '*.phar' \) -perm /022 -print

echo '== executable/PHP-family files in uploads =='
if [ -d /var/www/html/wp-content/uploads ]; then
	find /var/www/html/wp-content/uploads -xdev -type f \
		\( -name '*.php' -o -name '*.phtml' -o -name '*.phar' -o -perm /111 \) -print
fi

echo '== Apache scoreboard (localhost only) =='
curl --fail --silent --show-error "$STATUS_URL" || echo 'Apache status endpoint unavailable'
