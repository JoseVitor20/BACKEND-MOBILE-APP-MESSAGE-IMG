<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// Rota pública para listar todas as URLs de imagens
Route::get('/images', [ImageController::class, 'index']);

// NOVA ROTA: Lista imagens por categoria
// Exemplo de URL: /api/images/bom-dia?page=1
Route::get('images/{category}', [ImageController::class, 'indexByCategory']);