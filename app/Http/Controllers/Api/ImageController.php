<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator;

class ImageController extends Controller
{
    /**
     * Lista as imagens do Bucket por categoria (pasta) com paginação.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $category O nome da pasta/categoria (ex: 'bom-dia', 'natal').
     * @return \Illuminate\Http\JsonResponse
     */
    public function indexByCategory(Request $request, string $category)
    {
        // 1. Configurações de Paginação
        $perPage = 50;
        $page = $request->get('page', 1);

        // O prefixo do Bucket será o nome da categoria
        $bucketPrefix = $category;
        
        // 2. Acesso ao Bucket
        $disk = Storage::disk('s3');
        
        // 3. Obtém TODAS as chaves (nomes) dos arquivos DENTRO DA CATEGORIA
        // Aqui usamos o nome da pasta como prefixo para o allFiles()
        $allFilePaths = $disk->allFiles($bucketPrefix); 

        // 4. Cria a lista de URLs (Otimizado)
        $allImageUrls = collect($allFilePaths)
            ->filter(function ($filePath) {
                // Filtra para garantir que apenas arquivos de imagem sejam listados (opcional)
                return preg_match('/\.(jpe?g|png|webp|gif)$/i', $filePath);
            })
            ->map(function ($filePath) use ($disk) {
                // Mapeia para a URL completa do CDN/Bucket
                return $disk->url($filePath);
            })
            ->values() // Re-indexa o array
            ->all();

        // 5. Paginação Manual
        $total = count($allImageUrls);
        
        // Cria a coleção para paginar
        $items = collect($allImageUrls);

        // Divide a coleção em "pedaços" de 50
        $paginatedItems = $items->forPage($page, $perPage)->values();

        // Cria o Paginator do Laravel
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

        // 6. Retorno
        return response()->json($imagePaginator->toArray());
    }
}