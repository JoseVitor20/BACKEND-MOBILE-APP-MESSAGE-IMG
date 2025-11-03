<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// ROTA EXISTENTE: Lista imagens por categoria com paginação
// Exemplo de URL: /api/images/bom-dia?page=1
Route::get('images/{category}', [ImageController::class, 'indexByCategory']);