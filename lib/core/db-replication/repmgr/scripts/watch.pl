#!/usr/bin/perl -l

#use strict;

$cmd = shift;
$trigger = shift;
	
print "Command: $cmd";
print "Exit Trigger: $trigger";

if($pid = open(CMD, $cmd . "|")){
   print "We're in";
   select CMD;
   $| = 1;
   while(<CMD>){
      print STDOUT $_;

      if($_ =~ /.*\Q$trigger\E.*/){
         close CMD;
         kill 9, $pid;
         print STDOUT "Found the trigger string.";
         print STDOUT "Killing the command...";
         last;
      }
   }
}else{
   die('Could not start.');
}


