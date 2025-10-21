<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Routing\Controller;
use Illuminate\Pagination\LengthAwarePaginator; // Importe este!

class ImageController extends Controller
{
    /**
     * Lista as imagens do Bucket com paginação manual.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // 1. Configurações de Paginação
        $perPage = 50;
        $page = $request->get('page', 1); // Pega o número da página (padrão 1)
        
        // 2. Acesso ao Bucket
        $disk = Storage::disk('s3');
        
        // 3. Obtém TODAS as chaves (nomes) dos arquivos do bucket
        // IMPORTANTE: Se o número de arquivos crescer muito (ex: 100k+), 
        // esta linha pode começar a causar timeout ou esgotar a memória
        $allFilePaths = $disk->allFiles('');

        // 4. Cria a lista de URLs
        $allImageUrls = [];
        foreach ($allFilePaths as $filePath) {
            // Verifica se é uma imagem (opcional, mas recomendado)
            if (preg_match('/\.(jpe?g|png|webp)$/i', $filePath)) {
                $allImageUrls[] = $disk->url($filePath);
            }
        }

        // 5. Paginação Manual
        $total = count($allImageUrls);
        
        // Cria a coleção para paginar
        $items = collect($allImageUrls);

        // Divide a coleção em "pedaços" de 50
        $paginatedItems = $items->forPage($page, $perPage)->values();

        // Cria o Paginator do Laravel
        $imagePaginator = new LengthAwarePaginator(
            $paginatedItems, // Apenas os 50 itens da página atual
            $total,          // Total de itens
            $perPage,        // Itens por página
            $page,           // Página atual
            [
                'path' => $request->url(), // Caminho base para a paginação
                'query' => $request->query(),
            ]
        );

        // 6. Retorno (Já no formato padrão de paginação do Laravel)
        return response()->json($imagePaginator->toArray());
    }
}