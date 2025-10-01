# CLAUDE.md - GoKarla Shopware Plugin Developer Guide

This document provides comprehensive context for AI-assisted development of the GoKarla Shopware plugin.

## Project Overview

**Name:** Karla Delivery
**Type:** Shopware 6 Platform Plugin
**License:** Apache 2.0
**Purpose:** Automatically transmit order and shipment events from Shopware to GoKarla API for enhanced post-purchase customer experience

**Official Documentation:** [https://docs.gokarla.io/docs/shop-integrations/shopware]

## Architecture

### Core Components

#### **Main Plugin Class** - [`src/KarlaDelivery.php`](src/KarlaDelivery.php)

- Extends `Shopware\Core\Framework\Plugin`
- Handles plugin lifecycle: install, uninstall, activate, update
- Loads configuration from `Resources/config/packages/*.yaml`

#### **Order Event Subscriber** - [`src/Subscriber/OrderSubscriber.php`](src/Subscriber/OrderSubscriber.php)

- Implements `EventSubscriberInterface`
- Listens to `OrderEvents::ORDER_WRITTEN_EVENT`
- Main business logic for sending order data to GoKarla API
- Handles order placement, fulfillment, and customer segmentation

### Configuration Files

#### **Plugin Config** - [`src/Resources/config/config.xml`](src/Resources/config/config.xml)

- Defines admin panel configuration UI
- API credentials (shop slug, username, API key, URL)
- Event trigger configuration (order/delivery statuses)
- Line item mappings
- Sales channel to shop slug mapping
- Supports German (de-DE) and English (en-GB) localization

#### **Services Definition** - [`src/Resources/config/services.xml`](src/Resources/config/services.xml)

- Dependency injection configuration
- Registers `OrderSubscriber` with required services

#### **Logging Config** - [`src/Resources/config/packages/monolog.yaml`](src/Resources/config/packages/monolog.yaml)

- Dedicated log channel: `karla_delivery`
- Rotating file handler (30 days retention)
- Debug level logging

## Key Features

### 1. Order Synchronization

- Places orders (without tracking codes) to GoKarla
- Updates shipment addresses
- Fulfills orders with tracking codes
- Sends product details, pricing, and customer information

### 2. Configurable Event Triggers

**Order Statuses:**

- Open (default: disabled)
- In Progress (default: enabled)
- Completed (default: enabled)
- Cancelled (default: disabled)

**Delivery Statuses:**

- Open (default: disabled)
- Shipped (default: enabled)
- Shipped Partially (default: enabled)
- Returned (default: disabled)
- Returned Partially (default: disabled)
- Cancelled (default: disabled)

### 3. Customer Segmentation

Extracts and sends customer segments from:

- Order tags → formatted as `Shopware.tag.{tagName}`
- Customer tags → formatted as `Shopware.customer.tag.{tagName}`

Implementation: [`OrderSubscriber::extractOrderTagsAsSegments()`](src/Subscriber/OrderSubscriber.php:539)

### 4. Multi-Channel Support

Maps different Shopware sales channels to specific GoKarla shop slugs:

- Format: `salesChannelId1:shopSlug1,salesChannelId2:shopSlug2`
- Falls back to default shop slug if no mapping found
- Implementation: [`OrderSubscriber::parseSalesChannelMapping()`](src/Subscriber/OrderSubscriber.php:579) and [`OrderSubscriber::getShopSlugForSalesChannel()`](src/Subscriber/OrderSubscriber.php:614)

### 5. Line Item Type Mapping

- Supports product line items
- Supports promotions/discounts
- Configurable deposit line item type (e.g., bottle deposits)
- Sends product images, SKU, quantity, pricing

## API Integration

### Authentication

- **Method:** HTTP Basic Authentication
- **Credentials:** Base64 encoded `{apiUsername}:{apiKey}`
- **Header:** `Authorization: Basic {encodedCredentials}`

### Endpoint

```text
PUT {apiUrl}/v1/shops/{shopSlug}/orders
```

Default API URL: `https://api.gokarla.io`

### Request Timeout

- Default: 10.0 seconds
- Configurable range: 1.0 - 30.0 seconds

### Payload Structure

```json
{
  "id": "orderNumber",
  "id_type": "order_number",
  "order": {
    "order_number": "string",
    "order_placed_at": "ISO8601 datetime",
    "products": [...],
    "total_order_price": 0.0,
    "shipping_price": 0.0,
    "sub_total_price": 0.0,
    "discount_price": 0.0,
    "discounts": [...],
    "email_id": "string",
    "address": {...},
    "currency": "string",
    "external_id": "uuid",
    "segments": ["Shopware.tag.VIP", ...]
  },
  "trackings": [
    {
      "tracking_number": "string",
      "tracking_placed_at": "ISO8601 datetime",
      "products": [...]
    }
  ]
}
```

## Development Workflow

### Local Setup

```bash
# Install dependencies
make install

# Start Dockware container (Shopware development environment)
make dockware-start

# Attach to container
make dockware-attach
```

### Code Quality

**Linting:**

```bash
make lint  # Check PSR-12 compliance
```

**Auto-formatting:**

```bash
make format  # Fix PSR-12 violations
```

**Testing:**

```bash
make test  # Run PHPUnit tests
```

**Code Standards:**

- PSR-12 coding standard (defined in [`phpcs.xml`](phpcs.xml))
- PHP 8.3.2+
- Strict type declarations (`declare(strict_types=1);`)

### VSCode Setup

The project includes pre-configured VSCode settings:

- Auto-format on save
- PSR-12 line limit ruler at 120 characters
- Recommended extensions: phpcbf, phpcs, PHP Intellisense/Intelephense

### Testing

- Framework: PHPUnit 11.0+
- Location: [`tests/Subscriber/OrderSubscriberTest.php`](tests/Subscriber/OrderSubscriberTest.php)
- Coverage includes:
  - Order placement
  - Partial orders
  - Order fulfillment
  - Segmentation (tags)
  - Sales channel mapping

### Building for Distribution

```bash
make build  # Creates dist/KarlaDelivery.zip
```

Build output includes:

- `src/` directory
- `composer.json`
- Packaged as ZIP for Shopware admin upload

## CI/CD Pipeline

**GitHub Actions:** [`.github/workflows/ci.yaml`](.github/workflows/ci.yaml)

Workflow on push/PR:

1. Set up PHP 8.3.2
2. Install dependencies
3. Lint code (PSR-12)
4. Run tests
5. Build distributable ZIP (main branch only)
6. Create GitHub release with `release-wizard` action
7. Upload `KarlaDelivery.zip` as release asset

## Project Structure

```text
.
├── src/
│   ├── KarlaDelivery.php              # Main plugin class
│   ├── Subscriber/
│   │   └── OrderSubscriber.php        # Event subscriber (core logic)
│   └── Resources/
│       └── config/
│           ├── config.xml             # Admin panel config UI
│           ├── services.xml           # DI container config
│           └── packages/
│               └── monolog.yaml       # Logging config
├── tests/
│   └── Subscriber/
│       └── OrderSubscriberTest.php    # Unit tests
├── dist/                              # Build output (gitignored)
├── composer.json                      # Package definition
├── phpcs.xml                          # Code style rules
├── Makefile                           # Development commands
├── docker-compose.yml                 # Dockware setup
└── README.md                          # User-facing documentation
```

## Dependencies

### Required (Runtime)

- `shopware/core`
- `shopware/administration`
- `shopware/storefront`

### Dev Dependencies

- `squizlabs/php_codesniffer` (^3.9)
- `phpunit/phpunit` (^11.0)

## Installation Methods

### 1. Composer

```bash
composer require gokarla/shopware
```

### 2. ZIP via Console

```bash
# Extract to custom/plugins/
bin/console plugin:install --activate KarlaDelivery
bin/console cache:clear
```

### 3. ZIP via Admin UI

Upload through Shopware admin panel → Extensions

## Configuration Best Practices

### **API Credentials**

- Shop slug: Unique identifier for your GoKarla shop
- API username: Organization username
- API key: Secret key (stored securely as password type)
- API URL: Default production or custom endpoint

### **Event Triggers**

- Enable order statuses that indicate readiness to ship
- Enable delivery statuses when tracking is available
- Typically: "In Progress" + "Completed" orders + "Shipped" deliveries

### **Line Item Mappings**

- Configure deposit line item type if selling beverages with deposits
- Only product and deposit types are sent to GoKarla

### **Sales Channel Mapping**

- Map each sales channel to appropriate GoKarla shop
- Format: comma-separated `channelId:shopSlug` pairs
- Leave empty to use default shop slug for all channels

## Logging

**Log Location:** `{kernel.logs_dir}/karla_delivery.log`

**Log Levels Used:**

- `debug`: API requests/responses, configuration parsing, segment extraction
- `info`: Successful operations, skipped orders (status filtering)
- `warning`: Missing configuration, operational issues
- `error`: Exceptions, API failures

**Typical Dockware Location:** `http://localhost/logs`

## Common Development Tasks

### Adding a New Configuration Field

1. Add field to [`src/Resources/config/config.xml`](src/Resources/config/config.xml)
2. Read value in [`OrderSubscriber::__construct()`](src/Subscriber/OrderSubscriber.php:100)
3. Store in class property
4. Use in business logic

### Modifying API Payload

#### Update relevant method in `OrderSubscriber`

- `sendKarlaOrder()` - Main payload assembly
- `readLineItems()` - Product/discount parsing
- `readDeliveryPositions()` - Delivery product parsing
- `readAddress()` - Address formatting
- `extractOrderTagsAsSegments()` - Segmentation logic

#### Update corresponding test in [`OrderSubscriberTest.php`](tests/Subscriber/OrderSubscriberTest.php)

#### Run `make test` to verify

### Adding New Event Subscriptions

1. Add event constant to `getSubscribedEvents()` array
2. Implement handler method (follow `onOrderWritten` pattern)
3. Add test coverage

## Debugging Tips

1. **Enable Debug Logging:** Already enabled by default in `monolog.yaml`
2. **Check API Requests:** Review debug logs for full request/response details
3. **Verify Configuration:** Check logs for "Missing critical configuration" warnings
4. **Test with Dockware:**

- Admin: [http://localhost/admin] (admin/shopware)
- Storefront: [http://localhost]
- Logs: [http://localhost/logs]

## Versioning

- Follows [Semantic Versioning](https://semver.org/)
- Current version in [`composer.json`](composer.json): `0.5.0`
- Automated releases via GitHub Actions on main branch

## Important Notes for AI Assistants

### **Shopware Context:** This is a Shopware 6 plugin, not standalone PHP code

- Uses Shopware's event system
- Relies on Shopware entities (OrderEntity, ProductEntity, etc.)
- Follows Shopware plugin architecture patterns

### **Type Safety:** Always maintain strict type declarations

- All files start with `declare(strict_types=1);`
- Type hint all parameters and return values
- Handle nullable types explicitly

### **Error Handling:** Broad try-catch in event handlers to prevent breaking shop operations

- Log errors comprehensively (message, file, line)
- Never throw uncaught exceptions in subscribers

### **Testing:** Mock all Shopware dependencies in tests

- Use PHPUnit mocks for entities and services
- Test different scenarios (partial orders, tags, mappings)
- Verify API calls with argument matchers

### **Security Considerations:**

- API credentials stored in Shopware's encrypted config
- Use password field type for sensitive data
- Log payloads at debug level only
- Basic Auth over HTTPS only

### **Performance:**

- Event subscribers execute synchronously during order processing
- Keep API timeout reasonable (default 10s)
- Use rotating logs to prevent disk space issues
- Only fetch required entity associations

### **Multi-tenancy:** Support multiple shops via sales channel mapping

- Each sales channel can route to different GoKarla shop
- Fallback to default shop slug if unmapped

### **Extensibility Points:**

- Configuration is read from SystemConfigService (can be scoped per sales channel)
- Event-driven architecture allows parallel event listeners
- Line item type filtering is configurable

## Quick Reference: Key Classes & Methods

| Class/Method                   | Purpose                         | Location                                                                 |
| ------------------------------ | ------------------------------- | ------------------------------------------------------------------------ |
| `KarlaDelivery`                | Main plugin class               | [src/KarlaDelivery.php](src/KarlaDelivery.php)                           |
| `OrderSubscriber`              | Event handler                   | [src/Subscriber/OrderSubscriber.php](src/Subscriber/OrderSubscriber.php) |
| `onOrderWritten()`             | Main event handler entry point  | [OrderSubscriber.php:215](src/Subscriber/OrderSubscriber.php:215)        |
| `sendKarlaOrder()`             | Assembles & sends API request   | [OrderSubscriber.php:269](src/Subscriber/OrderSubscriber.php:269)        |
| `sendRequestToKarlaApi()`      | HTTP client wrapper             | [OrderSubscriber.php:391](src/Subscriber/OrderSubscriber.php:391)        |
| `readLineItems()`              | Parses order products/discounts | [OrderSubscriber.php:431](src/Subscriber/OrderSubscriber.php:431)        |
| `readDeliveryPositions()`      | Parses delivery products        | [OrderSubscriber.php:477](src/Subscriber/OrderSubscriber.php:477)        |
| `readAddress()`                | Formats address data            | [OrderSubscriber.php:509](src/Subscriber/OrderSubscriber.php:509)        |
| `extractOrderTagsAsSegments()` | Extracts customer segments      | [OrderSubscriber.php:539](src/Subscriber/OrderSubscriber.php:539)        |
| `parseSalesChannelMapping()`   | Parses channel→shop config      | [OrderSubscriber.php:579](src/Subscriber/OrderSubscriber.php:579)        |
| `getShopSlugForSalesChannel()` | Resolves shop slug              | [OrderSubscriber.php:614](src/Subscriber/OrderSubscriber.php:614)        |

## IDE Configuration

### Fixing Intelephense Type Warnings

You may see IDE warnings like:

```text
Expected type 'object'. Found 'Shopware\Core\Framework\DataAbstractionLayer\Search\TElement'
```

**This is normal and expected.** Shopware uses generic types that PHP/Intelephense can't fully resolve. The code works correctly at runtime.

**Solutions implemented:**

1. **PHPDoc hints** - Added `@var` annotations for type clarity
2. **Intelephense config** - Disabled overly strict type checking for Shopware framework
3. **PHPStorm meta** - Added `.phpstorm.meta.php` for better IDE support

**VSCode settings** (already configured in `.vscode/settings.json`):

```json
{
  "intelephense.diagnostics.undefinedMethods": false,
  "intelephense.diagnostics.undefinedTypes": false
}
```

These warnings don't affect:

- ✅ Runtime execution
- ✅ PHPStan analysis (properly configured)
- ✅ Tests
- ✅ Production code

## API & Framework References

### GoKarla API

- **OpenAPI Specification:** <https://api.gokarla.io/public/openapi.json>
- **Documentation:** <https://docs.gokarla.io/docs/shop-integrations/shopware>
- **Authentication:** <https://docs.gokarla.io/docs/api/authentication>

**Key Endpoint:** `PUT /v1/shops/{shopSlug}/orders` - Order upsert with tracking

### Shopware Framework

- **Plugin Development:** <https://developer.shopware.com/docs/guides/plugins/overview>
- **Data Handling (DAL):** <https://developer.shopware.com/docs/guides/plugins/plugins/framework/data-handling/>
- **Event System:** <https://developer.shopware.com/docs/guides/plugins/plugins/plugin-fundamentals/listening-to-events>
- **Order Management:** <https://developer.shopware.com/docs/guides/plugins/plugins/checkout/order/>

**Important Shopware Concepts:**

- `OrderLineItem::getId()` returns the line item instance UUID (use as `external_product_id`)
- `OrderLineItem::getReferencedId()` returns the product/variant UUID (use as `sku`)
- Use WebFetch on these docs when you need detailed entity/method information

### Coding Standards

- **PSR-12:** <https://www.php-fig.org/psr/psr-12/>

---

**Last Updated:** 2025-10-01
**Plugin Version:** 0.5.0
**Maintained By:** GoKarla GmbH
