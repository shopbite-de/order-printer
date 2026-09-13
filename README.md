# Order Printer for Shopware 6

A Symfony-based microservice that automatically fetches open orders from Shopware 6 and prints them on ESC/POS compatible thermal printers, either attached locally (USB/serial) or reachable over the network (raw ESC/POS on TCP port 9100).

## Features

- **Automated Polling**: Uses Symfony Scheduler to check for new orders every 10 seconds.
- **Shopware Integration**: Uses the Shopware SDK to fetch order details.
- **ESC/POS Support**: Generates formatted receipts for thermal printers.
- **Asynchronous Processing**: Uses Symfony Messenger for reliable print job handling.

## Requirements

- PHP 8.5 or higher
- SQLite extension (for queue and local storage)
- An ESC/POS printer: a local device file (e.g. `/dev/usb/lp0`) or a network printer / Raspberry Pi gateway listening on TCP port 9100. No CUPS required.

## Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/your-username/order-printer.git
   cd order-printer
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Configure environment**:
   Copy the example environment file and fill in your credentials:
   ```bash
   cp .env .env.local
   ```
   Edit `.env.local` and provide your Shopware API credentials and printer name.

4. **Initialize database**:
   ```bash
   bin/console doctrine:database:create
   ```

## Configuration

The following environment variables are required in your `.env.local`:

- `SHOPWARE_HOST`: Your Shopware 6 store URL.
- `SHOPWARE_CLIENT_ID`: Integration Client ID.
- `SHOPWARE_CLIENT_SECRET`: Integration Client Secret.
- `PRINTER_DSN`: How to reach the printer:
  - `file:///dev/usb/lp0` — local USB/serial device (any writable file path works, e.g. `file:///dev/null` in development)
  - `tcp://192.168.1.50:9100` — network printer or Pi gateway speaking raw ESC/POS; the port defaults to 9100 and connecting times out after 5 seconds
  - `dummy://` — no printer; receipts are only archived in `DATA_DIR`
- `DATA_DIR`: Directory (relative to the project root) where a copy of every printed receipt is stored.

## Usage

Start the messenger worker and the scheduler:

```bash
# Run the scheduler to poll for orders
bin/console messenger:consume scheduler_default

# Run the worker to process print jobs
bin/console messenger:consume async
```
