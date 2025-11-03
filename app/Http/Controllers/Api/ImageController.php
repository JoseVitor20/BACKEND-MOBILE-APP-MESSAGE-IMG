<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon; // Permanecida apenas se você usá-la em outro lugar, mas não é estritamente necessária aqui.

class ImageController extends Controller
{
    // Categoria padrão usada para listagem paginada
    const CATEGORIES = ['bom-dia', 'boa-noite', 'natal', 'ano-novo', 'aniversario'];
    
    // Constante com as extensões permitidas (Imagens, GIFs e Vídeos)
    const ALLOWED_MEDIA_REGEX = '/\.(jpe?g|png|webp|gif|mp4|mov|webm)$/i'; 

    // Cache de 1 hora para a ordem randomizada de paginação por categoria
    const PAGINATION_CACHE_TTL_MINUTES = 60; 

    /**
     * Lista as imagens do Bucket por categoria (pasta) com paginação.
     * Implementa randomização da lista completa, mantendo a ordem estável por 60 minutos
     * através do cache estático por categoria.
     * @param string $category O nome da pasta/categoria.
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexByCategory(Request $request, string $category)
    {
        $perPage = 25;
        $page = $request->get('page', 1);

        $bucketPrefix = $category;
        $disk = Storage::disk('s3');

        // 1. CHAVE DE CACHE ESTÁTICA POR CATEGORIA
        // Esta chave garante que a ordem randomizada seja a mesma para todos os clientes por 60 minutos.
        $cacheKey = "images_random_{$category}_static"; 

        // 2. Tenta buscar a lista randomizada do cache
        // FORÇA O DRIVER 'file' para garantir a compatibilidade no Laravel Cloud.
        $allImageUrls = Cache::store('file')->get($cacheKey); 

        if ($allImageUrls === null) {
            // Se o cache expirou, carrega e randomiza
            
            // allFiles() é lento, mas necessário para a paginação sem DB.
            $allFilePaths = $disk->allFiles($bucketPrefix); 

            $allImageUrls = collect($allFilePaths)
                // Filtro para incluir todas as mídias permitidas
                ->filter(fn ($filePath) => preg_match(self::ALLOWED_MEDIA_REGEX, $filePath))
                ->map(fn ($filePath) => $disk->url($filePath))
                ->values() 
                ->all();

            // *** RANDOMIZAÇÃO APLICADA AQUI ***
            shuffle($allImageUrls);

            // Armazena a lista randomizada no cache por 1 hora
            Cache::store('file')->put($cacheKey, $allImageUrls, now()->addMinutes(self::PAGINATION_CACHE_TTL_MINUTES));
        }
        
        // 3. Aplica a paginação na lista CACHEADA e randomizada
        $total = count($allImageUrls);
        
        $items = collect($allImageUrls);

        $paginatedItems = $items->forPage($page, $perPage)->values();

        $imagePaginator = new LengthAwarePaginator(
            $paginatedItems,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return response()->json($imagePaginator->toArray());
    }

    // Os métodos getLatestUpdateBatchFromS3() e getLatestNotificationBatch() foram removidos.
}