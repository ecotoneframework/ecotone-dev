# Hook into Tenant Switch

Example of reacting to a tenant being activated and deactivated, with `#[OnTenantActivation]` and
`#[OnTenantDeactivation]`, while commands are routed to each tenant's database by the `tenant` header.

## How to run
Follow instruction from the [root of the repository](https://github.com/ecotoneframework/quickstart-examples).

## Enterprise licence

Multi-tenancy is an Ecotone Enterprise feature, so this example needs a licence key before it starts.
Set it in the `ECOTONE_LICENSE_KEY` environment variable:

```bash
ECOTONE_LICENSE_KEY=<your key> php run_example.php
```

Start a trial at https://ecotone.tech/pricing to get a key. Without one, Ecotone refuses to start and its message
says where the key goes.
