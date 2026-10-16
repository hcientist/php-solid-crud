<?php
/**
 * Unit Test for SPARQL Update PATCH requests on the Server class
 *
 * The Solid Protocol requires N3 Patch for PATCH (Solid Protocol 0.11.0, §5.3.1
 * https://solidproject.org/TR/2024/protocol-20240512#n3-patch) and does not
 * mention SPARQL Update. This server accepts application/sparql-update as
 * well, and @inrupt/solid-client sends it, so it is held to the SPARQL 1.1
 * Update Recommendation (https://www.w3.org/TR/sparql11-update/):
 *
 * - §3 #updateLanguage: "Multiple operations are separated by a ';'
 *   (semicolon) character." and must have "the same effects as executing
 *   them sequentially in the order they appear in the request."
 * - §3.1.1 #insertData INSERT DATA, §3.1.2 #deleteData DELETE DATA.
 * - Keywords follow the SPARQL 1.1 Query grammar, §19.8 note 1
 *   (https://www.w3.org/TR/sparql11-query/#sparqlGrammar): "Keywords are
 *   matched in a case-insensitive manner with the exception of the keyword 'a'".
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
     * SPARQL 1.1 Update §3 #updateLanguage, §3.1.1 #insertData, §3.1.2 #deleteData.
     * Regression: one greedy match read the INSERT block as part of the DELETE,
     * so replacing a value removed both the old and the new triples.
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
     * SPARQL 1.1 Query §19.8 note 1: keywords are case-insensitive.
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

    /**
     * @testdox Server should run the operations of one request in the order given
     *
     * SPARQL 1.1 Update §3 #updateLanguage: sequential, in request order. Inserting
     * and then deleting the same triple leaves it absent; the reverse leaves it present.
     *
     * @covers ::respondToRequest
     */
    public function testOperationsRunInOrder()
    {
        $triple = '<https://example.com/wardrobe.ttl> <https://example.com/ns#wearer> <https://example.com/d.ttl#d> .';

        [, $insertThenDelete] = $this->patch("INSERT DATA { $triple }; DELETE DATA { $triple }");
        [, $deleteThenInsert] = $this->patch("DELETE DATA { <https://example.com/wardrobe.ttl> <http://purl.org/dc/terms/modified> \"1\" . }; INSERT DATA { $triple }");

        $this->assertStringNotContainsString('<https://example.com/d.ttl#d>', $insertThenDelete);
        $this->assertStringContainsString('<https://example.com/d.ttl#d>', $deleteThenInsert);
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
