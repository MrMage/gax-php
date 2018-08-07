<?php
/*
 * Copyright 2018, Google Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Google\ApiCore\Tests\Unit\Transport;

use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\Call;
use Google\ApiCore\RequestBuilder;
use Google\ApiCore\Testing\MockRequest;
use Google\ApiCore\Testing\MockResponse;
use Google\ApiCore\Transport\GrpcFallbackTransport;
use Google\ApiCore\Transport\RestTransport;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Google\Rpc\Code;
use Google\Rpc\Status;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class GrpcFallbackTransportTest extends TestCase
{
    private $call;

    public function setUp()
    {
        $this->call = new Call(
            'Testing123',
            MockResponse::class,
            new MockRequest()
        );
    }
    private function getTransport(callable $httpHandler)
    {
        return new GrpcFallbackTransport(
            'www.example.com',
            $httpHandler
        );
    }

    public function testStartUnaryCall()
    {
        $body = (new MockResponse())
            ->setName('hello')
            ->setNumber(15);

        $expectedRequest = new Request(
            'POST',
            'https://www.example.com/$rpc/Testing123',
            [
                'Content-Type' => 'application/x-protobuf',
                'x-goog-api-client' => ['grpc-web'],
            ],
            (new MockRequest())->serializeToString()
        );

        $httpHandler = function (RequestInterface $request, array $options = []) use ($body, $expectedRequest) {
            $this->assertEquals($expectedRequest, $request);

            return Promise\promise_for(
                new Response(
                    200,
                    [],
                    $body->serializeToString()
                )
            );
        };

        $response = $this->getTransport($httpHandler)
            ->startUnaryCall($this->call, [])
            ->wait();

        $this->assertEquals($body->getName(), $response->getName());
        $this->assertEquals($body->getNumber(), $response->getNumber());
    }

    /**
     * @expectedException \Exception
     */
    public function testStartUnaryCallThrowsException()
    {
        $httpHandler = function (RequestInterface $request, array $options = []) {
            return Promise\rejection_for(new \Exception());
        };

        $this->getTransport($httpHandler)
            ->startUnaryCall($this->call, [])
            ->wait();
    }

    /**
     * @expectedException \Google\ApiCore\ApiException
     */
    public function testStartUnaryCallThrowsRequestException()
    {
        $httpHandler = function (RequestInterface $request, array $options = []) {
            $status = new Status();
            $status->setCode(Code::NOT_FOUND);
            $status->setMessage("Ruh-roh");
            return Promise\rejection_for(
                RequestException::create(
                    new Request('POST', 'http://www.example.com'),
                    new Response(
                        404,
                        [],
                        $status->serializeToString()
                    )
                )
            );
        };

        $this->getTransport($httpHandler)
            ->startUnaryCall($this->call, [])
            ->wait();
    }

    /**
     * @dataProvider buildDataGrpcFallback
     */
    public function testBuildGrpcFallback($serviceAddress, $config, $expectedTransport)
    {
        $actualTransport = GrpcFallbackTransport::build($serviceAddress, $config);
        $this->assertEquals($expectedTransport, $actualTransport);
    }

    public function buildDataGrpcFallback()
    {
        $uri = "address.com";
        $serviceAddress = "$uri:443";
        $httpHandler = [HttpHandlerFactory::build(), 'async'];
        return [
            [
                $serviceAddress,
                ['httpHandler' => $httpHandler],
                new GrpcFallbackTransport($uri, $httpHandler)
            ],
            [
                $serviceAddress,
                [],
                new GrpcFallbackTransport($uri, $httpHandler),
            ],
        ];
    }

    /**
     * @dataProvider buildInvalidData
     * @expectedException \Google\ApiCore\ValidationException
     * @param $serviceAddress
     * @param $args
     * @throws \Google\ApiCore\ValidationException
     */
    public function testBuildInvalid($serviceAddress, $args)
    {
        GrpcFallbackTransport::build($serviceAddress, $args);
    }

    public function buildInvalidData()
    {
        return [
            [
                "addresswithtoo:many:segments",
                [],
            ],
        ];
    }

    /**
     * @expectedException \Google\ApiCore\ApiException
     * @expectedExceptionMessage <html><body>This is an HTML response<\/body><\/html>
     * @expectedExceptionCode 5
     */
    public function testNonBinaryProtobufResponseException()
    {
        $httpHandler = function (RequestInterface $request, array $options = []) {
            return Promise\rejection_for(
                RequestException::create(
                    new Request('POST', 'http://www.example.com'),
                    new Response(
                        404,
                        [],
                        "<html><body>This is an HTML response</body></html>"
                    )
                )
            );
        };

        $this->getTransport($httpHandler)
            ->startUnaryCall($this->call, [])
            ->wait();
    }
}
