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
    // Categoria padrão usada para listagem paginada (a rota de notificação usa todas as categorias)
    const CATEGORIES = ['bom-dia', 'boa-noite', 'natal', 'ano-novo', 'aniversario'];
    
    // Chave de cache para o lote de atualização mais recente (VITAL para a performance)
    const CACHE_KEY_UPDATE_DATA = 'latest_update_notification_data';
    
    // Cache de 6 horas para proteger o S3 de varreduras excessivas.
    const CACHE_TTL_MINUTES = 60 * 6;

    // Constante com as extensões permitidas (Imagens, GIFs e Vídeos)
    const ALLOWED_MEDIA_REGEX = '/\.(jpe?g|png|webp|gif|mp4|mov|webm)$/i'; 

    // NOVO: Cache de 1 hora para a ordem randomizada de paginação por sessão
    const PAGINATION_CACHE_TTL_MINUTES = 60; 

    /**
     * Lista as imagens do Bucket por categoria (pasta) com paginação (EXISTENTE).
     * Agora com randomização por sessão.
     * @param string $category O nome da pasta/categoria.
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexByCategory(Request $request, string $category)
    {
        $perPage = 25;
        $page = $request->get('page', 1);

        $bucketPrefix = $category;
        $disk = Storage::disk('s3');

        // 1. CHAVE DE CACHE BASEADA NA SESSÃO
        // Isso garante que cada sessão/usuário tenha uma ordem randomizada única e consistente.
        $sessionId = $request->session()->getId();
        $cacheKey = "images_random_{$category}_session_{$sessionId}";

        // 2. Tenta buscar a lista randomizada do cache
        $allImageUrls = Cache::get($cacheKey);

        if ($allImageUrls === null) {
            // Se não estiver no cache, carrega e randomiza

            // allFiles() é lento, mas necessário para a paginação sem DB.
            $allFilePaths = $disk->allFiles($bucketPrefix); 

            $allImageUrls = collect($allFilePaths)
                // Filtra para incluir todas as imagens E os vídeos
                ->filter(fn ($filePath) => preg_match(self::ALLOWED_MEDIA_REGEX, $filePath))
                ->map(fn ($filePath) => $disk->url($filePath))
                ->values() 
                ->all();

            // *** RANDOMIZAÇÃO APLICADA AQUI ***
            shuffle($allImageUrls);

            // Armazena a lista randomizada no cache
            Cache::put($cacheKey, $allImageUrls, now()->addMinutes(self::PAGINATION_CACHE_TTL_MINUTES));
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

    /**
     * Helper: Encontra o último número de 'update' no S3, filtra os arquivos correspondentes
     * e retorna as URLs. O resultado é CACHEADO usando o driver 'file'.
     * * Esta operação é custosa e só deve ser executada quando o cache expirar.
     */
    private function getLatestUpdateBatchFromS3(): array
    {
        // 1. Tenta buscar o resultado final do cache, FORÇANDO o driver 'file'
        $cachedData = Cache::store('file')->get(self::CACHE_KEY_UPDATE_DATA);

        if ($cachedData !== null) {
            return $cachedData;
        }
        
        // Se o cache expirou, executa a lógica de varredura (operação custosa)
        $disk = Storage::disk('s3');
        $maxUpdateNumber = 0;
        $updateRegex = '/update_(\d+)\.(jpe?g|png|webp|gif)$/i'; 
        
        // 2. Busca o número MÁXIMO de update em todas as categorias
        foreach (self::CATEGORIES as $category) {
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
        
        // 3. Se não houver 'update' em nenhum arquivo, retorna vazio
        if ($maxUpdateNumber === 0) {
            return [
                'update_number' => 0,
                'data' => [],
            ];
        }

        // 4. Monta o lote final com base no maxUpdateNumber
        $finalUpdateSuffix = "update_{$maxUpdateNumber}.";
        $finalUrls = [];

        foreach (self::CATEGORIES as $category) {
            
            $filesInBatch = $disk->allFiles($category);

            $selectedUrls = collect($filesInBatch)
                // Filtra APENAS os arquivos que contêm o sufixo exato (ex: '..._update_2.png')
                ->filter(fn ($path) => str_contains($path, $finalUpdateSuffix))
                ->map(fn ($path) => $disk->url($path))
                ->all();
            
            $finalUrls = array_merge($finalUrls, $selectedUrls);
        }

        // 5. Salva o resultado final no cache e o retorna, FORÇANDO o driver 'file'
        shuffle($finalUrls); // Embaralha o lote final

        $result = [
            'update_number' => $maxUpdateNumber,
            'data' => $finalUrls,
        ];

        Cache::store('file')->put(self::CACHE_KEY_UPDATE_DATA, $result, Carbon::now()->addMinutes(self::CACHE_TTL_MINUTES));
        
        return $result;
    }

    /**
     * Endpoint: Retorna todas as URLs do lote de atualização mais recente.
     * O Front-end usa o 'update_number' para gerenciar o estado e o parcelamento.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestNotificationBatch()
    {
        // 1. Obtém os dados do S3 (ou do cache, que agora usa explicitamente o driver 'file')
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