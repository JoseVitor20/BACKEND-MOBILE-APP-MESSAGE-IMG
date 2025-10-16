<?php

use App\Http\Controllers\Api\GalleryController;
use Illuminate\Support\Facades\Route;

Route::get('/images', [GalleryController::class, 'getAllImages']);