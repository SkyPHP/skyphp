$db_name = "postgres";
$db_host = "localhost";
$db_user = "postgres";
#$db_port; #if needed
$frequency = '30'; #number of minutes between consecutive backups
#$db_pass is NOT applicable for this script, you must use .pgpass

$frequency_error = '15';
#since this is to be run in cron, $frequency_error will allow a backup to occur at most this amount of minutes before the actual backup time
#since it is preferred to have a backup 15 minutes early than two hours late, depending on your cron settings.
#if a frequency is less than $frequency_error, backups will always occur

$backup_path = '/var/lib/pgsql/9.0/backups/frequent'; #directory of backups
$compress_path = "$backup_path/compressed"; #directory for compressed files.

$delete_backups_older_than = 3; #days, will not delete anything if there is not more than this number of backups
$max_purge = 3; #maximum number of deletes before purging is stopped

$suppress_output = 0;

#$skip_data = 1;
#$skip_schema = 1;
#$skip_compress = 1;
#$skip_purge = 1;

$compress_cmd = "cd $backup_path; tar czvf "; #uses gzip.  Worse compression rates but MUCH faster than bzip2.  Speed is the most important thing.
$compressed_file_extension = '.tar.gz';

$time_format = '%Y-%m-%d %H:%M';  #strftime

$tables = [
#   {  #simplest config
#      'name' => 'table_name'
#   },
#   {  #more customized, global variables overridden
#      'name' => 'table_name',
#      'schema' => 'schema_name', #optional, default is 'public'
#      'db_name' => 'db_name', #to allow overriding global variables for this table
#      'db_host' => 'db_host',
#      'db_user' => 'db_user',
#      'db_port' => '5432',
#      'frequency' => '60' 
#   }
];

