## Chrome Data Migration Script

cd-migration-tool is a PHP library built for Convertus Digital to migrate chrome data via a FTP connection to the Convertus RDS cluster.

### Installation

- You'll need to run this folder in an apache distribution like XAMPP or WAMP
- Clone the `config-example.php` file into a `config.php` and specify environment variables appropriately.

### Directory Layout

```bash
├── .vscode/                # Prettier setting with linting for front-end/back-end.
├── includes/               # Core files for the front-end and back-end of this tool.
    ├── css/                # Styling for the front-end.
    ├── js/                 # Client side javascript to call PHP scripts via jQuery ajax calls. 
    ├── php/                # Migration scripts.
├── scssphp/                # SCSS compiler logic.
├── temp/                   # Temporary files, specifically images migrated.
├── compiler.php            # Run the SCSS compiler for front-end styles.
├── config-example.php      # Sample file for the PHP environment variables to define.
├── cron.txt                # All cron outputs are logged in this text file.
├── error_log.txt           # All script errors encountered are outputted into this text file.
```