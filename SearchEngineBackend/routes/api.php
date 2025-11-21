<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DocumentController;

Route::post('/upload', [DocumentController::class, 'upload']);
Route::get('/search', [DocumentController::class, 'search']);
Route::post('/fix-index', [DocumentController::class, 'fixIndex']);
Route::delete('/delete-by-keyword', [DocumentController::class, 'deleteByKeyword']);
Route::delete('/delete-old-documents-years', [DocumentController::class, 'deleteOldDocumentsByYears']);
Route::delete('/reset-index', [DocumentController::class, 'resetIndex']);