<?php

declare(strict_types=1);

namespace Ecotone\SymfonyBundle\Messenger;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class SymfonyMessengerMessageChannelBuilder implements MessageChannelBuilder
{
    private const TRANSPORT_SERVICE_PREFIX = 'messenger.transport.';

    private array $headerMapper = [];

    private $acknowledgeMode = SymfonyAcknowledgementCallback::AUTO_ACK;

    private function __construct(private string $transportName)
    {
        $this->withHeaderMapping('*');
    }

    public static function create(string $transportName): self
    {
        return new self($transportName);
    }

    public function getMessageChannelName(): string
    {
        return $this->transportName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    /**
     * @param string $headerMapper
     * @return static
     */
    public function withHeaderMapping(string $headerMapper): self
    {
        $this->headerMapper = explode(',', $headerMapper);

        return $this;
    }

    public function build(ReferenceSearchService $referenceSearchService): MessageChannel
    {
        /** @var TransportInterface $transport */
        $transport = $referenceSearchService->get($this->getTransportServiceName());
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        return new SymfonyMessengerMessageChannel(
            $transport,
            DefaultHeaderMapper::createWith($this->headerMapper, $this->headerMapper, $conversionService),
            $this->acknowledgeMode
        );
    }

    public function getRequiredReferenceNames(): array
    {
        return [
            $this->getTransportServiceName(),
        ];
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }

    private function getTransportServiceName(): string
    {
        return self::TRANSPORT_SERVICE_PREFIX . $this->transportName;
    }
}
