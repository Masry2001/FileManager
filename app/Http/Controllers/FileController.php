<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'file' => 'required|file|max:10240', // max 10MB
            'description' => 'nullable|string|max:30',
        ]);




        $uploadedFile = $request->file('file');



        // Store file in storage/app/public/files
        $storedPath = $uploadedFile->store('files', 'public');

        // Save file metadata in DB
        File::create([
            'original_name' => $uploadedFile->getClientOriginalName(),
            'stored_name' => basename($storedPath),
            'path' => $storedPath,
            'extension' => $uploadedFile->getExtension(),
            'mime_type' => $uploadedFile->getMimeType(),
            'size' => $uploadedFile->getSize(),
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
