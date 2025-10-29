<?php

use App\Http\Controllers\Api\ImageController;
use Illuminate\Support\Facades\Route;

// ROTA EXISTENTE: Lista imagens por categoria com paginação
// Exemplo de URL: /api/images/bom-dia?page=1
Route::get('images/{category}', [ImageController::class, 'indexByCategory']);

// NOVA ROTA DE NOTIFICAÇÃO: Retorna o lote de atualização COMPLETO (o Front-end gerencia o parcelamento diário)
Route::get('notifications/latest-batch', [ImageController::class, 'getLatestNotificationBatch']);
