param (
    [Parameter(Mandatory=$true)]
    [string]$InputPath,

    [Parameter(Mandatory=$true)]
    [string]$OutputPath
)

$ErrorActionPreference = "Stop"

try {
    if (-not (Test-Path $InputPath)) {
        Write-Error "Input file does not exist: $InputPath"
        exit 1
    }

    $absInput = (Resolve-Path $InputPath).Path
    $absOutput = [System.IO.Path]::GetFullPath($OutputPath)
    
    $outDir = [System.IO.Path]::GetDirectoryName($absOutput)
    if (-not (Test-Path $outDir)) {
        New-Item -ItemType Directory -Path $outDir -Force | Out-Null
    }

    if (Test-Path $absOutput) {
        Remove-Item -Path $absOutput -Force
    }

    # PowerPoint COM Automation
    $ppt = New-Object -ComObject PowerPoint.Application
    # Open presentation in read-only, untitled, invisible window
    # ppSaveAsPDF format code = 32
    $presentation = $ppt.Presentations.Open($absInput, 2, 0, 0) # ReadOnly=2 (msoTrue), Untitled=0, WithWindow=0 (msoFalse)
    $presentation.SaveAs($absOutput, 32)
    $presentation.Close()
    $ppt.Quit()

    # Clean up COM references
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($presentation) | Out-Null
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($ppt) | Out-Null
    [System.GC]::Collect()
    [System.GC]::WaitForPendingFinalizers()

    if (Test-Path $absOutput) {
        Write-Output "SUCCESS: $absOutput"
        exit 0
    } else {
        Write-Error "Conversion failed: PDF was not generated."
        exit 1
    }
} catch {
    Write-Error "Error during PPTX to PDF conversion: $($_.Exception.Message)"
    exit 1
}
