host := "127.0.0.1"
port := "8000"

default:
    @just --list

# Start the PHP built-in server for local development.
serve host=host port=port:
    php -S {{host}}:{{port}} router.php
