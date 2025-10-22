<?php
require_once __DIR__ . '/../src/App/Scraping/PdfDocumentParser.php';

use App\Scraping\PdfDocumentParser;

$parser = new PdfDocumentParser();

$pdf = <<<PDF
%PDF-1.4
1 0 obj << /Type /Catalog /Pages 2 0 R >>
endobj
2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj
3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>
endobj
4 0 obj << /Length 98 >>
stream
BT
/F1 18 Tf
72 720 Td
(Quarterly Results Overview) Tj
0 -24 Td
(See https://example.com/report for details.) Tj
ET
endstream
endobj
5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj
6 0 obj << /Title (Sample Quarterly Report) >>
endobj
xref
0 7
0000000000 65535 f 
trailer << /Size 7 /Root 1 0 R /Info 6 0 R >>
startxref
0
%%EOF
PDF;

$normalise = static function (string $text): string {
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($collapsed)) {
        $collapsed = $text;
    }

    return trim($collapsed);
};

$document = $parser->parse($pdf, 'https://example.com/files/q1.pdf', $normalise);

if ($document['content_type'] !== 'application/pdf') {
    throw new RuntimeException('Expected parsed document to report application/pdf content type.');
}

if (stripos($document['title'], 'Quarterly') === false) {
    throw new RuntimeException('PDF parser should extract the embedded title metadata.');
}

if ($document['paragraphs'] === [] || stripos($document['text'], 'Quarterly Results Overview') === false) {
    throw new RuntimeException('PDF parser should expose text content extracted from the stream.');
}

if (!in_array('https://example.com/report', $document['links'], true)) {
    throw new RuntimeException('PDF parser should detect hyperlinks embedded within the document text.');
}
