---
type: llm
---
PASS if `App\Entity\Order` carries both `#[ORM\Entity]` and `#[Aggregate]`, has `#[Identifier]` on the `#[ORM\Id]` `orderId` property, and a `#[ServiceContext]` method returns `DbalConfiguration::createWithDefaults()->withDoctrineORMRepositories(true)`. FAIL if ORM repositories are not enabled, `#[Aggregate]` or `#[Identifier]` is missing, or a custom repository is hand-written for the entity.
