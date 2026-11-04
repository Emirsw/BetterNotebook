<?php
/**
 * PPTX / PPT Sunumlarını PDF'e Dönüştürme Yardımcısı
 */

function convertPptxToPdf($inputPath, $outputPath) {
    if (!file_exists($inputPath)) {
        return ['success' => false, 'error' => 'Kaynak PPTX dosyası bulunamadı.'];
    }

    $psScript = __DIR__ . '/../scripts/convert_pptx_to_pdf.ps1';
    if (!file_exists($psScript)) {
        return ['success' => false, 'error' => 'PowerShell dönüştürme betiği bulunamadı.'];
    }

    $inputEscaped = escapeshellarg(realpath($inputPath) ?: $inputPath);
    $outputEscaped = escapeshellarg($outputPath);
    $scriptEscaped = escapeshellarg(realpath($psScript) ?: $psScript);

    // PowerShell komutunu çalıştır
    $cmd = "powershell -NoProfile -ExecutionPolicy Bypass -File {$scriptEscaped} -InputPath {$inputEscaped} -OutputPath {$outputEscaped} 2>&1";
    $output = [];
    $returnCode = 0;
    
    exec($cmd, $output, $returnCode);
    $outputStr = implode("\n", $output);

    if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
        return [
            'success' => true,
            'pdf_path' => $outputPath,
            'output' => $outputStr
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Sunum PDF\'e dönüştürülürken hata oluştu: ' . ($outputStr ?: 'Bilinmeyen dönüştürme hatası.')
        ];
    }
}
