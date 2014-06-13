<?php
namespace Aws\Test\Common\Api;

use Aws\Common\Api\Service;
use Aws\AwsClient;
use Aws\Common\Credentials\NullCredentials;
use Aws\Common\Signature\SignatureV2;
use GuzzleHttp\Client;

/**
 * @covers \Aws\Common\Api\Service
 */
class ServiceTest extends \PHPUnit_Framework_TestCase
{
    public function testSetsDefaultValues()
    {
        $s = new Service([]);
        $this->assertSame([], $s['operations']);
        $this->assertSame([], $s['shapes']);
    }

    public function testImplementsArrayAccess()
    {
        $s = new Service(['metadata' => ['foo' => 'bar']]);
        $this->assertSame(['foo' => 'bar'], $s['metadata']);
        $this->assertNull($s['missing']);
        $s['abc'] = '123';
        $this->assertEquals('123', $s['abc']);
        $this->assertSame([], $s['shapes']);
    }

    public function testReturnsApiData()
    {
        $s = new Service(['metadata' => [
            'serviceFullName' => 'foo',
            'endpointPrefix' => 'bar',
            'apiVersion' => 'baz'
        ]]);
        $this->assertEquals('foo', $s->getServiceFullName());
        $this->assertEquals('bar', $s->getEndpointPrefix());
        $this->assertEquals('baz', $s->getApiVersion());
    }

    public function testReturnsMetadata()
    {
        $s = new Service([]);
        $this->assertSame([], $s->getMetadata());
        $s['metadata'] = [
            'serviceFullName' => 'foo',
            'endpointPrefix' => 'bar',
            'apiVersion' => 'baz'
        ];
        $this->assertEquals('foo', $s->getMetadata('serviceFullName'));
        $this->assertNull($s->getMetadata('baz'));
    }

    public function testReturnsIfOperationExists()
    {
        $s = new Service(['operations' => ['foo' => ['input' => []]]]);
        $this->assertTrue($s->hasOperation('foo'));
        $this->assertInstanceOf('Aws\Common\Api\Operation', $s->getOperation('foo'));
        $this->assertArrayHasKey('foo', $s->getOperations());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOperationExists()
    {
        (new Service([]))->getOperation('foo');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unable to determine
     */
    public function testEnsuresVersionIsAvailableWhenCreatingSignatures()
    {
        (new Service([]))->createSignature('foo');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unknown signature version
     */
    public function testEnsuresVersionExistsWhenCreatingSignatures()
    {
        (new Service([]))->createSignature('foo', 'baz');
    }

    public function signatureVersionProvider()
    {
        return [
            ['v2', 'Aws\Common\Signature\SignatureV2'],
            ['s3', 'Aws\Common\Signature\S3Signature'],
            ['v4', 'Aws\Common\Signature\SignatureV4'],
            ['v4', 'Aws\Common\Signature\S3SignatureV4', 's3'],
            ['v3https', 'Aws\Common\Signature\SignatureV3Https']
        ];
    }

    /**
     * @dataProvider signatureVersionProvider
     */
    public function testCreatesSignatures($version, $klass, $ep = null)
    {
        $s = new Service(['metadata' => ['endpointPrefix' => $ep]]);
        $result = $s->createSignature('foo', $version);
        $this->assertInstanceOf($klass, $result);
        if (method_exists($result, 'getRegion')) {
            $this->assertEquals('foo', $result->getRegion());
        }
    }

    public function testFallsBackToMainSignature()
    {
        $s = new Service(['metadata' => ['signatureVersion' => 'v2']]);
        $result = $s->createSignature('foo');
        $this->assertInstanceOf('Aws\Common\Signature\SignatureV2', $result);
    }

    public function protocolDataProvider()
    {
        return [
            ['json', 'Aws\Common\Api\Serializer\JsonRpcSerializer', 'Aws\Common\Api\Parser\JsonRpcParser'],
            ['rest-json', 'Aws\Common\Api\Serializer\RestJsonSerializer'],
            ['rest-xml', 'Aws\Common\Api\Serializer\RestXmlSerializer'],
            ['query', 'Aws\Common\Api\Serializer\QuerySerializer'],
        ];
    }

    /**
     * @dataProvider protocolDataProvider
     */
    public function testAttachesProtocols($type, $serializer, $parser = null)
    {
        $service = new Service(['metadata' => ['protocol' => $type]]);
        $client = new AwsClient([
            'api' => $service,
            'credentials' => new NullCredentials(),
            'client' => new Client(),
            'signature' => new SignatureV2()
        ]);

        $service->applyProtocol($client, 'http://foo.com');
        $s = $client->getEmitter()->listeners('prepare')[0];
        $this->assertInstanceOf($serializer, $s[0]);
        if ($parser) {
            $p = $client->getEmitter()->listeners('process')[0];
            $this->assertInstanceOf($parser, $p[0]);
        }
    }

    /**
     * @expectedException \UnexpectedValueException
     */
    public function testEnsuresProtocolExists()
    {
        $service = new Service(['metadata' => ['protocol' => 'foo']]);
        $client = new AwsClient([
            'api' => $service,
            'credentials' => new NullCredentials(),
            'client' => new Client(),
            'signature' => new SignatureV2()
        ]);
        $service->applyProtocol($client, 'http://foo.com');
    }
}