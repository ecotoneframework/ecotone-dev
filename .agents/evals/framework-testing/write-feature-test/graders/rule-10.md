---
type: llm
---
PASS if the test boots with EcotoneLite::bootstrapFlowTesting(), drives the application through public buses/gateways, and contains no SQL against Ecotone tables, no reflection, and no direct use of internal src/ classes (such as the outbound adapter). FAIL if it instantiates or calls the adapter or another src/ class, queries ecotone tables, or uses reflection.
