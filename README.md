# Karla Shopware Extension

Simplify your purchase experience in [Shopware](https://www.shopware.com/en/) with Karla

## Features

- Place orders (without a tracking code).
- Update shipment address from an order.
- Fulfill orders (add a delivery to an order with a tracking code).

## Requirements

- Shopware 6
- A Karla REST API Key. See [Authentication](https://docs.gokarla.io/docs/api/authentication).

To see the list of prerequisites to run this project locally:

```sh
make init
```

## Installation

### Composer

Via composer

```bash
composer require gokarla/shopware
```

### Zip file via console

Extract the .zip file to `<installation-root>/custom/plugins`.

Run the following commands in your `<installation-root>`

```sh
bin/console plugin:install --activate KarlaDelivery
bin/console cache:clear
```

### Zip file via UI dashboard

Download a .zip release and upload it in through your admin panel.
See [Shopware Integration](https://docs.gokarla.io/docs/shop-integrations/shopware) for more information.

## Development

First install all the dependencies

```sh
composer dump-autoload
composer install
```

Now you will have all the required PHP files for development

### Code Quality & Formatting

This project uses PHP CodeSniffer with PSR-12 standards for code linting and formatting.

#### Linting

Check code style issues:

```sh
make lint
```

#### Auto-formatting

Automatically fix code style issues:

```sh
make format
```

#### VSCode Setup

If you're using VSCode, the project includes configuration for automatic formatting:

1. Install the recommended extensions (VSCode will prompt you, or install manually):

   - **phpcbf**: PHP Code Beautifier and Fixer
   - **phpcs**: PHP CodeSniffer
   - **PHP Intellisense** or **Intelephense**: PHP language support

2. The workspace is configured to:
   - Format PHP files on save
   - Show a ruler at 120 characters (PSR-12 line limit)
   - Automatically fix linting issues when possible
   - Trim trailing whitespace and ensure final newlines

#### Testing

Run tests:

```sh
make test
```

### Dockware

Run the docker container

```sh
make dockware-start
```

Attach yourself to it

```sh
make dockware-attach
```

### Access shop

- Navigate to <http://localhost/admin> and type user `admin` and password `shopware`
  to access the admin panel.
  - Go to `Extensions`, `My extensions` and make sure that the `Karla Delivery` extension is active.
  - Go to the extension settings and provide a shop slug and api key.
- Navigate to <http://localhost> to see the shop and create test orders
- Navigate to <http://localhost/logs> to browse the logs

### Manual Testing

#### Create an order

Go to the shop at <http://localhost> and order something. This should trigger the webhook
and the backend should receive its payload in the shopware hook endpoint.

#### Ship an order

Go to the admin panel at <http://localhost/admin>, select an order, go to `Details`
and update the delivery status to `Shipped`. This will trigger a webhook as defined
in the Shopware app manifest and the backend should receive its payload in the shopware hook endpoint.

## Versioning

We use [SemVer](http://semver.org/) for versioning. For the versions available, see the tags on this repository.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
