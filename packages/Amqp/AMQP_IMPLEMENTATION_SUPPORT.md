# AMQP Implementation Support

This document describes how the Ecotone AMQP package supports both AMQP extension and AMQP lib implementations.

## Overview

The Ecotone AMQP package now supports two AMQP implementations:

1. **AMQP Extension** (`enqueue/amqp-ext` + `ext-amqp`) - Default
   - Uses the native PHP AMQP extension (C-based)
   - Better performance
   - Requires the AMQP PHP extension to be installed

2. **AMQP Lib** (`enqueue/amqp-lib`) - Pure PHP implementation
   - No extension required
   - Required for **AmqpStreamChannelBuilder** and RabbitMQ Streams support
   - Easier to install in environments where extensions cannot be compiled

## Default Behavior

For **backward compatibility**, the default connection factory reference is `Enqueue\AmqpExt\AmqpConnectionFactory`.

**Exception**: `AmqpStreamChannelBuilder` uses `Enqueue\AmqpLib\AmqpConnectionFactory` by default because RabbitMQ Streams require the lib implementation.

## Installation

### Using AMQP Extension (Default, Recommended)

```bash
# Install the AMQP extension
pecl install amqp

# Install the package
composer require ecotone/amqp enqueue/amqp-ext
```

### Using AMQP Lib (Pure PHP)

```bash
# No extension needed
composer require ecotone/amqp enqueue/amqp-lib
```

### Using Both (For Streams Support)

```bash
# Install both for maximum compatibility
pecl install amqp
composer require ecotone/amqp enqueue/amqp-ext enqueue/amqp-lib
```

## Usage

### Standard AMQP Channels (Uses Default - AmqpExt)

```php
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Enqueue\AmqpExt\AmqpConnectionFactory;

// Default uses AmqpExt
$channel = AmqpBackedMessageChannelBuilder::create('orders');

// Explicit AmqpExt
$channel = AmqpBackedMessageChannelBuilder::create(
    'orders',
    AmqpConnectionFactory::class
);
```

### Stream Channels (Requires AmqpLib)

```php
use Ecotone\Amqp\AmqpStreamChannelBuilder;
use Enqueue\AmqpLib\AmqpConnectionFactory;

// Default uses AmqpLib (required for streams)
$streamChannel = AmqpStreamChannelBuilder::create('stream_orders');

// Explicit AmqpLib
$streamChannel = AmqpStreamChannelBuilder::create(
    'stream_orders',
    'first',
    AmqpConnectionFactory::class
);
```

### Using AmqpLib for Standard Channels

```php
use Ecotone\Amqp\AmqpBackedMessageChannelBuilder;
use Enqueue\AmqpLib\AmqpConnectionFactory;

// Explicitly use AmqpLib
$channel = AmqpBackedMessageChannelBuilder::create(
    'orders',
    AmqpConnectionFactory::class
);
```

## Implementation Details

### AmqpReconnectableConnectionFactory

The `AmqpReconnectableConnectionFactory` class now supports both implementations:

- Accepts `AmqpExtConnectionFactory|AmqpLibConnectionFactory` in constructor
- Automatically detects which implementation is being used
- Handles connection management differently based on implementation:
  - **AmqpExt**: Uses `getExtChannel()`, `disconnect()`, `setConfirmCallback()`
  - **AmqpLib**: Uses `getLibChannel()`, `close()`, `confirm_select()`, `wait_for_pending_acks()`

### Publisher Acknowledgments

Publisher acknowledgments work differently between implementations:

**AmqpExt**:
```php
$context->getExtChannel()->setConfirmCallback(...);
$context->getExtChannel()->waitForConfirm();
```

**AmqpLib**:
```php
$context->getLibChannel()->confirm_select();
$context->getLibChannel()->wait_for_pending_acks(5000);
```

### Connection Exceptions

Both implementations throw different exceptions:

**AmqpExt**:
- `AMQPConnectionException`
- `AMQPChannelException`

**AmqpLib**:
- `AMQPIOException`
- `AMQPChannelClosedException`
- `AMQPConnectionClosedException`

The `AmqpInboundChannelAdapter` handles all of these exception types.

## Testing

The AMQP package is configured to automatically test both implementations when running `composer tests:ci`.

### Run All Tests (Both Implementations)

```bash
cd packages/Amqp
composer tests:ci
```

This will run:
1. PHPStan static analysis
2. PHPUnit tests with AMQP Extension
3. PHPUnit tests with AMQP Lib

### Test Individual Implementations

```bash
# Test with AMQP Extension only
composer tests:phpunit:amqp-ext

# Test with AMQP Lib only
composer tests:phpunit:amqp-lib

# Or manually with environment variable
AMQP_IMPLEMENTATION=ext vendor/bin/phpunit
AMQP_IMPLEMENTATION=lib vendor/bin/phpunit
```

## Migration Guide

### From Main Branch (AmqpExt only) to Current Branch (Both)

No changes required! The default behavior remains the same (AmqpExt).

### To Use Streams

1. Install `enqueue/amqp-lib`:
   ```bash
   composer require enqueue/amqp-lib
   ```

2. Use `AmqpStreamChannelBuilder`:
   ```php
   use Ecotone\Amqp\AmqpStreamChannelBuilder;
   
   $streamChannel = AmqpStreamChannelBuilder::create('my_stream');
   ```

### To Switch from AmqpExt to AmqpLib

1. Install `enqueue/amqp-lib`:
   ```bash
   composer require enqueue/amqp-lib
   ```

2. Update connection factory references:
   ```php
   // Before
   use Enqueue\AmqpExt\AmqpConnectionFactory;
   
   // After
   use Enqueue\AmqpLib\AmqpConnectionFactory;
   ```

3. Update service registration:
   ```php
   // Before
   [AmqpConnectionFactory::class => new \Enqueue\AmqpExt\AmqpConnectionFactory([...])]
   
   // After
   [AmqpConnectionFactory::class => new \Enqueue\AmqpLib\AmqpConnectionFactory([...])]
   ```

## Troubleshooting

### "Class not found" errors

Make sure you have the appropriate package installed:
- For AmqpExt: `composer require enqueue/amqp-ext` + `pecl install amqp`
- For AmqpLib: `composer require enqueue/amqp-lib`

### Stream channels not working

Stream channels require `enqueue/amqp-lib`. Install it:
```bash
composer require enqueue/amqp-lib
```

### Performance issues

If using AmqpLib and experiencing performance issues, consider switching to AmqpExt for better performance (except for stream channels which require AmqpLib).

## Architecture Decisions

1. **Backward Compatibility**: Default to AmqpExt to maintain backward compatibility
2. **Stream Support**: AmqpStreamChannelBuilder requires AmqpLib for RabbitMQ Streams
3. **Flexibility**: Allow users to choose implementation based on their needs
4. **Testing**: Support testing with both implementations via environment variable

