---
type: llm
---
PASS if the ticket's prior registration is seeded with `withEventsFor('<id>', Ticket::class, [new TicketWasRegistered(...)])` (or several such calls), and the assertions compare `popRecordedEvents()` / `popRecordedEventsOfType()` with the expected `TicketWasClosed` event and with an empty result for the second close. FAIL if history is built by constructing the aggregate or calling its apply methods, or the events are asserted through reflection or an event-store query instead of the recorded events.
