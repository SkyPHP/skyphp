#!/usr/bin/perl -l

###############
# Frequent Database Schema and Data Backup Script
# backup.pl
#
# For cron:
# cd /path/to/script/directory ; /usr/bin/perl backup.pl >>db_backup_log
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
#$db_port; #if needed
$frequency = '30'; #number of minutes between consecutive updates
#$db_pass is NOT applicable for this script, you must use .pgpass

$frequency_error = '15'; 
#since this is to be run in cron, $frequency_error will allow a backup to occur at most this amount of minutes before the actual backup time
#since it is preferred to have a backup 15 minutes early than two hours late, depending on your cron settings.

$backup_path = '/var/lib/pgsql/9.0/backups/frequent'; #directory of backups
$compress_path = "$backup_path/compressed"; #directory for compressed files.

$data_file_name = "data.sql";
$schema_file_name = "schema.sql";

$delete_backups_older_than = 3; #days, will not delete anything if there is not more than this number of backups
$max_purge = 3; #maximum number of deletes before purging is stopped

$suppress_output = 0;

$skip_data = 0;
$skip_schema = 0;
$skip_compress = 0;
$skip_purge = 0;

$compress_cmd = "cd $backup_path; tar czvf "; #uses gzip.  Worse compression rates but MUCH faster than bzip2.  Speed is the most important thing.
$compressed_file_extension = '.tar.gz';

$time_format = '%Y-%m-%d %H:%M';  #strftime

$tables = [
#   {  #simplest config, uses global variables
#      'name' => 'blog'
#   },
#   {  #customized config, global variables overridden
#      'name' => 'table_name',
#      'schema' => 'schema_name', #optional, default is 'public'
#      'db_name' => 'db_name', #to allow overriding global variables for this table
#      'db_host' => 'db_host',
#      'db_user' => 'db_user',
#      'db_port' => '5432',
#      'frequency' => '60' 
#   }
];

# Overwrite any of the above variables in config.pl
require "config.pl" if -e "config.pl";

# END CONFIG
############

$time_start = time();

use POSIX;
use Time::Local;

############
# FUNCTIONS
############

sub generate_cmd_connection_string{
   my $name = shift() || $db_name;
   my $host = shift() || $db_host;
   my $user = shift() || $db_user;
   my $port = shift() || $db_port;

   ($user?"-U $user ":'').($host?"-h $host ":'').($port?"-p $port ":'').$name;
}

sub generate_cmd_args{
   my $table = shift();
   
   my $table_name = $table->{'name'};
   my $shcema_name = $table->{'schema'} || 'public';

   "--table=$table_name --schema=$schema_name";   
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
   my $format = shift() || $time_format;

   $time = [$time?localtime($time):localtime];

   POSIX::strftime($format, @$time);
}

sub get_unix_time{
   my $time = shift() || time();

   get_time($time, '%s');
}

sub string_to_time{
   my $str = shift();

   my @time;

   if($str =~ /(...)\-(..)\-(..)_(..)(..)/){
      push(@time, 0, $5, $4, $3, $2 - 1, $1);
   }else{
      return(0);
   }

   timelocal(@time);
}

sub get_minutes_since_last_backup{
   my $table = shift();

   my $table_name = $table->{'name'};
   my $schema_name = $table->{'schema'} || 'public';

   my $dbname = $table->{'db_name'} || $db_name;
  
   my @backups = <$backup_path/$dbname/*/$schema_name.$table_name>;

   unless(scalar(@backups)){
      echo("There do not appear to be any backups for $shema_name.$table_name yet.");
      return(-1);
   }

   my $last_backup = string_to_time(pop(@backups));

   (get_unix_time() - $last_backup) / 60;   
}

sub backup_table{
   my $table = shift();

   my $freq = $table->{'frequency'} || $frequency;

   my $minutes_since_last_backup = get_minutes_since_last_backup($table);

   my $table_name = $table->{'name'};
   my $schema_name = $table->{'schema'} || 'public'; 

   unless($minutes_since_last_backup > ($freq - $frequency_error) || $minutes_since_last_backup < 0){
      echo("Skipping $schema_name.$table_name, backup time not yet reached...");
      echo('Next backup in approximately ' . ($freq - $minutes_since_last_backup) . ' minutes.');
      return(0);
   }

   my $dbname = $table->{'db_name'} || $db_name;
   my $dbhost = $table->{'db_host'} || $db_host;
   my $dbuser = $table->{'db_user'} || $db_user;
   my $dbport = $table->{'db_port'} || $db_port;

   my $db_cmd_connection_string = generate_cmd_connection_string($dbname, $dbhost, $dbuser, $dbport);
   my $db_cmd_args = generate_cmd_args($table);

   my $time = get_time($time_start);

   `mkdir -p "$backup_path/$dbname/$time"`;

   unless($skip_data){
      my $full_backup_path = "$backup_path/$dbname/$time/$schema_name.$table_name-data.sql";
      echo("Backing up $dbname $schema_name.$table_name data to $full_backup_path ...");
      `pg_dump --data-only --disable-triggers $db_cmd_args $db_cmd_connection_string >"$full_backup_path"`;
      echo("Data backup complete.");
   }else{
      echo("Skipping data backup of $db_name $schema_name.$table_name...");
   }
   
   unless($skip_schema){
      my $full_backup_path = "$backup_path/$dbname/$time/$schema_name.$table_name-schema.sql";
      echo("Backing up $db_name $schema_name.$table_name schema to $full_backup_path ...");
      `pg_dump --schema-only --disable-triggers $db_cmd_args $db_cmd_connection_string >"$full_backup_path"`;
      echo("Schema backup complete.");
   }else{
      echo("Skipping schema backup of $db_name $schema_name.$table_name...");
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
         if($_ =~ /\d\d\d\d\-\d\d\-\d\d_\d\d\d\d$/){
            my $compress_file_path;
            unless(-e ($compress_file_path = "$compress_path/$db_name/$_" . $compressed_file_extension)){
               echo("Compressing $backup_path/$db_name/$_ to $compress_file_path ...");
               #!!! This command structure assumes we are cded to $backup_path
               `$compress_cmd "$compress_file_path" "$db_name/$_"`;
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

         my @ls = `ls -1 "$cur_path/$db_name"`;
         my $count = 0;
 
         foreach(@ls){
            $_ = trim($_);

            $count++ if $_ =~ /^\d\d\d\d\-\d\d\-\d\d_\d\d\d\d/; #so we don't accidentally count non-backups
         }

         if($count < $delete_backups_older_than){
            echo("Not enough backups to start purging.");
            next;
         }

         $delete_count = 0;

         foreach(@ls){
            last if $delete_count >= $max_purge;
            (`rm -rf "$cur_path/$db_name/$_"`, $delete_count++, echo("deleting $backup_path/$db_name/$_")) if $_ lt $delete_older_than && $_ =~ /^\d\d\d\d-\d\d\-\d\d_\d\d\d\d/; #the regexp so we don't accidentally delete non-backups
         }

         echo("Purge of $cur_path/$db_name complete.");
      }
   }else{
      echo("Skipping purge of $db_name...");
   }
   
   1;
}

%databases;

sub handle_table{
   my $table = shift();

   $table->{'schema'} || ($table->{'schema'} = 'public');

   echo('Handling ' . $table->{'name'} . '...');

   backup_table($table);
 
   %databases->{$table->{'db_name'} || $db_name} = 1;

   echo('Finished handling ' . $table->{'name'} . '.');

   1;
}

# END FUNCTIONS
###############

###############
# SCRIPT
###############

handle_table($_) foreach @$tables;

(delete_old($_), compress_backups($_)) foreach keys %databases;




