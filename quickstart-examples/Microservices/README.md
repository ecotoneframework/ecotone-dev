# Microservices

## Publisher

On the publisher side all you need to do it to createPublisher configuration.  
Then you can fetch it directly from your [Dependency Container](https://github.com/ecotoneframework/quickstart-examples/blob/main/Microservices/run_example.php#L33).

## Consumer

On the consumer side you want to createConsumer configuration.  
And then you can mark your Handler as [Distributed](src/Receiver/OrderServiceReceiver.php).