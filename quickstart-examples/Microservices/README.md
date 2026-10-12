# Microservices

## Publisher

On the publisher side all you need to do it to createPublisher configuration.  
Then you can fetch it directly from your [Dependency Container](https://github.com/ecotoneframework/quickstart-examples/blob/main/Microservices/run_example.php#L33).

## Consumer

On the consumer side you want to createConsumer configuration.  
And then you can mark your Handler as [Distributed](src/Receiver/OrderServiceReceiver.php).
## Enterprise licence

Distributed Bus with Service Map is an Ecotone Enterprise feature, so this example needs a licence key before it starts.
Set it in the `ECOTONE_LICENSE_KEY` environment variable:

```bash
ECOTONE_LICENSE_KEY=<your key> php run_example.php
```

Start a trial at https://ecotone.tech/pricing to get a key. Without one, Ecotone refuses to start and its message
says where the key goes.
