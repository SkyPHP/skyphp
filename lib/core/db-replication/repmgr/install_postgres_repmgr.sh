cd /usr/local/src

#BEGIN INTALL POSTGRESQL 9.0.4
#create a folder for our rpms
mkdir -p postgresql9.0.4_rpm
cd postgresql9.0.4_rpm

#wget the necesary rpms
wget http://www.pgrpms.org/9.0/redhat/rhel-5-x86_64/postgresql90-server-9.0.4-1PGDG.rhel5.x86_64.rpm
wget http://www.pgrpms.org/9.0/redhat/rhel-5-x86_64/postgresql90-devel-9.0.4-1PGDG.rhel5.x86_64.rpm
wget http://www.pgrpms.org/9.0/redhat/rhel-5-x86_64/postgresql90-9.0.4-1PGDG.rhel5.x86_64.rpm
wget http://www.pgrpms.org/9.0/redhat/rhel-5-x86_64/postgresql90-libs-9.0.4-1PGDG.rhel5.x86_64.rpm

#install the rpms
rpm -ivh *
#END INSTALL POSTGRESQL 9.0.4


#BEGIN MISC SETUP
#initialize the db
service postgresql-9.0 initdb

#Add the postgres bin directory to our PATH now
PATH=/usr/pgsql-9.0/bin:$PATH
export PATH

#Add the postgres bin directory to the .bash_profile PATH if it doesn't appear to already
if ! grep -P /usr/pgsql\-9.0/bin ~/.bash_profile >/dev/null; then
echo PATH=/usr/pgsql-9.0/bin:\$PATH >>~/.bash_profile;
echo export PATH >>~/.bash_profile;
fi

#Add the postgres bin directory to the ~postgres/.bash_profile PATH if it doesn't appear to already
if ! grep -P /usr/pgsql\-9.0/bin ~postgres/.bash_profile >/dev/null; then
echo PATH=/usr/pgsql-9.0/bin:\$PATH >>~postgres/.bash_profile;
echo export PATH >>~postgres/.bash_profile;
fi

#Define PGDATA now
PGDATA=/var/lib/pgsql/9.0/data
export PGDATA

#Adds $PGDATA to the .bash_profile if it doesn't appear to already
if ! grep -P PGDATA ~/.bash_profile>/dev/null; then
echo PGDATA=/var/lib/pgsql/9.0/data >>~/.bash_profile;
echo export PGDATA >>~/.bash_profile;
fi

#Adds $PGDATA to the ~postgres/.bash_profile if it doesn't appear to already
if ! grep -P PGDATA ~postgres/.bash_profile>/dev/null; then
echo PGDATA=/var/lib/pgsql/9.0/data >>~postgres/.bash_profile;
echo export PGDATA >>~postgres/.bash_profile;
fi

#END MISC SETUP

cd /usr/local/src



#BEGIN INSTALL REPMGR
#install the dependencies
yum -y install libxslt-devel.x86_64 pam-devel.x86_64 openssl-devel.x86_64 readline-devel.x86_64

#install will fail if postgres binaries are not in PATH
PATH=/usr/pgsql-9.0/bin:$PATH
export PATH

#install repmgr
wget --no-check-certificate -O repmgr.tar.gz https://github.com/greg2ndQuadrant/repmgr/tarball/master

#we do this in this way because there is no straightforward way to tell what the extracted tar will be named
mkdir repmgr; tar xzf repmgr.tar.gz -C repmgr
cd repmgr/`\ls -1 repmgr`

make USE_PGXS=1
make USE_PGXS=1 install
#END INSTALL REPMGR
