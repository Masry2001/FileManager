<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class XmlController extends Controller
{
    public function view()
    {
        // Fetch files from DB
        $files = DB::table('files')->get();

        // Pass to Blade view
        return response()
            ->view('xml.xml_template', [
                'files' => $files
            ])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function download()
    {
        // Fetch all assets from the 'files' table
        $files = DB::table('files')->get();

        // Render XML using your Blade template
        $xmlContent = view('xml.xml_template', compact('files'))->render();

        // Return as downloadable XML file
        return response($xmlContent)
            ->header('Content-Type', 'application/xml')
            ->header('Content-Disposition', 'attachment; filename="metadata.xml"');
    }

    public function viewSingle($id)
    {
        $file = DB::table('files')->find($id);

        if (!$file) {
            abort(404, 'File not found');
        }

        return response()
            ->view('xml.xml_single', compact('file'))
            ->header('Content-Type', 'application/xml');
    }

    public function downloadSingle($id)
    {
        $file = DB::table('files')->find($id);

        if (!$file) {
            abort(404, 'File not found');
        }

        $xmlContent = view('xml.xml_single', compact('file'))->render();

        return response($xmlContent)
            ->header('Content-Type', 'application/xml')
            ->header('Content-Disposition', 'attachment; filename="file_' . $id . '.xml"');
    }


}
