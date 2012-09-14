# Tests

There are two kind of tests. The folder ``vendor/phpcr/phpcr-api-tests`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

Unit tests for the jackalope mongodb backend implememtation are in
tests/Jackalope/Transport/Mongodb.

Note that the base jackalope repository contains some unit tests for jackalope in
its tests folder.


## API test suite

The phpunit.xml.dist is configured to run all tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/MongodbImplementationLoader.php
file to see which features are currently skipped.

You should only see success or skipped tests, no failures or errors.


# Setup


**Careful: You should create a separate database for the tests, as the whole
database is dropped each time you run a test.**

Test fixtures for functional tests are written in the JCR System XML format. Use
the converter script ``tests/generate_fixtures.php`` to prepare the fixtures
for the tests.

The converted fixtures are written into tests/fixtures/mongodb. The
converted fixtures are not tracked in the repository, you should regenerate
them whenever you update the vendors through composer.

To run the tests:

    cd /path/to/jackalope/tests
    cp phpunit.xml.dist phpunit.xml
    # adjust phpunit.xml as necessary
    ./generate_fixtures.php
    phpunit

