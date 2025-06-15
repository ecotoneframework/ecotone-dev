# FetchAggregate Attribute

This directory contains test fixtures demonstrating the usage of the `#[FetchAggregate]` attribute.

## Usage

The `#[FetchAggregate]` attribute allows you to inject aggregate instances into command handlers by evaluating an expression to get the aggregate identifier.

### Basic Usage

```php
#[CommandHandler]
public function placeOrder(
    PlaceOrder $command,
    #[FetchAggregate("payload.getUserId()")] User $user
): void {
    // $user will be fetched using the userId from the command payload
}
```

### Complex Expression Usage

```php
#[CommandHandler]
public function handleComplexCommand(
    ComplexCommand $command,
    #[FetchAggregate("payload.getMetadata()['userId']")] ?User $user
): void {
    // $user will be fetched using a complex expression
}
```

## Features

- **Expression Evaluation**: Uses Symfony Expression Language for flexible identifier extraction
- **Type Safety**: Aggregate class is determined from parameter type hint
- **Null Handling**: Returns null if identifier is null or aggregate not found
- **Repository Integration**: Uses AllAggregateRepository for consistent aggregate fetching

## Test Cases

- Basic aggregate fetching with simple expression
- Null identifier handling
- Non-existent aggregate handling
- Complex expression evaluation
- Multiple users and different scenarios
