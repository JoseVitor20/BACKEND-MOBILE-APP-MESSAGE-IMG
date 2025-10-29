<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ImageController extends Controller
{
    // Parâmetros para o cache e S3
    const CATEGORIES_TO_SCAN = ['bom-dia', 'boa-noite', 'natal', 'aniversario']; 

    // Lote 1: minha_foto_1_update_1.jpg
    // Lote 1: minha_foto_2_update_1.jpg
    
    // Lote 2 minha_foto_1_update_2.jpg
    // Lote 2 minha_foto_2_update_2.jpg

    const CACHE_KEY_UPDATE_DATA = 'latest_full_update_batch_data';
    const CACHE_TTL_MINUTES = 60 * 6; // Cache de 6 horas para proteger o S3
    
    /**
     * Endpoint de Paginação: Seu código original (mantido por compatibilidade).
     */
    public function indexByCategory(Request $request, string $category)
    {
        $perPage = 25;
        $page = $request->get('page', 1);
        $disk = Storage::disk('s3');
        $allFilePaths = $disk->allFiles($category); 

        $allImageUrls = collect($allFilePaths)
            ->filter(fn ($filePath) => preg_match('/\.(jpe?g|png|webp|gif)$/i', $filePath))
            ->map(fn ($filePath) => $disk->url($filePath))
            ->values() 
            ->all();

        $total = count($allImageUrls);
        $items = collect($allImageUrls);
        $paginatedItems = $items->forPage($page, $perPage)->values();

        $imagePaginator = new LengthAwarePaginator(
            $paginatedItems, $total, $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($imagePaginator->toArray());
    }

    /**
     * Helper: Encontra o número de 'update' mais alto no S3 e retorna
     * todas as URLs correspondentes. O resultado é cacheado.
     * * Esta operação é custosa e só deve ser executada quando o cache expirar.
     */
    private function getLatestUpdateBatchData(): array
    {
        $cachedData = Cache::get(self::CACHE_KEY_UPDATE_DATA);

        if ($cachedData !== null) {
            return $cachedData;
        }

        $disk = Storage::disk('s3');
        // A regex procura pelo sufixo "_update_N.<extensão>". Ex: imagem(3)_update_2.png
        $updateRegex = '/update_(\d+)\.(jpe?g|png|webp|gif)$/i';
        $maxUpdateNumber = 0;
        $allUrls = [];

        // 1. Achar o número MÁXIMO de update existente no Bucket
        foreach (self::CATEGORIES_TO_SCAN as $category) {
            $allFilePaths = $disk->allFiles($category); 
            
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

        if ($maxUpdateNumber > 0) {
            // 2. Coletar TODAS as URLs do lote mais recente
            $finalUpdateSuffix = "update_{$maxUpdateNumber}.";

            foreach (self::CATEGORIES_TO_SCAN as $category) {
                $filesInBatch = $disk->allFiles($category);

                $categoryUrls = collect($filesInBatch)
                    // Filtra apenas os arquivos que contêm o sufixo exato do lote mais novo
                    ->filter(fn ($path) => str_contains($path, $finalUpdateSuffix)) 
                    ->map(fn ($path) => $disk->url($path))
                    ->all();
                
                $allUrls = array_merge($allUrls, $categoryUrls);
            }
        }

        shuffle($allUrls); // Embaralha para o frontend

        $result = [
            'update_number' => $maxUpdateNumber,
            'data' => $allUrls,
            'count' => count($allUrls),
        ];

        // 3. Cachear o resultado antes de retornar (protege o S3)
        Cache::put(self::CACHE_KEY_UPDATE_DATA, $result, Carbon::now()->addMinutes(self::CACHE_TTL_MINUTES));
        
        return $result;
    }

    /**
     * [APP CLIENTE] Retorna TODO o lote de atualização mais recente.
     * O Front-end é responsável por gerenciar o parcelamento diário (10 imagens/dia)
     * e o rastreamento do 'update_number'.
     * * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestNotificationBatch()
    {
        $latestBatchData = $this->getLatestUpdateBatchData();
        $urls = $latestBatchData['data'];
        $updateNumber = $latestBatchData['update_number'];

        if ($updateNumber === 0) {
            return response()->json([
                'message' => 'Nenhum lote de atualização encontrado no bucket.',
                'update_number' => 0,
                'data' => [],
                'count' => 0,
            ], 404);
        }

        return response()->json([
            'batch_id' => "update_{$updateNumber}", 
            'update_number' => $updateNumber, // O Front-end usa isso para rastrear o estado
            'timestamp' => now()->timestamp,
            'data' => $urls, 
            'count' => count($urls),
        ]);
    }
}
