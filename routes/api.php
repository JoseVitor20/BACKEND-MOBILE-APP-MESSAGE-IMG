<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// Rota pública para listar todas as URLs de imagens
Route::get('/images', [ImageController::class, 'index']);
