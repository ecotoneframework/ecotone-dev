---
type: llm
---
PASS if every `:placeholder` in each SQL string matches the name of a method parameter (e.g. `:orderId` with `$orderId`) or a `#[DbalParameter]` name, and no SQL is assembled by concatenating or interpolating method arguments. FAIL if a placeholder has no matching parameter (e.g. `:id` with `$orderId`) or values are interpolated into the SQL.
