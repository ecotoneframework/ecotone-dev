---
type: llm
---
PASS if the `EscalateTicket` handler on `Ticket` returns an array of events (an escalation event, or an empty array when already escalated) without assigning properties itself, and the escalated flag is set in an `#[Apply]` method for the escalation event; the existing `applyRegistered` keeps assigning `ticketId`. FAIL if the command handler writes `$this->escalated` (or other state) directly, if it returns `void`, or if an `#[Apply]` method has side effects.
