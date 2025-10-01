# Karla Shopware Extension

[![CI](https://github.com/gokarla/shopware/workflows/ci/badge.svg)](https://github.com/gokarla/shopware/actions)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.3-blue)](https://www.php.net/)
[![Shopware](https://img.shields.io/badge/shopware-6.x-blue)](https://www.shopware.com/)

Enhance your post-purchase customer experience in [Shopware](https://www.shopware.com/en/) with [GoKarla](https://gokarla.io) - automated order tracking and delivery updates for your customers.

## Overview

The Karla Delivery extension automatically synchronizes your Shopware orders and shipments with GoKarla, enabling you to provide your customers with precise, timely delivery updates and improve satisfaction after purchase.

## Features

### Core Capabilities

- **Automatic Order Synchronization** - Orders are automatically sent to GoKarla when placed or updated
- **Shipment Tracking Integration** - Delivery tracking codes are synchronized in real-time
- **Customer Segmentation** - Leverage order and customer tags for targeted communication
- **Multi-Channel Support** - Map different sales channels to specific GoKarla shops

### Flexible Configuration

- **Configurable Event Triggers** - Choose which order and delivery statuses trigger synchronization
- **Line Item Type Mapping** - Support for products, promotions, and custom line item types (e.g., deposits)
- **Custom API Endpoint** - Use production or custom GoKarla API endpoints
- **Detailed Logging** - Built-in logging for troubleshooting and monitoring

## Requirements

- **Shopware 6** - Compatible with Shopware 6.x
- **GoKarla Account** - Sign up at [portal.gokarla.io](https://portal.gokarla.io)
- **API Credentials** - Shop slug and API key from your GoKarla account. See [Authentication](https://docs.gokarla.io/docs/api/authentication).

## Installation

### Option 1: Via Shopware Admin Panel (Recommended)

1. Download the latest `KarlaDelivery.zip` from [GitHub Releases](https://github.com/gokarla/shopware/releases)
2. In Shopware admin, navigate to **Extensions** → **My Extensions**
3. Click **Upload extension** and select the downloaded ZIP file
4. Click **Install** and then **Activate**
5. Configure your API credentials in the extension settings

For detailed instructions, see [Shopware Integration Documentation](https://docs.gokarla.io/docs/shop-integrations/shopware).

### Option 2: Via Composer

```bash
composer require gokarla/shopware
bin/console plugin:refresh
bin/console plugin:install --activate KarlaDelivery
bin/console cache:clear
```

### Option 3: Manual Installation via Console

1. Download and extract `KarlaDelivery.zip` to `<shopware-root>/custom/plugins/`
2. Run the following commands:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate KarlaDelivery
bin/console cache:clear
```

## Configuration

After installation, configure the extension:

1. Go to **Extensions** → **My Extensions** → **Karla Delivery** → **Configure**
2. Enter your **Shop Slug** and **API Key** from your GoKarla account
3. Configure which order and delivery statuses should trigger synchronization
4. (Optional) Set up sales channel mapping for multi-shop setups
5. Save the configuration

**Recommended Settings:**

- Order Statuses: Enable "In Progress" and "Completed"
- Delivery Statuses: Enable "Shipped" and "Shipped Partially"

## Support

- **Documentation**: [docs.gokarla.io](https://docs.gokarla.io/docs/shop-integrations/shopware)
- **Issues**: [GitHub Issues](https://github.com/gokarla/shopware/issues)
- **Contact**: For additional support, contact your GoKarla account manager

## Contributing

We welcome contributions! This is an open-source project under the Apache 2.0 License.

**Quality Standards:**

- All code must pass `make check-all` (linting, static analysis, and tests)
- Follow PSR-12 coding standards
- Add tests for new features
- Update documentation as needed

### Development Setup

Install dependencies:

```bash
composer install
```

### Code Quality

This project follows PSR-12 coding standards and uses modern PHP tooling for quality assurance.

**Available Commands:**

```bash
# Check code style with PHP-CS-Fixer
make lint

# Auto-fix code style issues
make format

# Run static analysis with PHPStan
make analyse

# Run tests with PHPUnit
make test

# Generate code coverage report
make coverage

# Run all quality checks
make check-all
```

**Tooling:**

- **PHP-CS-Fixer** - Fast, modern code formatter
- **PHPStan** - Static analysis to catch bugs before runtime
- **PHPUnit** - Comprehensive test suite
- **EditorConfig** - Consistent formatting across editors

### Code Coverage

To generate code coverage reports locally, you need Xdebug installed:

**macOS (Homebrew):**

```bash
# Install with sudo (Homebrew Cellar requires elevated permissions)
sudo pecl install xdebug
```

Verify installation:

```bash
php -m | grep xdebug
```

Alternative if PECL fails - compile from source:

```bash
# Download and compile xdebug
git clone https://github.com/xdebug/xdebug.git
cd xdebug
phpize
./configure
make
sudo make install

# Enable in php.ini
echo "zend_extension=xdebug.so" | sudo tee -a $(php --ini | grep "Loaded Configuration" | sed -e "s|.*:\s*||")
```

**Linux (Ubuntu/Debian):**

```bash
sudo apt install php8.3-xdebug
```

**Docker/Dockware:**

```bash
# Xdebug is pre-installed in Dockware containers
```

After installing Xdebug, run:

```bash
make coverage
# Open coverage/html/index.html in your browser
```

Coverage reports are automatically generated in CI and uploaded to [Codecov](https://codecov.io/gh/gokarla/shopware).

### Local Development with Dockware

Start a local Shopware instance:

```bash
make dockware-start
make dockware-attach
```

Access the development shop:

- **Admin Panel**: <http://localhost/admin> (credentials: `admin` / `shopware`)
- **Storefront**: <http://localhost>
- **Logs**: <http://localhost/logs>

For detailed development guidelines, see [CLAUDE.md](CLAUDE.md).

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the tags on this repository.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
