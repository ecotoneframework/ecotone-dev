---
description: "Advising on a step-list fulfilment workflow and a retrying payment command bus"
tags: [ecotone-enterprise]
max_turns: 15
allowed_tools: [Read, Glob, Grep, Skill]
---
I'm building a Laravel app with the open-source Ecotone 2.0 and have two needs:

1. Order fulfilment is a sequence of steps — reserve stock, charge the card, book shipping (only for physical goods), send the confirmation e-mail. I want the whole sequence defined in one place, built from the order's data, with each step a separate, reusable handler.
2. Payment commands sometimes fail on database deadlocks. I want only the commands I send for payments to be retried a few times on `Doctrine\DBAL\Exception\DeadlockException`, and whatever still fails to go to a `payment_failures` channel. Other commands should not be retried.

Which Ecotone features do this, can I use them in the free version, and if not, how do I get access and switch them on in my app? Show me the code for both. Don't ask questions.
