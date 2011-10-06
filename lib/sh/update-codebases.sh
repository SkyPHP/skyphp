#! /bin/bash

P="./"
echo $P

for i in $( ls -d -- */ ); do
        if [ -d $P/$i.git ]; then
                cd $P/$i
                git pull
                echo ---------------------------------
                echo git pull $i
                cd ../
        fi
        if [ -d $P/$i.svn ]; then
                svn up $P/$i
                echo ---------------------------------
                echo svn up $i
        fi
done