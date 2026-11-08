---
type: llm
---
PASS if the tests boot the application with `EcotoneLite::bootstrapFlowTesting()`, listing `Ticket` and/or `SatisfactionSurvey` in `classesToResolve` and passing a `SatisfactionSurvey` instance built with a test Mailer double in `containerOrAvailableServices`, and drive behaviour by sending commands/events through the returned object. FAIL if the tests call `Ticket::close()` or `SatisfactionSurvey::whenClosed()` directly, construct the aggregate by hand, or use a bootstrap other than `bootstrapFlowTesting()`.
