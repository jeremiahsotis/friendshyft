# FriendShyft PHPUnit Tests

Comprehensive automated test suite for the FriendShyft volunteer management plugin.

## Prerequisites

- PHP 7.4 or higher
- Composer
- WordPress test library
- MySQL database for testing

## Installation

### 1. Install Composer Dependencies

```bash
cd /path/to/friendshyft/plugin
composer install
```

### 2. Install WordPress Test Library

Run the install script to set up the WordPress testing environment:

```bash
bash tests/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

**Parameters:**
- `wordpress_test` - Test database name (will be created)
- `root` - MySQL username
- `''` - MySQL password (empty in example)
- `localhost` - MySQL host
- `latest` - WordPress version (or specific version like `6.4`)

**Note:** The test database will be wiped clean on each test run.

### 3. Make Install Script Executable

```bash
chmod +x tests/bin/install-wp-tests.sh
```

## Running Tests

### Run All Tests

```bash
composer test
```

Or directly with PHPUnit:

```bash
vendor/bin/phpunit
```

### Run Specific Test File

```bash
vendor/bin/phpunit tests/test-volunteer-crud.php
```

### Run Specific Test Method

```bash
vendor/bin/phpunit --filter test_create_volunteer
```

### Run Tests with Coverage Report

```bash
composer test-coverage
```

This generates an HTML coverage report in the `coverage/` directory.

## Test Suite Overview

### Database Schema Tests (`test-database-schema.php`)
- ✅ Core tables existence (15 tables)
- ✅ Team management tables (3 tables)
- ✅ Email ingestion tables
- ✅ Audit log table
- ✅ Google Calendar tables
- ✅ Feedback system tables (3 tables)
- ✅ Advanced scheduling tables (6 tables)
- ✅ Analytics tables (3 tables)
- ✅ Table structure verification
- ✅ Index verification on key columns

**Total: 28 tables tested**

### Volunteer CRUD Tests (`test-volunteer-crud.php`)
- ✅ Create volunteer
- ✅ Access token generation (64 characters)
- ✅ PIN generation (4 digits)
- ✅ Read volunteer by ID, email, token
- ✅ Update volunteer
- ✅ Delete volunteer
- ✅ Deactivate volunteer (soft delete)
- ✅ Duplicate email detection
- ✅ Pagination
- ✅ Search by name
- ✅ Filter by status

**Total: 15 tests**

### Opportunity CRUD Tests (`test-opportunity-crud.php`)
- ✅ Create opportunity
- ✅ Read opportunity by ID
- ✅ Update opportunity
- ✅ Delete opportunity
- ✅ Status changes (draft → published → cancelled)
- ✅ List upcoming opportunities
- ✅ Filter by program
- ✅ Check available spots
- ✅ Detect full opportunities
- ✅ Increment/decrement spots_filled
- ✅ Search by title

**Total: 14 tests**

### Signup Logic Tests (`test-signup-logic.php`)
- ✅ Create signup
- ✅ Detect time conflicts (overlapping shifts)
- ✅ No conflict for non-overlapping times
- ✅ Prevent duplicate signups
- ✅ Spots_filled increment/decrement
- ✅ Full opportunity detection
- ✅ Signup status transitions
- ✅ List volunteer's signups
- ✅ List opportunity's signups
- ✅ Cancelled signups don't count toward capacity
- ✅ Filter by status

**Total: 14 tests**

### Time Tracking Tests (`test-time-tracking.php`)
- ✅ Record check-in
- ✅ Record check-out and calculate hours
- ✅ Calculate hours for various durations
- ✅ Total hours across multiple shifts
- ✅ Hours for specific date range
- ✅ Round hours to nearest quarter hour
- ✅ List time records for volunteer
- ✅ Prevent duplicate check-ins
- ✅ Monthly hours summary
- ✅ Yearly hours summary
- ✅ Average hours per shift

**Total: 14 tests**

### Badge Awarding Tests (`test-badge-awarding.php`)
- ✅ Award 10-hour badge
- ✅ Award multiple milestone badges (10, 50, 100, 500 hours)
- ✅ Prevent duplicate badge awards
- ✅ List volunteer's badges
- ✅ Badge count for volunteer
- ✅ Award first signup badge
- ✅ Award consistency badge (10 signups)
- ✅ Badge earned_at timestamp

**Total: 10 tests**

### Analytics Tests (`test-analytics.php`)
- ✅ Calculate total volunteer hours
- ✅ Calculate economic value ($31.80/hour)
- ✅ Calculate retention rate
- ✅ Engagement score calculation (0-100)
- ✅ Determine risk level (high/medium/low)
- ✅ No-show prediction (weighted average)
- ✅ Forecast fill rate with time factors
- ✅ Hours by program breakdown
- ✅ Unique volunteer count
- ✅ Confidence score calculation
- ✅ Year-over-year comparison

**Total: 11 tests**

### Email Mocking Tests (`test-email-mocking.php`)
- ✅ Email function called
- ✅ Email parameters correct
- ✅ Confirmation email structure
- ✅ Reminder email structure
- ✅ Cancellation email structure
- ✅ Re-engagement email structure
- ✅ Badge notification email structure
- ✅ Welcome email structure
- ✅ HTML email headers
- ✅ Subject sanitization
- ✅ Body HTML escaping
- ✅ Multiple recipients

**Total: 13 tests**

## Grand Total: 103 Tests

## Continuous Integration

### GitHub Actions Example

Create `.github/workflows/tests.yml`:

```yaml
name: PHPUnit Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:5.7
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'

      - name: Install Composer dependencies
        run: composer install

      - name: Install WordPress test library
        run: bash tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest

      - name: Run tests
        run: composer test
```

## Debugging Tests

### Enable Verbose Output

```bash
vendor/bin/phpunit --verbose
```

### Stop on First Failure

```bash
vendor/bin/phpunit --stop-on-failure
```

### Show Test Execution Details

```bash
vendor/bin/phpunit --testdox
```

Example output:
```
Volunteer CRUD
 ✔ Create volunteer
 ✔ Access token generation
 ✔ PIN generation
 ✔ Read volunteer by ID
```

### Debug Specific Test

```bash
vendor/bin/phpunit --filter test_create_volunteer --debug
```

## Writing New Tests

### Test Class Template

```php
<?php
class Test_My_Feature extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();
        // Setup code here
    }

    public function tearDown(): void {
        // Cleanup code here
        parent::tearDown();
    }

    public function test_my_feature_works() {
        // Arrange
        $expected = 'value';

        // Act
        $actual = my_function();

        // Assert
        $this->assertEquals($expected, $actual);
    }
}
```

### Common Assertions

```php
// Equality
$this->assertEquals($expected, $actual);
$this->assertNotEquals($expected, $actual);

// Booleans
$this->assertTrue($condition);
$this->assertFalse($condition);

// Null checks
$this->assertNull($value);
$this->assertNotNull($value);

// Numeric comparisons
$this->assertGreaterThan(10, $value);
$this->assertLessThan(100, $value);

// String checks
$this->assertStringContainsString('needle', $haystack);
$this->assertStringStartsWith('prefix', $string);

// Array checks
$this->assertContains($item, $array);
$this->assertCount(5, $array);
$this->assertArrayHasKey('key', $array);

// Instance checks
$this->assertInstanceOf(ClassName::class, $object);
```

## Best Practices

1. **Isolation**: Each test should be independent
2. **Clean State**: Use setUp() and tearDown() properly
3. **Clear Names**: Test method names should describe what they test
4. **AAA Pattern**: Arrange, Act, Assert
5. **One Assertion**: Test one thing per test method
6. **Mock External Dependencies**: Don't rely on external services
7. **Fast Tests**: Keep tests fast (<100ms per test)
8. **Readable**: Tests serve as documentation

## Troubleshooting

### Error: "Could not find WordPress test library"

Set the WP_TESTS_DIR environment variable:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

### Error: "Database connection failed"

Check MySQL credentials in the install script:

```bash
bash tests/bin/install-wp-tests.sh wordpress_test YOUR_USER YOUR_PASS localhost latest
```

### Error: "Class not found"

Ensure the plugin is properly loaded in `tests/bootstrap.php`.

### Tests Running Slow

- Reduce database queries
- Mock external API calls
- Use transactions for faster rollback

## Coverage Goals

- **Unit Tests**: 80%+ code coverage
- **Integration Tests**: All critical paths covered
- **Edge Cases**: Common error scenarios tested

## Next Steps

1. ✅ Run all tests: `composer test`
2. ✅ Check coverage: `composer test-coverage`
3. ✅ Fix any failing tests
4. ✅ Add tests for new features
5. ✅ Set up CI/CD pipeline
6. ✅ Monitor test execution time

## Support

For questions about the test suite:
- Review existing test files for examples
- Check PHPUnit documentation: https://phpunit.de/
- WordPress testing handbook: https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/

---

**Happy Testing!** 🧪
