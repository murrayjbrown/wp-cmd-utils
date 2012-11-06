#! /usr/bin/php -q
<?php
/**
 *
 * wpdb_replace.php - search and replace string in database tables for Wordpress site
 *                    (invoked via wpdb-replace bash script)
 *
 * Synopsis: wpdb_replace.php <srch-string> <repl-string>
 *
 * Input: Arguments -
 *              <srch-string> - target string to be replaced
 *              <repl-string> - replacement string value
 *        environment variables (per wpdb-replace shell script)
 *              $DbName - database name
 *              $DbTabs - database table names (space-separated list)
 *              $dbUser - database user
 *              $DbPass - database password
 * InOut:  MySQL  - database content
 *
 * Author: Murray J. Brown <mjb@mjbrown.com>
 * 
 * License: GPLv2
 *
 * Provenance: Search/replace logic derived from core functionality of
 * "Safe Search and Replace on Database with Serialized Data v2.0.1"
 * first written 2009-05-25 by David Coveney of Interconnect IT Ltd (UK)
 * (http://www.davidcoveney.com or http://www.interconnectit.com and
 * released under the WTFPL: http://sam.zoy.org/wtfpl/), which was
 * adapted for CLI and updated with PDO API for MySQL database.
 *
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
		elseif ( is_string( $data ) ) {
				$data = str_replace( $from, $to, $data );
		}

		if ( $serialised ) {
			return serialize( $data );
        }

	} catch( Exception $error ) {

	}

	return $data;
}

/**
 * Walk selected database tables and then walk every row and column
 * replacing all occurences of a string with another.
 * We split large tables into 50,000 row blocks when dealing with
 * them to save on memmory consumption.
 */

$errors = array( );

// DB details
$char = 'utf8';
$host = 'localhost';
$dbnm = getenv('DbName');
$tabs = getenv('DbTabs');
$user = getenv('DbUser');
$pass = getenv('DbPass');

// Excluded columns - excepted from search/replace operation
$exclude_cols = array( 'guid' );

// Get substitution strings
if ( 3 > $argc ) { // command orig-string replacement-string
    print "wpdb_replace.php: too few arguments!\n";
    exit( 1 );
}
if (3 <  $argc ) { // command orig-string replacement-string
    print "wpdb_replace.php: too many arguments!\n";
    exit( 1 );
}

$srch = $argv[ 1 ];
$repl = $argv[ 2 ];

//printf("Substituting '%s' for '%s' in database '%s'.\n", $repl, $srch, $dbnm);

// Convert table list string to array
$tables = explode(' ', $tabs);
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

// Open db connection
try {
    $dbh = new PDO("mysql:host=$host;dbname=$dbnm", $user, $pass );
    /*
    $rows = $dbh->query('SELECT * FROM ');
    foreach($rows as $row) {
        print "$row\n";
    }
    */

} catch (PDOException $e) {
    print "wpdb_replace.php: PDO error - $e->getMessage()";
    die();
}

@ set_time_limit( 60 * 10 );
// Try to push the allowed memory up, while we're at it
@ ini_set( 'memory_limit', '1024M' );

// Process the tables
if ( isset( $dbh ) ) {
	$report = array( 'tables' => 0,
					 'rows' => 0,
					 'change' => 0,
					 'updates' => 0,
					 'start' => microtime( ),
					 'end' => microtime( ),
					 'errors' => array( ),
					 );

	if ( is_array( $tables ) && ! empty( $tables ) ) {
		foreach( $tables as $table ) {
            //print "TABLE: $table\n";
			$report[ 'tables' ]++;
			$columns = array( );

			// Get a list of columns in this table
            try {
		        $query = $dbh->prepare( "DESCRIBE $table" );
                $query->execute();
			    while( $column = $query->fetch() ) {
				    $columns[ $column[ 'Field' ] ] = $column[ 'Key' ] == 'PRI' ? true : false;
				    //printf("Field: '%s', Key '%s'\n", $column[ 'Field' ], $column[ 'Key' ]);
                }
            } catch (PDOException $e) {
                print "wpdb_replace.php: PDO error - $e->getMessage()";
                die();
            }

            // Fetch the number of rows in the table.
            // Large tables wlll be processed in chunks
            // (per enhancement by Simon Wheatley).
            $query = $dbh->prepare( "SELECT COUNT(*) FROM $table" );
            $query->execute();
			$rows_result = $query->fetch();
			$row_count = $rows_result[ 0 ];

			if ( $row_count == 0 ) {
				continue;
            }

			$page_size = 50000;
			$pages = ceil( $row_count / $page_size );

			for( $page = 0; $page < $pages; $page++ ) {

				$current_row = 0;
				$start = $page * $page_size;
				$end = $start + $page_size;
				// Get a chunk of the table content
				$query = $dbh->prepare( sprintf( 'SELECT * FROM %s LIMIT %d, %d', $table, $start, $end ) );
                $query->execute();
				if ( ! $query ) {
					$report[ 'errors' ][] = $dbh->errorCode();
                }

                // Process each row in table chunk
                while ( $row = $query->fetch() ) {

					$report[ 'rows' ]++; // Increment the row counter
					$current_row++;

					$update_fields = array( );
					$where_fields = array( );
					$upd = false;

					foreach( $columns as $column => $primary_key ) {

						if ( in_array( $column, $exclude_cols ) ) {
							continue;
                        }

						$edited_data = $data_to_fix = $row[ $column ];

						// Run a search replace on the data that'll respect the serialisation.
						$edited_data = recursive_unserialize_replace( $srch, $repl, $data_to_fix );

						// Something was changed
						if ( $edited_data != $data_to_fix ) {
							$report[ 'change' ]++;
							$update_fields[] = "$column='$edited_data'";
							$upd = true;
						}

						if ( $primary_key ) {
							$where_fields[] = "$column='$data_to_fix'";
                        }
					}

					if ( $upd && ! empty( $where_fields ) ) {
                        $update_set_clause = implode(', ', $update_fields);
                        $update_where_clause = implode(' AND ', array_filter($where_fields) );
						//echo "dbh->prepare( 'UPDATE $table SET $update_set_clause WHERE $update_where_clause' )\n";
						$update = $dbh->prepare( "UPDATE $table SET $update_set_clause WHERE $update_where_clause" );
                        $result = $update->execute();
						if ( ! $result ) {
							$report[ 'errors' ][] = $dbh->errorCode();
                        }
                        else {
							$report[ 'updates' ]++;
                        }
                    }
                    elseif ( $upd ) {
						$report[ 'errors' ][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $current_row );
					}

				}
			}
		}

	}
	$report[ 'end' ] = microtime( );
}

// Output any errors encountered during the db work.
/*
if ( ! empty( $report[ 'errors' ] ) && is_array( $report[ 'errors' ] ) ) {
    print "Errors: ";
	foreach( $report[ 'errors' ] as $error ) {
		printf("%d ", $error);
    }
}
*/

// Calc the time taken.
$time = array_sum( explode( ' ', $report[ 'end' ] ) ) - array_sum( explode( ' ', $report[ 'start' ] ) ); 

printf( "Scanned %d tables with %d rows; %d cells changed; %d db updates performed in %f seconds.\n", $report[ 'tables' ], $report[ 'rows' ], $report[ 'change' ], $report[ 'updates' ], $time );

// close database connection
$dbh = null;

?>
