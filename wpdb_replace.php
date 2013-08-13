#! /usr/bin/php -q
<?php
/**
 *
 * wpdb_replace.php - Search and replace string in database tables for Wordpress site
 *                    (invoked via wpdb-replace bash script)
 *
 * Synopsis: 
 *
 *		wpdb_replace.php [--dry-run] [--guid] {-s|--search} <srch> {-r|--replace} <repl>
 *
 * Input: 
 *
 *		Command-line arguments
 *			--dry-run				- do not change database
 *			--guid					- search/replace GUID column (default: excluded)
 *			--help					- print help text
 *			{-s|--search}  <srch>	- search string (target to be replaced)
 *			{-r|--replace} <repl>	- replacement string value
 *
 *        
 *      Environment variables set by bash script:
 *			$DbName - database name
 *			$DbUser - database user
 *			$DbPass - user password
 *			$DbTbls - table names (space-separated list)
 *
 * In-Out:
 *
 *		MySQL database: indicated Wordpress site tables
 *
 *
 * Author: Murray J. Brown <mjb@mjbrown.com>
 * 
 * License: GPLv2 -- see license.txt file.
 *
 * Version 1.0
 * - Adapted from enhanced version of command-line tool at
 *   https://github.com/interconnectit/Search-Replace-DB.git
 *   dated 2013-07-18
 *
 * Version 0.x - Initial (unnumbered) version
 * - Deprecated upon discovery of database corruption. (Oops.)
 * - Derived from source cited (below) for original provenance.
 *
 * Credits and Provenance: Thanks to David Coveney et al.
 *	 The search & replace logic was originally derived from core functions
 *	 of "Safe Search and Replace on Database with Serialized Data v2.0.1"
 *   first written 2009-05-25 by David Coveney of Interconnect IT Ltd (UK)
 *   (http://www.davidcoveney.com or http://www.interconnectit.com and
 *   released under the WTFPL: http://sam.zoy.org/wtfpl/), which was
 *   later adapted for CLI and updated with PDO API for MySQL database.
 *   More recent versions have been derived from source maintained at
 *   https://github.com/interconnectit/Search-Replace-DB.git
 */

/*
 * ==============================================================
 * Begin: Excerpt from searchreplacedb2.php
 * ==============================================================
*/

/**
 * Take a serialised array and unserialise it replacing elements as needed and
 * unserialising any subordinate arrays and performing the replace on those too.
 *
 * @param string $from       String we're looking to replace.
 * @param string $to         What we want it to be replaced with
 * @param array  $data       Used to pass any subordinate arrays back to in.
 * @param bool   $serialised Does the array passed via $data need serialising.
 *
 * @return array	The original array with all elements replaced as needed.
 */
function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {

	// some unseriliased data cannot be re-serialised eg. SimpleXMLElements
	try {

		if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
			$data = recursive_unserialize_replace( $from, $to, $unserialized, true );
		}

		elseif ( is_array( $data ) ) {
			$_tmp = array( );
			foreach ( $data as $key => $value ) {
				$_tmp[ $key ] = recursive_unserialize_replace( $from, $to, $value, false );
			}

			$data = $_tmp;
			unset( $_tmp );
		}

		// Submitted by Tina Matter
		elseif ( is_object( $data ) ) {
			$dataClass = get_class( $data );
			$_tmp = new $dataClass( );
			foreach ( $data as $key => $value ) {
				$_tmp->$key = recursive_unserialize_replace( $from, $to, $value, false );
			}

			$data = $_tmp;
			unset( $_tmp );
		}

		else {
			if ( is_string( $data ) )
				$data = str_replace( $from, $to, $data );
		}

		if ( $serialised )
			return serialize( $data );

	} catch( Exception $error ) {

	}

	return $data;
}

/**
 * The main loop triggered in step 5. Up here to keep it out of the way of the
 * HTML. This walks every table in the db that was selected in step 3 and then
 * walks every row and column replacing all occurences of a string with another.
 * We split large tables into 50,000 row blocks when dealing with them to save
 * on memmory consumption.
 *
 * @param mysql  $connection The db connection object
 * @param string $search     What we want to replace
 * @param string $replace    What we want to replace it with.
 * @param array  $tables     The tables we want to look at.
 *
 * @return array    Collection of information gathered during the run.
 */
function icit_srdb_replacer( $connection, $search = '', $replace = '', $tables = array( ) ) {
	global $guid, $exclude_cols, $dry_run_only;

	$report = array( 'tables' => 0,
					 'rows' => 0,
					 'change' => 0,
					 'updates' => 0,
					 'start' => microtime( ),
					 'end' => microtime( ),
					 'errors' => array( ),
					 );
/*
	if ( $dry_run_only ) { 	// Report this as a search-only run.
		$report[ 'errors' ][] = '<span id="search-only>">The dry-run option was checked. No replacements were actually made.</span>';
	}
*/

	if ( is_array( $tables ) && ! empty( $tables ) ) {
		foreach( $tables as $table ) {
			$report[ 'tables' ]++;

			$columns = array( );

			// Get a list of columns in this table
		    $fields = mysql_query( 'DESCRIBE ' . $table, $connection );
			if ( ! $fields ) {
				$report[ 'errors' ][] = mysql_error( );
				continue;
			}
			while( $column = mysql_fetch_array( $fields ) )
				$columns[ $column[ 'Field' ] ] = $column[ 'Key' ] == 'PRI' ? true : false;

			// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
			$row_count = mysql_query( 'SELECT COUNT(*) FROM ' . $table, $connection );
			$rows_result = mysql_fetch_array( $row_count );
			$row_count = $rows_result[ 0 ];
			if ( $row_count == 0 )
				continue;

			$page_size = 50000;
			$pages = ceil( $row_count / $page_size );

			for( $page = 0; $page < $pages; $page++ ) {

				$current_row = 0;
				$start = $page * $page_size;
				$end = $start + $page_size;
				// Grab the content of the table
				$data = mysql_query( sprintf( 'SELECT * FROM %s LIMIT %d, %d', $table, $start, $end ), $connection );

				if ( ! $data )
					$report[ 'errors' ][] = mysql_error( );

				while ( $row = mysql_fetch_array( $data ) ) {

					$report[ 'rows' ]++; // Increment the row counter
					$current_row++;

					$update_sql = array( );
					$where_sql = array( );
					$upd = false;

					foreach( $columns as $column => $primary_key ) {
						if ( $guid == 1 && in_array( $column, $exclude_cols ) )
							continue;

						$edited_data = $data_to_fix = $row[ $column ];

						// Run a search replace on the data that'll respect the serialisation.
						$edited_data = recursive_unserialize_replace( $search, $replace, $data_to_fix );

						// Something was changed
						if ( $edited_data != $data_to_fix ) {
							$report[ 'change' ]++;
							$update_sql[] = $column . ' = "' . mysql_real_escape_string( $edited_data ) . '"';
							$upd = true;
						}

						if ( $primary_key )
							$where_sql[] = $column . ' = "' . mysql_real_escape_string( $data_to_fix ) . '"';
					}

					if ($dry_run_only) {
						// nothing for this state
					}
					elseif ( $upd && ! empty( $where_sql ) ) {
						$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
						$result = mysql_query( $sql, $connection );
						if ( ! $result )
							$report[ 'errors' ][] = mysql_error( );
						else
							$report[ 'updates' ]++;

					} elseif ( $upd ) {
						$report[ 'errors' ][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $current_row );
					}

				}
			}
		}

	}
	$report[ 'end' ] = microtime( );

	return $report;
}

/*
 * ==============================================================
 * End: Excerpt from searchreplacedb2.php
 * ==============================================================
*/

function help_die( ) {
	print "Synopsis: wpdb_replace.php [--dry-run] [--guid] {-s|--search} <srch> {-r|--replace} <repl>.";
	die();
}


/*
 * DB specification (from env variables)
*/
$char = 'utf8';
$host = 'localhost';
$dbnm = getenv('DbName'); // database name
$user = getenv('DbUser'); // database user
$pass = getenv('DbPass'); // user password	
$tbls = getenv('DbTbls'); // table names (space-separated list)

/*
 * Command arguments
*/
// Flags for options, all values required 
$shortopts  = "";
$shortopts .= "s:"; // search // $srch
$shortopts .= "r:"; // replace // $rplc
$shortopts .= "ngh"; // dry-run, guid, help

// All long options require values
$longopts  = array(
    "search:", // $srch
    "replace:", // $rplc
    "dry-run", // $dry_run_only
    "guid", // $exclude_cols
    "help", // $help_text
);

// Store arg values 
$arg_count = $_SERVER["argc"];
$args_array = $_SERVER["argv"];
$options = getopt($shortopts, $longopts); // Store array of options and values
// var_dump($options); // return all the values

// help option 
if ( isset($options["help"]) )
	help_die();

// Dry-run option
$dry_run_only = isset($options["n"]) || isset($options["dry-run"]) ? true : false;

// GUID column option: excluded by default
// --guid option means include GUID column in search/replace operations
$exclude_cols = isset($options["g"]) || isset($options["guid"]) ? array( ) : array('guid');

// search string
if (isset($options["s"])) {
  $srch = $options["s"];
}
elseif (isset($options["search"])) {
  $srch = $options["search"];
}
else help_die();

// replacement string
if (isset($options["r"])) {
  $rplc = $options["r"];
}
elseif (isset($options["replace"])) {
  $rplc = $options["replace"];
}
else help_die();

// Convert table list string to array
$tables = explode(' ', $tbls);
if( !is_array($tables) ) {
    print "wpdb_replace.php: no database tables given.\n";
}
/*
else { 
    print "wpdb_replace.php: table array - ";
    foreach( $tables as $table ) {
        print "$table ";
    }
    print "\n";
}
*/

/*
 * Open database connection
*/
$connection = @mysql_connect( $host, $user, $pass );
if ( ! $connection ) {
	$errors[] = mysql_error( );
    echo "MySQL Connection Error: ";
    print_r($errors);
}

/*
 * Execute search & replace operation on database tables
*/
//if ( !$dry_run_only ) { // check if dry-run

	// Print intended substitution
	printf("Substituting '%s' for '%s' in database '%s'.\n", $rplc, $srch, $dbnm);
	echo "\nWorking...";

	@ set_time_limit( 60 * 10 );
	// Try to push the allowed memory up, while we're at it
	@ ini_set( 'memory_limit', '1024M' );

	//
	// Process the tables
	//
	if ( isset( $connection ) ) {
        @mysql_select_db( $dbnm, $connection );
		$report = icit_srdb_replacer( $connection, $srch, $rplc, $tables );
	}
	

	// Output any errors encountered during the db work.
	if ( ! empty( $report[ 'errors' ] ) && is_array( $report[ 'errors' ] ) ) {
		echo "Search/Replace Errors: \n";
		foreach( $report[ 'errors' ] as $error )
			echo $error . "\n";
	}

	// Calc the time taken.
	$time = array_sum( explode( ' ', $report[ 'end' ] ) ) - array_sum( explode( ' ', $report[ 'start' ] ) );
	
	echo "Done.\n\n";
	if ( $dry_run_only ) { 
		print "Dry-run: Records searched only; no replacements were actually made.\n";
	}
	printf( 'Scanned %d tables with a total of %d rows.', $report[ 'tables' ], $report[ 'rows' ] );
	print "\n";
	printf( 'Number of updates: %d; Number of cells changed: %d.', $report[ 'updates' ], $report[ 'change' ] );
	print "\n";
	printf( 'Elapsed time: %f seconds.', $time );
	print "\n\n";
//}

// close database connection
$dbh = null;
?>
