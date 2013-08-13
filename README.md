# Repository

* [github repo](https://github.com/murrayjbrown/wp-cmd-utils)

# Description

This is a collection of bash and php command-line scripts to assist with
the administration of Wordpress databases and site document files

# Author

* murrayjbrown - Murray J. Brown <mjb@mjbrown.com>

# License

* GNU Public License Version 2.0 (GPLv2)
	
	This is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 2 of the License, or
    (at your option) any later version.

    It is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

# Contents:

- license.txt         - Full text of GPL Version 2 license 
- README.txt          - This file
- wpdb-copy           - Copy existing Wordpress site tables for a new site
- wpdb-delete         - Delete Wordpress site tables from database
- wpdb-export         - Export Wordpress site tables into SQL dump file
- wpdb_functions.sh   - Common utility functions used by scripts
- wpdb-import         - Import Wordpress site tables from SQL dump file
- wpdb-list           - List Wordpress site prefixes (optionally tables) in database
- wpdb-move           - Rename Wordpress site (table prefix) in database
- wpdb-replace        - Full-text search & replace in database site tables
- wpdb_replace.php    - Utility functions called by wpdb-replace script
- wpdb-urls           - List/edit HOME & SITEURL fields wor Wordpress site
- wp-protect          - Set protective attributes & permissions on Wordpress files
- wp-unprotect        - Unset protective attributes & permissions on Wordpress files
 
# Notes

An earlier version of wpdb-replace tool, which had been previously deprecated,
has been re-written based on code adapted from an enhanced version of tools at
https://github.com/interconnectit/Search-Replace-DB.git dated 2013-07-18.

