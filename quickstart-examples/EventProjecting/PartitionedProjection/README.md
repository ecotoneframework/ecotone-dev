# Partitioned Projection - Enterprise Feature

> **⚠️ ENTERPRISE LICENSE REQUIRED**
> This example demonstrates **Partitioned Projections**, an Ecotone Enterprise feature.
> To run this example, you need an Enterprise License Key.
>
> - **For evaluation**: Contact support@simplycodedsoftware.com for a trial key
> - **For production**: Visit https://ecotone.tech

This example demonstrates **Ecotone's Enterprise feature for scalable projections** based on partitioned event streams.

## What is a Partitioned Projection?

A partitioned projection splits the event stream into multiple partitions (typically by aggregate ID) to enable **parallel processing** of events. This allows multiple consumers to process different partitions concurrently, dramatically improving throughput for high-volume event streams.

### Key Benefits

1. **Horizontal Scalability**: Run multiple consumers in parallel, each processing different wallet partitions
2. **High Throughput**: Process thousands of events per second by distributing load across partitions
3. **Fault Isolation**: Issues in one partition don't affect others
4. **Efficient Resource Usage**: Better CPU and memory utilization across multiple cores/servers

## Example: Wallet Balance Projection

This example shows a **Wallet** event-sourced aggregate that handles debit and credit operations, with a **WalletBalanceProjection** that maintains current balances and statistics.

### Architecture

```
┌─────────────────┐
│  Wallet         │  Event Sourced Aggregate
│  Aggregate      │  - CreateWallet
│                 │  - DebitWallet
│                 │  - CreditWallet
└────────┬────────┘
         │ emits events
         ▼
┌─────────────────┐
│  Event Store    │  Stores all wallet events
│  (DBAL)         │  - WalletWasCreated
│                 │  - WalletWasDebited
│                 │  - WalletWasCredited
└────────┬────────┘
         │ streams events
         ▼
┌─────────────────────────────────────────┐
│  Partitioned Projection                 │
│  (partitionHeaderName: 'aggregate.id')  │
│                                         │
│  Partition 1    Partition 2    ...     │
│  (Wallet A)     (Wallet B)             │
│     ↓               ↓                   │
│  Consumer 1     Consumer 2     ...     │
└────────┬────────────┬───────────────────┘
         │            │
         ▼            ▼
┌─────────────────────────────────────────┐
│  Read Model: wallet_balances            │
│  - wallet_id (PK)                       │
│  - current_balance                      │
│  - total_debits                         │
│  - total_credits                        │
│  - transaction_count                    │
└─────────────────────────────────────────┘
```

### How Partitioning Works

The projection is configured with `partitionHeaderName: 'aggregate.id'`:

```php
#[Projection('wallet_balance', partitionHeaderName: 'aggregate.id')]
#[FromStream('wallet_stream', Wallet::class)]
#[Asynchronous('async_projection')]
class WalletBalanceProjection { ... }
```

This means:
- Each wallet ID becomes a separate partition
- Events for Wallet A go to Partition A
- Events for Wallet B go to Partition B
- Multiple consumers can process different partitions in parallel

### Async Processing with DBAL

The projection uses `DbalBackedMessageChannelBuilder` for reliable async processing:

```php
#[ServiceContext]
public function asyncProjectionChannel(): DbalBackedMessageChannelBuilder
{
    return DbalBackedMessageChannelBuilder::create('async_projection');
}
```

This provides:
- **Outbox Pattern**: Transactional event publishing
- **Guaranteed Delivery**: Events are persisted before processing
- **Retry Mechanism**: Failed events can be retried
- **Dead Letter Queue**: Failed events are stored for investigation

## Running the Example

### Prerequisites

- PHP 8.1+
- Composer
- **PostgreSQL or MySQL** (required - SQLite is not supported by the Prooph event store)
- **Ecotone Enterprise License** (required for partitioned projections)

> **Note**: This example requires PostgreSQL or MySQL. The Prooph event store used for event sourcing does not support SQLite.

### Installation

```bash
cd quickstart-examples/EventProjecting/PartitionedProjection
composer install
```

### Setup License Key

Set your Enterprise License Key as an environment variable:

```bash
export ECOTONE_LICENSE_KEY="your-license-key-here"
```

> **Note**: If you don't have a license key yet, contact support@simplycodedsoftware.com for a trial key.

### Run the Example

```bash
php run_example.php
```

### Expected Output

```
=== Partitioned Projection Example ===

0. Cleaning up (deleting existing projection)...
   ✓ Projection deleted (clean state)

1. Initializing projection...
   ✓ Projection initialized (table created)

2. Creating wallets...
   ✓ Created wallet 1: <uuid> with balance 1000.00
   ✓ Created wallet 2: <uuid> with balance 500.00

3. Performing transactions...
   ✓ Debited 100.00 from wallet 1
   ✓ Credited 50.00 to wallet 1
   ✓ Debited 200.00 from wallet 2

4. Running async projection consumer...
   ✓ Projection updated

5. Querying wallet balances...
   ✓ Wallet 1 balance: 950.00 (expected: 950.00)
   ✓ Wallet 2 balance: 300.00 (expected: 300.00)

6. Querying wallet statistics...
   ✓ Wallet 1 stats: {"balance":950,"total_debits":100,"total_credits":50,"transaction_count":2}
   ✓ Wallet 2 stats: {"balance":300,"total_debits":200,"total_credits":0,"transaction_count":1}

=== Example completed successfully! ===
```

## Production Deployment

### Running Multiple Consumers

To leverage partitioning in production, run multiple consumer processes:

```bash
# Terminal 1
vendor/bin/ecotone ecotone:run async_projection

# Terminal 2
vendor/bin/ecotone ecotone:run async_projection

# Terminal 3
vendor/bin/ecotone ecotone:run async_projection
```

Each consumer will automatically pick up different partitions, processing events in parallel.

### Projection Management Commands

```bash
# Initialize projection (create table)
vendor/bin/ecotone ecotone:projection:init wallet_balance

# Delete projection (drop table and state)
vendor/bin/ecotone ecotone:projection:delete wallet_balance

# Run projection consumer
vendor/bin/ecotone ecotone:run async_projection
```

## Learn More

- [Ecotone Documentation](https://docs.ecotone.tech)
- [Event Sourcing Guide](https://docs.ecotone.tech/modelling/event-sourcing)
- [Projections Guide](https://docs.ecotone.tech/modelling/event-sourcing/setting-up-projections)
- [Enterprise Features](https://docs.ecotone.tech/enterprise)

## License

This example requires an **Ecotone Enterprise License** for the partitioned projection feature.

