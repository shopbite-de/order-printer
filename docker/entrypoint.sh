#!/bin/sh
# Prepares the /app/data volume and the SQLite database, then runs the given command
# (supervisord by default) as root so it can drop privileges per program.
set -eu
cd /app

mkdir -p data/receipts var
chown -R app:app data var

# Creates the SQLite file and the messenger_messages table in /app/data on first start, so a
# volume that is not writable fails loudly here instead of inside a worker. The lock_keys table
# is created on first use. There are no Doctrine migrations in this project (no entities), so
# there is no migrate step; doctrine:database:create is not used because SQLite does not
# support --if-not-exists.
su-exec app php bin/console messenger:setup-transports --no-interaction

exec "$@"
