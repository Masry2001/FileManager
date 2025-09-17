<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\FileController;

Route::get('/', function () {
    return view('welcome');
});


Route::prefix('files')->controller(FileController::class)->group(function () {
    Route::get('/', 'index')->name('files.index');          // List files
    Route::post('/upload', 'store')->name('files.store');   // Upload file
    Route::get('/download/{file}', 'download')->name('files.download'); // Download file
    Route::put('/{file}', 'update')->name('files.update');  // Update file metadata
    Route::delete('/{file}', 'destroy')->name('files.destroy'); // Delete file
    Route::get('/{file}/edit', 'edit')->name('files.edit');

});
