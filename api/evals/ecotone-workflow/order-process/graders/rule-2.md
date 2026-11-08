---
type: llm
---
PASS if the saga's `ShipmentDelivered` handler declares `dropMessageOnNotFound: true`. FAIL if it does not, or if the answer tries to ignore unknown orders some other way (try/catch, a lookup before the call, a separate listener checking existence).
