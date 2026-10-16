<?php
/**
 * Unit Test for SPARQL Update PATCH requests on the Server class
 */

namespace Pdsinterop\Solid\Resources;

use EasyRdf\Graph;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\FileAttributes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Pdsinterop\Solid\Resources\Server
 * @coversDefaultClass \Pdsinterop\Solid\Resources\Server
 *
 * @uses   \Laminas\Diactoros\Response
 * @uses   \Laminas\Diactoros\ServerRequest
 * @uses   \Pdsinterop\Solid\Resources\Server
 */
class ServerSparqlUpdateTest extends TestCase
{
    ////////////////////////////////// FIXTURES \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    const MOCK_PATH = '/wardrobe.ttl';
    const MOCK_URL = 'https://example.com' . self::MOCK_PATH;

    const STORED = <<<'TTL'
<https://example.com/wardrobe.ttl>
  <http://purl.org/dc/terms/modified> "1" ;
  <https://example.com/ns#wearer> <https://example.com/a.ttl#a> ;
  <https://example.com/ns#units> "metric" .
TTL;

    /////////////////////////////////// TESTS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    /**
     * @testdox Server should apply each block of a DELETE DATA; INSERT DATA patch to its own triples
     *
     * @covers ::respondToRequest
     */
    public function testDeleteThenInsertReplacesValue()
    {
        // The shape @inrupt/solid-client sends when a value changes and one is added.
        $patch = 'DELETE DATA {<https://example.com/wardrobe.ttl> <http://purl.org/dc/terms/modified> "1".}; '
            . "INSERT DATA {<https://example.com/wardrobe.ttl> <http://purl.org/dc/terms/modified> \"2\";\n"
            . "    <https://example.com/ns#wearer> <https://example.com/b.ttl#b>.};\n";

        [$status, $written] = $this->patch($patch);

        $this->assertSame(201, $status);
        $this->assertStringContainsString('"2"', $written);
        $this->assertStringNotContainsString('"1"', $written);
        $this->assertStringContainsString('<https://example.com/a.ttl#a>', $written);
        $this->assertStringContainsString('<https://example.com/b.ttl#b>', $written);
        $this->assertStringContainsString('"metric"', $written);
    }

    /**
     * @testdox Server should accept SPARQL Update keywords in any case
     *
     * @covers ::respondToRequest
     */
    public function testLowercaseInsert()
    {
        [$status, $written] = $this->patch('insert data { <https://example.com/wardrobe.ttl> <https://example.com/ns#wearer> <https://example.com/c.ttl#c> . }');

        $this->assertSame(201, $status);
        $this->assertStringContainsString('<https://example.com/c.ttl#c>', $written);
        $this->assertStringContainsString('<https://example.com/a.ttl#a>', $written);
    }

    ////////////////////////////// MOCKS AND STUBS \\\\\\\\\\\\\\\\\\\\\\\\\\\\\

    /** @return array{0: int, 1: string} status and the document written back */
    private function patch(string $body): array
    {
        $written = '';

        $mockFileSystem = $this->getMockBuilder(FilesystemOperator::class)->getMock();
        $mockFileSystem->method('fileExists')->willReturnCallback(fn ($path) => $path === self::MOCK_PATH);
        $mockFileSystem->method('read')->willReturn(self::STORED);
        $mockFileSystem->method('write')->willReturnCallback(function ($path, $contents) use (&$written) {
            $written = $contents;
        });

        $mockAdapter = $this->getMockBuilder(FilesystemAdapter::class)->getMock();
        $mockAttributes = $this->getMockBuilder(FileAttributes::class)->setConstructorArgs(['/'])->getMock();
        $mockAdapter->method('fileSize')->willReturn($mockAttributes);
        $mockAttributes->method('extraMetaData')->willReturn([]);

        $request = (new ServerRequest([], [], self::MOCK_URL, 'PATCH', 'php://temp', [
            'Content-Type' => 'application/sparql-update',
        ]))->withBody((new StreamFactory())->createStream($body));

        $server = new Server($mockFileSystem, $mockAdapter, new Response(), new Graph());
        $response = $server->respondToRequest($request);

        return [$response->getStatusCode(), $written];
    }
}
