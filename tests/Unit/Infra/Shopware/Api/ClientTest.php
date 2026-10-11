<?php

declare(strict_types=1);

namespace Veliu\OrderPrinter\Tests\Unit\Infra\Shopware\Api;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface as Psr7RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Veliu\OrderPrinter\Domain\Api\ApiResponseInterface;
use Veliu\OrderPrinter\Domain\Api\RequestInterface;
use Veliu\OrderPrinter\Domain\Api\ResponseHandlerInterface;
use Veliu\OrderPrinter\Infra\Shopware\Api\AccessTokenProviderInterface;
use Veliu\OrderPrinter\Infra\Shopware\Api\Client;

/**
 * @covers \Veliu\OrderPrinter\Infra\Shopware\Api\Client
 */
class ClientTest extends TestCase
{
    private const string API_HOST = 'https://api.example.com';

    private Client $client;
    private MockObject&PsrClientInterface $httpClient;
    private MockObject&RequestFactoryInterface $requestFactory;
    private MockObject&ResponseHandlerInterface $responseHandler;
    private MockObject&Psr7RequestInterface $psr7Request;
    private MockObject&ResponseInterface $psr7Response;
    private MockObject&StreamInterface $responseStream;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(PsrClientInterface::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->responseHandler = $this->createMock(ResponseHandlerInterface::class);
        $accessTokenProvider = $this->createMock(AccessTokenProviderInterface::class);

        $this->psr7Request = $this->createMock(Psr7RequestInterface::class);
        $this->psr7Response = $this->createMock(ResponseInterface::class);
        $this->responseStream = $this->createMock(StreamInterface::class);

        $this->client = new Client(
            self::API_HOST,
            $this->httpClient,
            $this->requestFactory,
            $streamFactory,
            $this->responseHandler,
            $accessTokenProvider
        );
    }

    public function testRequestWithBasicRequest(): void
    {
        // Arrange
        $request = $this->createMock(RequestInterface::class);
        $apiResponse = $this->createMock(ApiResponseInterface::class);
        $method = 'GET';
        $uri = '/api/products';
        $responseContent = '{"data": "test"}';

        $request->expects($this->once())
            ->method('getMethod')
            ->willReturn($method);

        $request->expects($this->once())
            ->method('getUri')
            ->willReturn($uri);

        $request->expects($this->once())
            ->method('getHeaders')
            ->willReturn([]);

        $request->expects($this->once())
            ->method('getBody')
            ->willReturn(null);

        $this->requestFactory->expects($this->once())
            ->method('createRequest')
            ->with($method, self::API_HOST.$uri)
            ->willReturn($this->psr7Request);

        $this->psr7Request->expects($this->exactly(3))
            ->method('withHeader')
            ->willReturnSelf();

        $this->psr7Response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);

        $this->psr7Response->expects($this->once())
            ->method('getBody')
            ->willReturn($this->responseStream);

        $this->responseStream->expects($this->once())
            ->method('getContents')
            ->willReturn($responseContent);

        $this->httpClient->expects($this->once())
            ->method('sendRequest')
            ->with($this->psr7Request)
            ->willReturn($this->psr7Response);

        $this->responseHandler->expects($this->once())
            ->method('handle')
            ->with(200, ['data' => 'test'])
            ->willReturn($apiResponse);

        // Act
        $result = $this->client->request($request);

        // Assert
        $this->assertSame($apiResponse, $result);
    }

    public function testEmptyBodyOf204IsAnEmptyArray(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getMethod')->willReturn('PATCH');
        $request->method('getUri')->willReturn('/api/order/a1');
        $request->method('getHeaders')->willReturn([]);
        $request->method('getBody')->willReturn(null);
        $this->requestFactory->method('createRequest')->willReturn($this->psr7Request);
        $this->psr7Request->method('withHeader')->willReturnSelf();
        $this->psr7Response->method('getStatusCode')->willReturn(204);
        $this->psr7Response->method('getBody')->willReturn($this->responseStream);
        $this->responseStream->method('getContents')->willReturn('');
        $this->httpClient->method('sendRequest')->willReturn($this->psr7Response);

        $this->responseHandler->expects($this->once())->method('handle')->with(204, [])
            ->willReturn($this->createMock(ApiResponseInterface::class));

        $this->client->request($request);
    }

    public function testGetApiHost(): void
    {
        $this->assertSame(self::API_HOST, $this->client->getApiHost());
    }
}
