# Authoritative classmap example

This example demonstrates how an authoritative classmap from composer
can be used to limit parsed files:

- `run_example_authoritative.php` will discard `Tests` folder
- `run_example_non_authoritative.php` will try to parse `Tests` folder but throws an exception because of missing PHPUnit (--no-dev)
- `run_example_non_authoritative_with_dev.php` will parse and run `Tests` folder
