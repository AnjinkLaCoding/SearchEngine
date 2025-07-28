<?php

namespace App\Http\Controllers;

use App\Models\FileDocument;
use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpWord\IOFactory as WordIO;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpPresentation\IOFactory as PresentationIO;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Exception;
use Spatie\PdfToText\Pdf;

class DocumentController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate(['files.*' => 'required|file']);

        foreach ($request->file('files') as $file) {
            $filePath = $file->store('uploads', 'public');

            $metadata = [
                'title'     => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'content'   => $this->extractContent($file),
                'indexed_at' => now()->toDateString(),
            ];

            $existing = FileDocument::where('file_name', $file->getClientOriginalName())->first();

            if ($existing) {
                $existing->update($metadata);
                $existing->searchable();
                continue;
            }

            $doc = FileDocument::create($metadata);
            $doc->searchable();

            // Save metadata to JSON
            $jsonData = Storage::disk('local')->exists('metadata.json')
                ? json_decode(Storage::get('metadata.json'), true)
                : [];

            $jsonData[] = $metadata;
            Storage::put('metadata.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            Storage::disk('public')->delete($filePath);
        }

        return response()->json(['message' => 'Files uploaded and indexed successfully']);
    }

    //To delete if there is duplicate metadata
    public function fixIndex()
    {
        $documents = FileDocument::all();
        $seen = [];

        foreach ($documents as $doc) {
            $key = $doc->file_name;

            if (array_key_exists($key, $seen)) {
                // Duplicate detected — remove from index and DB
                $doc->unsearchable();
                $doc->delete();
            } else {
                $seen[$key] = true;
            }
        }

        return response()->json(['message' => 'Duplicate documents removed from index and database']);
    }

    public function deleteByKeyword(Request $request)
    {
        $keyword = $request->query('keyword');

        if (!$keyword) {
            return response()->json(['message' => 'Keyword is required'], 400);
        }

        $results = FileDocument::search($keyword)->get();

        $deletedCount = 0;

        foreach ($results as $doc) {
            if (stripos($doc->content, $keyword) !== false) {
                $doc->unsearchable();
                $doc->delete();
                $deletedCount++;
            }
        }

        return response()->json(['message' => "$deletedCount document(s) containing '$keyword' have been deleted"]);
    }


    public function deleteOldDocuments(Request $request)
    {
        $daysOld = intval($request->query('day')); // Default to 30 days
        $batchSize = intval($request->query('batch_size', 50));
    
        if (!is_numeric($daysOld) || $daysOld <= 0) {
            return response()->json([
                'message' => 'days_old must be a positive number'
            ], 400);
        }
    
        $cutoffDate = date('Y-m-d', strtotime("-$daysOld days"));
    
        try {
            $totalDeleted = 0;
            $batchCount = 0;
        
            do {
                $documentsToDelete = FileDocument::whereDate('indexed_at', '<', $cutoffDate)
                    ->limit($batchSize)
                    ->get();
            
                if ($documentsToDelete->isEmpty()) {
                    break;
                }
            
                foreach ($documentsToDelete as $doc) {
                    try {
                        $doc->unsearchable();
                        $doc->delete();
                        $totalDeleted++;
                    } catch (Exception $e) {
                        \Log::error("Failed to delete document ID {$doc->id}: " . $e->getMessage());
                    }
                }
            
                $batchCount++;
                usleep(100000); // 100ms delay
            
            } while ($documentsToDelete->count() === $batchSize);
        
            return response()->json([
                'message' => "$totalDeleted documents older than $daysOld days have been deleted",
            ]);
        
        } catch (Exception $e) {
            \Log::error("Error during old document cleanup: " . $e->getMessage());
        
            return response()->json([
                'message' => 'An error occurred during cleanup',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function convertDocToDocx($inputPath)
    {
        $outputDir = dirname($inputPath);
        $command = "libreoffice --headless --convert-to docx --outdir " . escapeshellarg($outputDir) . " " . escapeshellarg($inputPath);
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            throw new \RuntimeException("Failed to convert DOC to DOCX: " . implode("\n", $output));
        }

        // Return new file path
        return $outputDir . '/' . pathinfo($inputPath, PATHINFO_FILENAME) . '.docx';
    }

    private function ConvertToUTF8($text)
    {
        $encoding = mb_detect_encoding($text, mb_detect_order(), false);
        #$text = mb_convert_encoding($text, 'UTF-8');    
        $out = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        return $out;
    }

    function extractPdfText($pdfPath) 
    {
        // Validate file exists and is readable
        if (!file_exists($pdfPath) || !is_readable($pdfPath)) {
            return '';
        }
    
        // Check if it's actually a PDF
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $pdfPath);
        finfo_close($finfo);
    
        if (strpos($mimeType, 'pdf') === false) {
            return '';
        }

    
        try {
            // Try the setPdf() method (fluent interface)
            $pdf = new Pdf();
            $text = $pdf->setPdf($pdfPath)->text();
        
        } catch (\Exception $e) {
            try {
                // Fallback: Try constructor method
                $pdf = new Pdf($pdfPath);
                $text = $pdf->text();
            
            } catch (\Exception $e2) {
                // Last resort: Direct shell command
                $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_extract_');
                $command = 'pdftotext ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($tempOutput) . ' 2>&1';
                shell_exec($command);
            
                if (file_exists($tempOutput) && filesize($tempOutput) > 0) {
                    $text = file_get_contents($tempOutput);
                    unlink($tempOutput);
                } else {
                    error_log("PDF extraction failed: " . $e2->getMessage());
                    return '';
                }
            }
        }
    
        $pages = explode("\f", $text);
        $result = '';
    
        foreach ($pages as $index => $page) {
            if ($index > 0) {
                $result .= "\n\n--- PAGE " . ($index + 1) . " ---\n\n";
            }
            $result .= trim($page);
        }
    
        return $result;
    }

    //Code for extracting content of a file
    private function extractContent($file)
    {
        $mime = $file->getMimeType();

        if (str_contains($mime, 'pdf')) {
            $text = '';
            $text = $this->extractPdfText($file);
            $text = preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $text);
            return $text;
        } elseif (str_contains($mime, 'word') || str_contains($file->getClientOriginalExtension(), 'doc')) {
            $filePath = $file->getPathname();

            if (strtolower($file->getClientOriginalExtension()) === 'doc') {
                // Convert DOC to DOCX
                $filePath = $this->convertDocToDocx($filePath);
            }

            $phpWord = WordIO::load($filePath);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    // Regular paragraph text
                    if (method_exists($element, 'getText')) {
                        $textPart = $element->getText();
                        if (is_array($textPart)) {
                            $textPart = implode("\n", $textPart);
                        }
                        $text .= $textPart . "\n";

                    // If it's a Table
                    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                        foreach ($element->getRows() as $row) {
                            foreach ($row->getCells() as $cell) {
                                foreach ($cell->getElements() as $cellElement) {
                                    if (method_exists($cellElement, 'getText')) {
                                        $cellText = $cellElement->getText();
                                        if (is_array($cellText)) {
                                            $cellText = implode("\n", $cellText);
                                        }
                                        $text .= $cellText . "\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            $text = preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $text);
            return $text;
        }elseif(str_contains($mime, 'presentation') || str_contains($file->getClientOriginalExtension(), 'pptx')){
            $ppt = PresentationIO::load($file->getPathname());
            $text = '';
            foreach ($ppt->getAllSlides() as $slide) {
                foreach ($slide->getShapeCollection() as $shape) {
                    if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                        foreach ($shape->getParagraphs() as $paragraph) {
                            foreach ($paragraph->getRichTextElements() as $element) {
                                if (method_exists($element, 'getText')) {
                                    $text .= $element->getText() . " ";
                                }
                            }
                        }
                        $text .= "\n";
                    } elseif (method_exists($shape, 'getDescription')) {
                        $text .= $shape->getDescription() . "\n";
                    }
                }
            }
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            $text = preg_replace('/[^\x{0000}-\x{FFFF}]/u', '', $text);
            return $text;
        }elseif(str_contains($mime, 'spreadsheet') ||in_array(strtolower($file->getClientOriginalExtension()), ['xlsx', 'xls'])){
            try {
                    ini_set('memory_limit', '512M');
        
                    $spreadsheet = IOFactory::load($file->getPathname());
                    $text = '';

                    foreach ($spreadsheet->getAllSheets() as $sheet) {
                        // Convert entire sheet to array
                        $sheetData = $sheet->toArray(null, true, true, true);
            
                        foreach ($sheetData as $row) {
                            $rowText = '';
                            $hasData = false;
                
                            foreach ($row as $cellValue) {
                                if ($cellValue !== null && $cellValue !== '') {
                                    $hasData = true;
                                    $rowText .= trim((string)$cellValue) . ' ';
                                }
                            }
                
                            if ($hasData) {
                                $text .= $rowText . "\n";
                            }
                        }
                    }

                    return trim($text);

            } catch (Exception $e) {
                \Log::error('Excel processing error: ' . $e->getMessage());
                return "Error processing Excel file";
            }
        }
    }
    //To search for the keyword
    public function search(Request $request)
    {
        $results = FileDocument::search($request->query('keyword'))->get();

        $cleanedResults = $results->map(function ($doc) {
            return [
                'id'        => $doc->id,
                'title'     => mb_convert_encoding($doc->title, 'UTF-8', 'UTF-8'),
                'file_name' => mb_convert_encoding($doc->file_name, 'UTF-8', 'UTF-8'),
                'file_size' => $doc->file_size,
                'mime_type' => mb_convert_encoding($doc->mime_type, 'UTF-8', 'UTF-8'),
                'content'   => mb_convert_encoding(substr($doc->content, 0, 500), 'UTF-8', 'UTF-8') . '...',
            ];
        });

        return response()->json($cleanedResults, 200, [], JSON_UNESCAPED_UNICODE);
    }
}