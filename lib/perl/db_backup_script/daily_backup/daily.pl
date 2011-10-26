#!/usr/bin/perl -l

###############
# Daily Database Schema and Data Backup Script
# backup.pl
#
# For cron:
# cd /path/to/script/directory ; /usr/bin/perl daily.pl >>db_backup_log
#
# cd is not necesarily required unless config.pl is used, but it is harmless and convinient in cron
#

################
# CONFIG
################
# These are all the configuration variable defaults.  If the file config.pl exists in the working directory, it will be included overwriting any defaults
#

$db_name = "postgres";
$db_host = "localhost";
$db_user = "postgres";
#$db_pass = '***'; #uncomment as needed, it is recomended to use a .pgpass file instead

# These databases will not be backed up
@exclude_databases = (
   'template1',
   'template0',
   'postgres',
);

# These schemas will be excluded from individual table backups
@table_backup_exclude_schemas = (
   'information_schema',
   'pg_catalog'
);

$backup_path = '/var/lib/pgsql/9.0/backups'; #directory of backups
$compress_path = "$backup_path/compressed"; #directory for compressed files.

$data_file_name = "data.sql";  #name of data backup file
$schema_file_name = "schema.sql";  #name of schema backup file

$delete_backups_older_than = 10; #days, will not delete anything if there is not more than this number of backups
$max_purge = 3; #maximum number of deletes before purging is stopped

$suppress_output = 0;

$skip_vaccum = 0;
$skip_data = 0;
$skip_schema = 0;
$skip_table_backup = 0;
$skip_purge = 0;
$skip_compress = 0;

$compress_cmd = "cd $backup_path; tar czvf "; #uses gzip.  Worse compression rates but MUCH faster than bzip2.  Speed is the most important thing.
$compressed_file_extension = '.tar.gz';

$time_format = '%Y-%m-%d';  #strftime

# Overwrite any of the above variables in config.pl
require "config.pl" if -e "config.pl";

# END CONFIG
############

use DBI;
use POSIX;

$db = DBI->connect("DBI:Pg:dbname=$db_name;host=$db_host", $db_user, $db_pass, {'RaiseError' => 1}) || die "Unable to connecto to database";

############
# FUNCTIONS
############

sub db_execute{
   my $sql = shift();
   my $_db = shift() || $db;

   echo("database query: `$sql'");

   my $rs = $_db->prepare($sql);
   $rs->execute();

   $rs;
}

sub generate_cmd_connection_string{
   my $name = shift() || $db_name;
   my $host = shift() || $db_host;
   my $user = shift() || $db_user;
   my $port = shift() || $db_port;

   ($user?"-U $user ":'').($host?"-h $host ":'').($port?"-p $port ":'').$name;
}

sub echo{
   unless($suppress_output){
      print $print while $print = shift();
   }

   $print;
}

sub trim{
   $1 if shift() =~ /^\s*(.+)\s*$/;
}

sub get_time{
   my $time = shift();

   $time = [$time?localtime($time):localtime];

   POSIX::strftime($time_format, @$time);
}

sub get_databases{
   my $rs = db_execute("select * from pg_database where datname not in ('" . join("', '", @exclude_databases) . "')");

   my @return;

   push(@return, $ref->{'datname'}) while $ref = $rs->fetchrow_hashref();

   @return;
}

sub backup_database{
   my $db_name = shift();

   my $db_cmd_connection_string = generate_cmd_connection_string($db_name);
   my $time = get_time();

   `mkdir -p $backup_path/$db_name/$time`;

   unless($skip_data){
      my $full_backup_path = "$backup_path/$db_name/$time/$data_file_name";
      echo("Backing up $db_name data to $full_backup_path ...");
      `pg_dump --data-only --disable-triggers $db_cmd_connection_string >$full_backup_path`;
      echo("Data backup complete.");
   }else{
      echo("Skipping data backup of $db_name...");
   }
   
   unless($skip_schema){
      my $full_backup_path = "$backup_path/$db_name/$time/$schema_file_name";
      echo("Backing up $db_name schema to $full_backup_path ...");
      `pg_dump --schema-only --disable-triggers $db_cmd_connection_string >$full_backup_path`;
      echo("Schema backup complete.");
   }else{
      echo("Skipping schema backup of $db_name");
   }
 
   unless($skip_table_backup){
      `mkdir -p $backup_path/$db_name/$time/tables`;

      echo("Backing up individual tables for $db_name...");

      #using .pgpass is recomended so that complications arrise at this point of the script, where $db_pass may differ from the password of my $db_name
      my $db = DBI->connect("DBI:Pg:dbname=$db_name;host=$db_host", $db_user, $db_pass, {'RaiseError' => 1}) || die "Unable to connec to to database";

      my $rs = db_execute("select schemaname, tablename from pg_tables where schemaname not in ('" . join("', '", @table_backup_exclude_schemas) . "')", $db);

      my @tables;

      push(@tables, $ref) while $ref = $rs->fetchrow_hashref();

      unless($skip_data){
         echo("Backing up individual tables' data for $db_name");         

         foreach(@tables){
            my $tablename = $_->{'tablename'};
            my $schemaname = $_->{'schemaname'};

            my $full_backup_path = "$backup_path/$db_name/$time/tables/$schemaname.$tablename-data.sql";

            echo("backing up data for table $schemaname.$tablename...");
            `pg_dump --data-only --disable-triggers --table=$tablename $db_cmd_connection_string >$full_backup_path`;
            echo("Finished backing up data for table $schemaname.$tablename.");
         }

         echo("Finished backing up individual tables' data for $db_name.");
      }else{
         echo("Skipping individual table data backup for $db_name...");
      }

      unless($skip_schema){
         echo("Backing up individual tables' schema for $db_name...");

         foreach(@tables){
            my $tablename = $_->{'tablename'};
            my $schemaname = $_->{'schemaname'};

            my $full_backup_path = "$backup_path/$db_name/$time/tables/$schemaname.$tablename-schema.sql";

            echo("backing up schema for table $schemaname.$tablename...");
            `pg_dump --schema-only --disable-triggers --table=$tablename $db_cmd_connection_string >$full_backup_path`;
            echo("Finished backing up schema for table $schemaname.$tablename.");
         }

         echo("Finished backing up individual tables' schema for $db_name.");
      }else{
         echo("Skipping individual table schema backup for $db_name...");
      }

      $db->disconnect();
      
      echo("Finished backing up individual tables for $db_name.");
   }else{
      echo("Skipping backup of individual tables for $db_name...");
   }

   1;
}

sub compress_backups{
   my $db_name = shift();

   unless($skip_compress){
      echo("Compressing $db_name backups...");  

      `mkdir -p $compress_path/$db_name`;

      my @ls = `ls -1 $backup_path/$db_name`;
      foreach(@ls){
         $_ = trim($_);
         if($_ =~ /\d\d\d\d\-\d\d\-\d\d$/){
            my $compress_file_path;
            unless(-e ($compress_file_path = "$compress_path/$db_name/$_" . $compressed_file_extension)){
               echo("Compressing $backup_path/$db_name/$_ to $compress_file_path ...");
               #!!! This command structure assumes we are cded to $backup_path
               `$compress_cmd $compress_file_path $db_name/$_`;
               echo("Finished compressing $backup_path/$db_name/$_ .");
            }else{
               echo("Already compressed $backup_path/$db_name/$_ ...");
            }
         }
      } 
 
      echo("Finished compressing $db_name backups.");  
   }else{
      echo("Skipping compression of $db_name...");
   }
}

sub delete_old{
   my $db_name = shift();

   my $delete_older_than = get_time(time - ($delete_backups_older_than * 86400)); #seconds per day

   unless($skip_purge){
      foreach(($backup_path, $compress_path)){	
         my $cur_path = $_;

         echo("Purging old $db_name backups at $cur_path/$db_name ...");

         my @ls = `ls -1 $cur_path/$db_name`;
         my $count = 0;
 
         foreach(@ls){
            $_ = trim($_);

            $count++ if $_ =~ /^\d\d\d\d\-\d\d\-\d\d/; #so we don't accidentally count non-backups
         }

         if($count < $delete_backups_older_than){
            echo("Not enough backups to start purging.");
            next;
         }

         $delete_count = 0;

         foreach(@ls){
            last if $delete_count >= $max_purge;
            (`rm -rf $cur_path/$db_name/$_`, $delete_count++, echo("deleting $backup_path/$db_name/$_")) if $_ lt $delete_older_than && $_ =~ /^\d\d\d\d-\d\d\-\d\d/; #the regexp so we don't accidentally delete non-backups
         }

         echo("Purge of $cur_path/$db_name complete.");
      }
   }else{
      echo("Skipping purge of $db_name...");
   }
   
   1;
}

sub handle_database{
   my $db_name = shift();

   echo("Handling $db_name...");

   backup_database($db_name);
   delete_old($db_name);
   compress_backups($db_name);

   echo("Finished handling $db_name.");

   1;
}

sub vacuum{
   unless($skip_vaccum){
      echo("vacuumdb started...");
      `vacuumdb -a -z -U $db_user`;
      echo("vacuumdb finished.");
   }else{
      echo("Skipping vacuumdb...");
   }

   1;
}

# END FUNCTIONS
###############

###############
# SCRIPT
###############

vacuum();

handle_database($_) foreach get_databases();

$db->disconnect();
