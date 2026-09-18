#!/bin/bash
# docker/start.sh
#
# Render only sets $PORT when the CONTAINER STARTS, not during `docker
# build` -- so the port Apache listens on has to be substituted here, at
# startup, not baked into the image at build time.
set -e
PORT="${PORT:-10000}"
sed -ri "s/Listen [0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
