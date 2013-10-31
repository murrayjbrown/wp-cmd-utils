#!/bin/bash
#
# wpdb_functions - helper functions for Wordpress database scripts
#
# Assumes MySQL database
#
# Author: Murray J. Brown <mjb@mjbrown.com>
#
# License: GPLv2 -- see license.txt file.
# WARNING: USE AT YOUR OWN RISK.
#

# Database table name prefix delimiter 
#-- Note: A delimiter is appended by these scripts for convenience.
#         (By convention, the author uses an underscore delimiter.)
DbPrefDelim="_";

function wpdb_credentials {
    #------------------------------------------------------------------
    # Synopsis: wpdb_credentials <db_name>
    #
    # Purpose:  Get user authentication credentials for database
    # Params:   database name
    # Input:    database user & password 
    # Output:   database credentials (DbName, DbUser, DbPass)
    #------------------------------------------------------------------
    # initialize output variables with default values
    DbName=
    DbUser=root
    DbPass=
    #
    # Parse options
    #-- database name
    if [ "" == "$1" ]; then
        echo "Error: wpdb_credentials - missing database name.";
        exit 1;
    fi
    DbName="$1";
    #
    # Database user authentication credentials
    echo "Enter MySQL user information";
    #-- user name
    #read -p "Username [$DbUser]: " user;
    #if [ "" != "$user" ]; then
    #    DbUser="$user";
    #fi
    #-- user password
    /bin/stty -echo;
    read -p "Password: " DbPass; 
    echo "";
    /bin/stty echo;
}

function wpdb_enum_tables {
    #------------------------------------------------------------------
    # Synopsis: wpdb_enum_tables <prefix>
    #
    # Purpose:  Enumerate tables of given prefix in database
    # Params:   Wordpress site prefix 
    # Input:    database credentials (DbName, DbUser, DbPass)
    # Output:   string of matching table names (DbTables)
    #------------------------------------------------------------------
    local prefix
    local count
    if [ "" == "$1" ]; then
        echo "Error: wpdb_enum_tables - missing site prefix";
        exit 1;
    fi
    prefix="$1";
    count=0;
    DbTables=('');
    tfile="/tmp/wpdb-$RANDOM.txt";
    /usr/bin/mysql --user=$DbUser --password=$DbPass $DbName <<< "SHOW TABLES;" |/usr/bin/buthead 1 >$tfile;
    while read LINE; do
        if [ "$prefix" == "${LINE:0:${#prefix}}" ]; then
            DbTables[$count]=$LINE;
            ((count++));
        fi
    done < $tfile;
    rm $tfile;
}

function wpdb_url_host() { 
    #------------------------------------------------------------------
    # Synopsis: wpdb_url_host <url>
    #
    # Purpose:  Extract host part of given url
    # Params:   URL
    # Output:   host
    #------------------------------------------------------------------
	url=$1
	# Strip https scheme
	if [[ 8 -lt ${#url} && 'https://' = ${url:0:8} ]]; then
		url=${url:8}
	fi
	# Strip http scheme
	if [[ 7 -lt ${#url} && 'http://' = ${url:0:7} ]]; then
		url=${url:7}
	fi
	# Strip path info
	i=`expr index $url /`
	if [[ 0 -lt $i ]]; then
		url=${url:0:$i-1}
	fi
	# Strip port info
	i=`expr index $url :`
	if [[ 0 -lt $i ]]; then
		host=${url:0:$i-1}
	else
		host=${url}
	fi
	# return host
	echo $host
}

