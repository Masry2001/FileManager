<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\FormatConversion;

//FileController (Orchestrator)
class FileController extends Controller
{
    // Show all files
    public function index()
    {
        $files = File::latest()->get();
        return view('files.index', compact('files'));
    }

    // Handle file upload
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // max 100MB
            'description' => 'nullable|string|max:255',
        ]);

        $uploadedFile = $request->file('file');



        // Convert file if needed
        $convertedPath = FormatConversion::convertFileFormat($uploadedFile);
        //Example: '/tmp/report_64f5a2b1c3d4e_.docx'
        // check here if the $conertedPaht is null
        if ($convertedPath === null) {
            return redirect()->back()->with('error', 'File conversion failed. Please try again.');
        }

        if ($convertedPath && $convertedPath !== $uploadedFile->getRealPath()) {
            // Handle converted file...

            // Wrap converted file
            $convertedFile = new \Illuminate\Http\File($convertedPath);

            // Store in Laravel storage (storage/app/public/files/)
            // Result: 'files/AbCdEf123456.docx'
            $storedPath = Storage::disk('public')->putFile('files', $convertedFile);

            // Get metadata from converted file
            $fileName = basename($storedPath);
            $extension = pathinfo($convertedPath, PATHINFO_EXTENSION);
            $mimeType = mime_content_type($convertedPath);
            $size = filesize($convertedPath);
            $originalName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $extension;

        } else {
            // Store original file (without conversion)
            $storedPath = $uploadedFile->store('files', 'public');

            // Get metadata from original
            $fileName = basename($storedPath);
            $extension = $uploadedFile->getClientOriginalExtension();
            $mimeType = $uploadedFile->getMimeType();
            $size = $uploadedFile->getSize();
            $originalName = $uploadedFile->getClientOriginalName();
        }

        // Save file metadata in DB
        File::create([
            'original_name' => $originalName,
            'stored_name' => $fileName,
            'path' => $storedPath,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'size' => $size,
            'description' => $request->description,
        ]);

        return redirect()->back()->with('success', 'File uploaded successfully!');
    }


    public function edit(File $file)
    {
        return view('files.edit', compact('file'));
    }


    // Update file metadata (like description or original_name)
    public function update(Request $request, File $file)
    {
        $request->validate([
            'original_name' => 'nullable|string', // allow renaming if needed
            'description' => 'nullable|string|max:30',
        ]);

        $file->update([
            'original_name' => $request->original_name ?? $file->original_name,
            'description' => $request->description ?? $file->description,
        ]);

        // Redirect to index instead of back
        return redirect()->route('files.index')
            ->with('success', 'File updated successfully!');
    }
    // Download a file
    public function download(File $file)
    {
        return Storage::disk('public')->download($file->path, $file->original_name);
    }

    // Delete a file
    public function destroy(File $file)
    {
        Storage::disk('public')->delete($file->path);
        $file->delete();

        return redirect()->back()->with('success', 'File deleted successfully!');
    }




}
