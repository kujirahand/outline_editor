host := "127.0.0.1"
port := "8000"

default:
    @just --list

# Start the PHP built-in server for local development.
serve host=host port=port:
    php -S {{host}}:{{port}} router.php

# Run all tests.
test: test-api test-app

# Run HTTP API tests.
test-api:
    php tests/api/run.php

# Run browser app tests. Requires Playwright and Chrome by default.
test-app:
    python tests/app/run.py
