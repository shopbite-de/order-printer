<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Infra\Shopware\Api;

use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Veliu\OrderPrinter\Domain\Api\ApiResponseInterface;
use Veliu\OrderPrinter\Domain\Api\ClientInterface;
use Veliu\OrderPrinter\Domain\Api\CustomEncoderInterface;
use Veliu\OrderPrinter\Domain\Api\RequestInterface;
use Veliu\OrderPrinter\Domain\Api\ResponseHandlerInterface;

use function Psl\Json\decode;
use function Psl\Json\encode;
use function Psl\Type\non_empty_string;

/**
 * @psalm-api
 */
final readonly class Client implements ClientInterface
{
    public function __construct(
        #[Autowire(env: 'SHOPWARE_HOST')]
        private string $apiHost,
        private PsrClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private ResponseHandlerInterface $responseHandler,
        private ?AccessTokenProviderInterface $accessTokenProvider = null,
    ) {
    }

    #[\Override]
    public function request(RequestInterface $request, ?CustomEncoderInterface $customEncoder = null): ApiResponseInterface
    {
        $psr7Request = $this->requestFactory->createRequest($request->getMethod(), $this->getApiHost().$request->getUri());

        $psr7Request = $psr7Request
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json');

        foreach ($request->getHeaders() ?? [] as $headerName => $headerValue) {
            $psr7Request = $psr7Request->withHeader($headerName, $headerValue);
        }

        if ($request instanceof StoreApiRequest) {
            $psr7Request = $psr7Request->withHeader('sw-access-key', $request->getAccessKey());
        } elseif ($this->accessTokenProvider) {
            $psr7Request = $psr7Request->withHeader('Authorization', $this->accessTokenProvider->provideAccessToken()->getAuthorizationHeaderValue());
        }

        $requestBody = $request->getBody();
        if (!empty($requestBody)) {
            $jsonString = encode($requestBody);
            $psr7Request = $psr7Request->withBody($this->streamFactory->createStream($jsonString));
        }

        $psr7Response = $this->httpClient->sendRequest($psr7Request);

        $statusCode = $psr7Response->getStatusCode();
        $contents = $psr7Response->getBody()->getContents();

        // 204 No Content (e.g. PATCH /api/order/{id}) has an empty body, not JSON
        $body = non_empty_string()->matches($contents) ? decode($contents) : [];

        return $this->responseHandler->handle($statusCode, \is_array($body) ? $body : []);
    }

    #[\Override]
    public function getApiHost(): string
    {
        return $this->apiHost;
    }
}
