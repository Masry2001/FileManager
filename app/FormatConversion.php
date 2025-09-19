<?php

namespace App;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;


//FormatConversion (Decision Maker)
class FormatConversion
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }



    public static function convertFileFormat(UploadedFile $file): ?string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $tempPath = $file->getRealPath(); // the physical path of the uploaded file on the server

        $newFileName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME); // file name with no extension

        // Create a temporary output path (without extension initially)
        $outputPath = sys_get_temp_dir() . '/' . uniqid($newFileName . '_');
        // Example: /tmp/document_64f5a2b1c3d4e_
        switch ($extension) {
            case 'pdf':
                $success = ConvertFiles::convertPDFToDOCX($outputPath, $tempPath);
                if ($success) {
                    // Return the path with the .docx extension
                    return $outputPath . '.docx';
                    // /tmp/document_64f5a2b1c3d4e_.docx
                }
                // If conversion failed, return null
                return null;

            case 'wav':
            case 'mp3':
                // TODO: Implement audio conversion
                // convertAudioToFLAC($outputPath, $tempPath);
                return $tempPath; // Return original for now
                break;

            case 'mp4':
                // TODO: Implement video conversion
                // convertVideoToFLV($outputPath, $tempPath);
                return $tempPath; // Return original for now
                break;

            case 'jpg':
            case 'jpeg':
                // TODO: Implement image conversion
                // convertImageToPng($outputPath, $tempPath);
                return $tempPath; // Return original for now
                break;

            default:
                // No conversion needed → return original file path
                return $tempPath;
        }
    }




    private function convertAudioToFLAC($outputPath, $tempPath)
    {

    }

    private function convertVideoToFLV($outputPath, $tempPath)
    {

    }

    private function convertImageToPng($outputPath, $tempPath)
    {

    }
}
