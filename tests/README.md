# Test Suite Documentation

This directory contains the test suite for the Karla Delivery Shopware plugin.

## Coverage

We maintain **100% test coverage** for all production code. The CI pipeline will fail if coverage drops below 100%.

- Run tests: `make test`
- Generate coverage: `make coverage`
- View coverage: Open `coverage/html/index.html` in a browser

## Test Structure

```text
tests/
├── Support/                      # Test helpers and builders
│   ├── ConfigBuilder.php             # Builder for SystemConfig mocks
│   └── OrderMockBuilderTrait.php     # Trait for Order entity mocks
├── Subscriber/
│   └── OrderSubscriberTest.php   # Tests for OrderSubscriber
└── KarlaDeliveryTest.php         # Tests for plugin lifecycle
```

## Best Practices

### 1. Use Helpers for Complex Mocks

Instead of creating mocks inline, use the helper traits in `tests/Support/`:

**❌ Bad - Inline mock creation:**

```php
$order = $this->createMock(OrderEntity::class);
$order->method('getOrderNumber')->willReturn('10001');
$order->method('getAmountTotal')->willReturn(100.00);
// ... 20 more lines of mock setup
```

**✅ Good - Using OrderMockBuilderTrait:**

```php
use Karla\Delivery\Tests\Support\OrderMockBuilderTrait;

class MyTest extends TestCase
{
    use OrderMockBuilderTrait;

    public function testSomething()
    {
        $order = $this->createOrderMock(
            orderNumber: '10001',
            status: 'in_progress',
            tags: ['VIP']
        );
    }
}
```

### 2. Use ConfigBuilder for System Configuration

**❌ Bad - Hardcoded config maps:**

```php
$configMock->method('get')->willReturnMap([
    ['KarlaDelivery.config.shopSlug', null, 'testSlug'],
    ['KarlaDelivery.config.apiUsername', null, 'testUser'],
    // ... 15 more lines
]);
```

**✅ Good - Using ConfigBuilder:**

```php
$configMap = ConfigBuilder::create()
    ->withApiConfig('testSlug', 'testUser', 'testKey')
    ->withAllOrderStatusesEnabled()
    ->buildMap();

$configMock->method('get')->willReturnMap($configMap);
```

### 3. Follow AAA Pattern (Arrange-Act-Assert)

Structure tests clearly with comments:

```php
public function testOrderProcessing()
{
    // Arrange
    $order = $this->createOrderMock(status: 'in_progress');
    $event = $this->mockOrderEvent($context, $order);

    // Act
    $this->subscriber->onOrderWritten($event);

    // Assert
    $this->httpClientMock->expects($this->once())
        ->method('request');
}
```

### 4. Name Tests Descriptively

Use descriptive test names that explain the scenario:

**❌ Bad:**

```php
public function testOrder() { }
public function testConfig() { }
```

**✅ Good:**

```php
public function testOrderSkippedWhenStatusNotAllowed() { }
public function testOrderWithCustomerTagsSendsSegments() { }
```

### 5. One Assertion Per Test (When Possible)

Each test should verify one specific behavior:

**❌ Bad - Multiple concerns:**

```php
public function testOrderProcessing()
{
    // Tests order creation AND delivery AND tags AND...
}
```

**✅ Good - Single concern:**

```php
public function testOrderSkippedWhenStatusNotAllowed() { }
public function testOrderWithTagsIncludesSegments() { }
public function testOrderWithDeliveryCreatesTracking() { }
```

### 6. Use Data Providers for Similar Tests

For tests that differ only in input/output:

```php
/**
 * @dataProvider orderStatusProvider
 */
public function testOrderStatusHandling(string $status, bool $shouldProcess)
{
    $order = OrderMockBuilder::create($this)
        ->withStatus($status)
        ->build();

    // ... test logic
}

public static function orderStatusProvider(): array
{
    return [
        'open status' => ['open', false],
        'in_progress status' => ['in_progress', true],
        'completed status' => ['completed', false],
    ];
}
```

### 7. Avoid Testing Implementation Details

Test behavior, not implementation:

**❌ Bad - Testing internal structure:**

```php
public function testPrivateMethodIsCalled() {
    // Using reflection to test private methods
}
```

**✅ Good - Testing observable behavior:**

```php
public function testOrderSendsCorrectApiPayload() {
    $this->httpClientMock->expects($this->once())
        ->method('request')
        ->with($this->callback(function ($options) {
            $body = json_decode($options['body'], true);
            return isset($body['order']['orderNumber']);
        }));
}
```

### 8. Keep Tests Independent

Each test should be able to run independently:

- ✅ Use `setUp()` for common initialization
- ✅ Reset mocks between tests (PHPUnit does this automatically)
- ❌ Don't rely on test execution order
- ❌ Don't share state between tests

### 9. Document Complex Test Logic

Use docblocks to explain non-obvious test scenarios:

```php
/**
 * When an order has customer tags, they should be sent to the API
 * with the prefix "Shopware.customer.tag." to distinguish them
 * from order-level tags.
 */
public function testOrderWithCustomerTags()
{
    // ...
}
```

### 10. Use @codeCoverageIgnore Sparingly

Only exclude code that:

- Requires complex infrastructure (like plugin lifecycle hooks)
- Is framework boilerplate
- Cannot reasonably be tested in unit tests

Document WHY code is excluded:

```php
/**
 * @codeCoverageIgnore
 * This method loads Shopware configuration and requires complex integration test setup.
 */
public function build(ContainerBuilder $container): void
{
    // ...
}
```

## Running Tests

### All Tests

```bash
make test
```

### With Coverage

```bash
make coverage
```

### Specific Test Class

```bash
vendor/bin/phpunit tests/Subscriber/OrderSubscriberTest.php
```

### Specific Test Method

```bash
vendor/bin/phpunit --filter testOrderWithCustomerTags
```

### With Coverage HTML Report

```bash
make coverage
open coverage/html/index.html
```

## Coverage Enforcement

The test suite enforces **100% line and method coverage**. If coverage drops below this threshold:

1. `make coverage` will exit with error code 1
2. CI pipeline will fail
3. You'll see output like:

```text
❌ Line coverage 95.00% is below minimum threshold of 100.00%
```

To fix, add tests for the uncovered code or add `@codeCoverageIgnore` with proper justification.

## Adding New Tests

When adding new tests:

1. **Create the test class** in the appropriate directory
2. **Extend TestCase** and use builders from `tests/Support/`
3. **Follow naming conventions**: `Test` suffix for classes, `test` prefix for methods
4. **Run coverage** to ensure 100% is maintained: `make coverage`
5. **Run all quality checks** before committing: `make check-all`

## Continuous Integration

The CI pipeline runs:

1. ✅ Code style checks (`make lint`)
2. ✅ Static analysis (`make analyse`)
3. ✅ Unit tests (`make test`)
4. ✅ Coverage enforcement (`make coverage`)

All must pass for the build to succeed.

## Questions?

See the [PHPUnit documentation](https://phpunit.de/documentation.html) or review existing tests for examples.
