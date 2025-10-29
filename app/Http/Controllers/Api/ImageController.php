<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ImageController extends Controller
{
    const BATCH_SIZE = 20; 
    
    // Distribuição fixa de 20 imagens
    const NOTIFICATION_DISTRIBUTION = [
        'bom-dia' => 5,
        'boa-noite' => 10,
        'natal' => 5,
    ];

    const CATEGORIES = ['bom-dia', 'boa-noite', 'natal', 'ano-novo', 'aniversario']; 
    const CACHE_KEY_UPDATE_DATA = 'latest_update_notification_data';
    const CACHE_TTL_MINUTES = 60 * 6; // Cache de 6 horas para proteger o S3

    /**
     * Lista as imagens do Bucket por categoria (pasta) com paginação (EXISTENTE).
     * @param string $category O nome da pasta/categoria.
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexByCategory(Request $request, string $category)
    {
        // ... (Seu código original de paginação manual do S3 aqui, sem alteração) ...
        $perPage = 25;
        $page = $request->get('page', 1);

        $bucketPrefix = $category;
        
        $disk = Storage::disk('s3');
        
        $allFilePaths = $disk->allFiles($bucketPrefix); 

        $allImageUrls = collect($allFilePaths)
            ->filter(fn ($filePath) => preg_match('/\.(jpe?g|png|webp|gif)$/i', $filePath))
            ->map(fn ($filePath) => $disk->url($filePath))
            ->values() 
            ->all();

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

    /**
     * Lógica Crucial: Encontra o último número de 'update' no S3 e retorna as URLs correspondentes.
     * Salva o resultado no cache por 6 horas para proteger o S3.
     */
    private function getLatestUpdateBatchFromS3(): array
    {
        // 1. Tenta buscar o resultado final do cache
        $cachedData = Cache::get(self::CACHE_KEY_UPDATE_DATA);

        if ($cachedData !== null) {
            return $cachedData;
        }
        
        // 2. Busca o número MÁXIMO de update (operação cara, rodará a cada 6h)
        $disk = Storage::disk('s3');
        $maxUpdateNumber = 0;
        
        // Regex para encontrar o número de update: (update_N.)
        $updateRegex = '/update_(\d+)\.(jpe?g|png|webp|gif)$/i';
        
        foreach (self::CATEGORIES as $category) {
            $allFilePaths = $disk->allFiles($category); 
            
            // Busca o maior número de update dentro desta categoria
            $currentMax = collect($allFilePaths)
                ->map(function ($filePath) use ($updateRegex) {
                    if (preg_match($updateRegex, $filePath, $matches)) {
                        return (int)$matches[1];
                    }
                    return 0;
                })
                ->max() ?? 0;
            
            $maxUpdateNumber = max($maxUpdateNumber, $currentMax);
        }
        
        // 3. Se não houver 'update' em nenhum arquivo, retorna vazio
        if ($maxUpdateNumber === 0) {
            return [
                'update_number' => 0,
                'data' => [],
            ];
        }

        // 4. Monta o lote final com base no maxUpdateNumber
        $finalUpdateSuffix = "update_{$maxUpdateNumber}.";
        $finalRandomUrls = [];

        foreach (self::NOTIFICATION_DISTRIBUTION as $category => $count) {
            $categoryPrefix = $category . '/';
            
            // Pega APENAS os arquivos que contêm o sufixo exato (ex: '/bom-dia/imagem(X)update_2.png')
            // CUIDADO: allFiles pode ser lento, mas é inevitável sem uma DB. 
            // O cache de 6h protege o servidor.
            $filesInBatch = $disk->allFiles($category);

            $selectedUrls = collect($filesInBatch)
                ->filter(fn ($path) => str_contains($path, $finalUpdateSuffix))
                // Mapeia para URL e limita à contagem necessária (5, 10 ou 5)
                ->map(fn ($path) => $disk->url($path))
                ->shuffle() // Garante a aleatoriedade se houver mais de 5, 10 ou 5 arquivos por update
                ->take($count) 
                ->all();
            
            $finalRandomUrls = array_merge($finalRandomUrls, $selectedUrls);
        }

        // 5. Salva o resultado final no cache e o retorna
        shuffle($finalRandomUrls); // Embaralha o lote final

        $result = [
            'update_number' => $maxUpdateNumber,
            'data' => $finalRandomUrls,
        ];

        Cache::put(self::CACHE_KEY_UPDATE_DATA, $result, Carbon::now()->addMinutes(self::CACHE_TTL_MINUTES));
        
        return $result;
    }

    /**
     * Endpoint: Retorna 20 URLs do lote de atualização mais recente.
     * O Front-end usa o 'update_number' para gerenciar o estado e a notificação de 7 dias.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestNotificationBatch()
    {
        // 1. Obtém os dados do S3 (ou do cache, que é a regra geral)
        $latestBatchData = $this->getLatestUpdateBatchFromS3();
        
        $urls = $latestBatchData['data'];
        $updateNumber = $latestBatchData['update_number'];

        // 2. Se não houver URLs (apenas se maxUpdateNumber for 0)
        if (empty($urls)) {
             return response()->json([
                 'message' => 'Nenhuma atualização de notificação encontrada no bucket.',
                 'update_number' => 0,
             ], 404);
        }

        // 3. Retorna o lote
        return response()->json([
            'update_number' => $updateNumber, // O Front-end usa isso para rastrear o estado
            'timestamp' => now()->timestamp,
            'data' => $urls,
            'count' => count($urls),
        ]);
    }
}