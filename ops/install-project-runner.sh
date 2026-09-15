#!/usr/bin/env bash
set -euo pipefail
# H86 only. Run after provision-project-runner.rb; no credential is printed.
[[ "$(hostname -s)" == ns3140186 ]]
test ! -e /srv/yuz-tra-runner
if docker inspect yuz-tra-runner >/dev/null 2>&1; then
  echo 'Refusing to replace an existing runner container' >&2
  exit 1
fi
docker exec g17_14b test -f /tmp/yuz-tra-runner-config.toml
image=$(docker inspect gitlab-runner --format '{{.Image}}')
[[ "$image" =~ ^sha256:[a-f0-9]{64}$ ]]
sudo -n install -d -m 700 /srv/yuz-tra-runner/config
sudo -n docker cp g17_14b:/tmp/yuz-tra-runner-config.toml /srv/yuz-tra-runner/config/config.toml
sudo -n chmod 600 /srv/yuz-tra-runner/config/config.toml
docker run -d --name yuz-tra-runner --restart unless-stopped --memory 256m --cpus 0.5 \
  -v /srv/yuz-tra-runner/config:/etc/gitlab-runner \
  -v /var/run/docker.sock:/var/run/docker.sock "$image"
# This socket is available only to the trusted runner daemon, never to CI jobs.
docker exec yuz-tra-runner gitlab-runner verify >/dev/null 2>&1
docker exec g17_14b rm /tmp/yuz-tra-runner-config.toml
echo 'PASS dedicated runner credential verified; staging credential removed'
