export PATH=/usr/pgsql-9.0/bin:/usr/kerberos/bin:/usr/local/bin:/bin:/usr/bin
export PGDATA=/var/lib/pgsql/9.0/data
repmgr -f /var/lib/pgsql/repmgr/repmgr.conf --verbose standby promote 2>&1
exit

